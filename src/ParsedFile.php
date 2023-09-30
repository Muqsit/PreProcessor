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
use PHPStan\Type\ObjectType;
use SplFileInfo;

final class ParsedFile{

	public static function nodeHash(Node $node) : ?string{
		$tokens = [
			$node->getType(), $node->getLine(),
			$node->getStartFilePos(), $node->getEndFilePos(),
			$node->getStartLine(), $node->getEndLine(),
			$node->getStartTokenPos(), $node->getEndTokenPos()
		];
		if($node instanceof Expr){
			try{
				static $printer = null;
				$printer ??= new Standard();
				$tokens[] = $printer->prettyPrintExpr($node);
			}catch(Error | Exception){
			}
		}
		return implode(":", $tokens);
	}

	/** @var Node[] */
	private array $nodes_modified;

	/**
	 * @param SplFileInfo $file
	 * @param Scope[] $scopes
	 * @param Node[] $nodes_original
	 * @param Node[] $tokens_original
	 */
	public function __construct(
		readonly public SplFileInfo $file,
		readonly private array $scopes,
		readonly private array $nodes_original,
		readonly private array $tokens_original
	){
		$traverser = new NodeTraverser();
		$traverser->addVisitor(new CloningVisitor());
		$traverser->addVisitor(new NameResolver(null, [
			'preserveOriginalNames' => false,
			'replaceNodes' => true
		]));
		$this->nodes_modified = $traverser->traverse($this->nodes_original);
	}

	/**
	 * @param Closure(Node) : (null|int|Node) ...$visitors
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
	 * @param Closure(Node, Scope, string) : (null|int|Node) ...$visitors
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
	 * @param class-string $class
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
						if($class_type->accepts($type, true)->yes()){
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
						$class_type->accepts(new ObjectType($node->name->toString()), true)->yes()
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
	 * @param Closure(ClassMethod $node, Scope $scope, string $class, string $method) : (null|int|Node) ...$visitors
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
	 * @param class-string $class
	 * @param string $method
	 * @return ClassMethod
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