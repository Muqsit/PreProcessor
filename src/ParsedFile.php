<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

use Closure;
use Error;
use Exception;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\MutatingScope;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClassConstantsNode;
use PHPStan\Node\MethodReturnStatementsNode;
use PHPStan\Type\ObjectType;
use SplFileInfo;
use function array_push;
use function implode;
use function str_contains;
use function var_dump;

final class ParsedFile{

	public static function nodeHash(Node $node) : ?string{
		$node_type = match($node::class){ // TODO: remove this once https://github.com/phpstan/phpstan-src/pull/1568 is merged
			ClassConstantsNode::class => "PHPStan_Node_ClassConstantsNode",
			MethodReturnStatementsNode::class => "PHPStan_Node_MethodReturnStatementsNode",
			default => $node->getType()
		};

		$tokens = [$node->getLine()];

		$add_non_expr_tokens = true;
		if($node instanceof Expr){
			try{
				static $printer = null;
				$printer ??= new Standard();
				$tokens[] = $printer->prettyPrintExpr($node);
				$add_non_expr_tokens = false;
			}catch(Error | Exception){
			}
		}
		if($add_non_expr_tokens){
			array_push($tokens, $node_type, $node->getStartLine(), $node->getEndLine(), $node->getStartTokenPos());
		}
		if(str_contains(implode(":", $tokens), '$this->getPlayer()->getUniqueId()->toString()')){
			var_dump(implode(":", $tokens));
			echo (new \Exception)->getTraceAsString(), PHP_EOL;
		}
		return implode(":", $tokens);
	}

	/** @var SplFileInfo */
	private $file;

	/** @var Scope[] */
	private $scopes;

	/** @var Node[] */
	private $nodes_original;

	/** @var Node[] */
	private $nodes_modified;

	/** @var mixed[] */
	private $tokens_original;

	/**
	 * @param SplFileInfo $file
	 * @param array $scopes
	 * @param array $nodes_original
	 * @param array $tokens_original
	 */
	public function __construct(SplFileInfo $file, array $scopes, array $nodes_original, array $tokens_original){
		$this->file = $file;
		$this->scopes = $scopes;
		$this->nodes_original = $nodes_original;
		$this->tokens_original = $tokens_original;

		$traverser = new NodeTraverser();
		$traverser->addVisitor(new CloningVisitor());
		$traverser->addVisitor(new NameResolver(null, [
			'preserveOriginalNames' => false,
			'replaceNodes' => true
		]));
		$this->nodes_modified = $traverser->traverse($this->nodes_original);
	}

	public function getFile() : SplFileInfo{
		return $this->file;
	}

	/**
	 * @param Closure[] $visitors
	 *
	 * @phpstan-param Closure(Node) : null|int|Node ...$visitors
	 */
	public function visit(Closure ...$visitors) : void{
		$traverser = new NodeTraverser();
		$traverser->addVisitor(new ClosureNodeVisitor(static function(Node $node) use($visitors){
			foreach($visitors as $visitor){
				$result = $visitor($node);
				if($result !== null){
					return $result;
				}
			}
			return null;
		}));

		$this->nodes_modified = $traverser->traverse($this->nodes_modified);
	}

	/**
	 * @param Closure[] $visitors
	 *
	 * @phpstan-param Closure(Node, Scope, string) : null|int|Node ...$visitors
	 */
	public function visitWithScope(Closure ...$visitors) : void{
		$this->visit(function(Node $node) use($visitors){
			if(($scope_index = self::nodeHash($node)) !== null && isset($this->scopes[$scope_index])){
				$scope = $this->scopes[$scope_index];
				foreach($visitors as $visitor){
					$result = $visitor($node, $scope, $scope_index);
					if($result !== null){
						return $result;
					}
				}
			}
			return null;
		});
	}

	/**
	 * @param string $class
	 * @param string $method
	 * @param Closure ...$visitors
	 *
	 * @phpstan-param class-string $class
	 * @phpstan-param Closure(MethodCall|StaticCall, Scope) : null|int|Node ...$visitors
	 */
	public function visitMethodCalls(string $class, string $method, Closure ...$visitors) : void{
		if(!method_exists($class, $method)){
			throw new InvalidArgumentException("Method {$class}::{$method} does not exist");
		}

		$class_type = new ObjectType($class);
		$method = strtolower($method);
		$this->visitWithScope(static function(Node $node, Scope $scope, string $index) use($class_type, $method, $visitors){
			if($node instanceof Expr && $scope instanceof MutatingScope){
				if($node instanceof MethodCall){
					if($node->name instanceof Identifier && $node->name->toLowerString() === $method){
						$type = $scope->getType($node->var);
						if($class_type->accepts($type, true)){
							foreach($visitors as $visitor){
								$return = $visitor($node, $scope);
								if($return !== null){
									return $return;
								}
							}
						}
					}
				}elseif($node instanceof StaticCall){
					if(
						$node->name instanceof Identifier &&
						$node->name->toLowerString() === $method &&
						$class_type->accepts(new ObjectType($node->name->toString()), true)
					){
						foreach($visitors as $visitor){
							$return = $visitor($node, $scope);
							if($return !== null){
								return $return;
							}
						}
					}
				}
			}

			return null;
		});
	}

	/**
	 * @param Closure[] $visitors
	 *
	 * @phpstan-param class-string $class
	 * @phpstan-param Closure(ClassMethod $node, Scope $scope, string $class, string $method) : null|int|Node ...$visitors
	 */
	public function visitClassMethods(Closure ...$visitors) : void{
		$this->visitWithScope(static function(Node $node, Scope $scope, string $index) use($visitors){
			if($node instanceof ClassMethod && $scope instanceof MutatingScope){
				if($node->name instanceof Identifier){
					$class = $scope->getClassReflection()->getName();
					$method = $node->name->toString();
					foreach($visitors as $visitor){
						$result = $visitor($node, $scope, $class, $method);
						if($result !== null){
							return $result;
						}
					}
				}
			}

			return null;
		});
	}

	/**
	 * @param string $class
	 * @param string $method
	 * @return ClassMethod
	 *
	 * @phpstan-param class-string $class
	 * @phpstan-param Closure(ClassMethod) : null|int|Node ...$visitors
	 */
	public function getMethodNode(string $class, string $method) : ClassMethod{
		if(!method_exists($class, $method)){
			throw new InvalidArgumentException("Method {$class}::{$method} does not exist");
		}

		/** @var ClassMethod|null $method_node */
		$method_node = null;

		$method = strtolower($method);
		$this->visitClassMethods(static function(ClassMethod $node, Scope $scope, string $class_name, string $method_name) use($class, $method, &$method_node){
			if(strtolower($method_name) === $method && is_a($class_name, $class, true)){
				$method_node = $node;
			}
			return null;
		});

		return $method_node;
	}

	public function export() : string{
		$printer = new Standard();
		return $printer->printFormatPreserving($this->nodes_modified, $this->nodes_original, $this->tokens_original);
	}
}