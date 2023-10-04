<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

use Closure;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\Type\ObjectType;
use SplFileInfo;
use function method_exists;
use function spl_object_id;

final class ParsedFile{

	/** @var array<int, Scope>|null */
	private ?array $scopes_cached = null;

	/** @var Node[] */
	private array $nodes_modified;

	/**
	 * @param ScopeFactory $scope_factory
	 * @param NodeScopeResolver $scope_resolver
	 * @param SplFileInfo $file
	 * @param Node[] $nodes_original
	 * @param Node[] $tokens_original
	 */
	public function __construct(
		readonly private ScopeFactory $scope_factory,
		readonly private NodeScopeResolver $scope_resolver,
		readonly public SplFileInfo $file,
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
	 * @param Closure(Node, Scope) : (null|int|Node) ...$visitors
	 */
	public function visitWithScope(Closure ...$visitors) : void{
		$this->visit(function(Node $node) use($visitors){
			if($this->scopes_cached === null){
				$this->scopes_cached = [];
				$mutating_scope = $this->scope_factory->create(ScopeContext::create($this->file->getPathname()));
				$this->scope_resolver->processNodes($this->nodes_modified, $mutating_scope, function(Node $node, Scope $scope) : void{
					$this->scopes_cached[spl_object_id($node)] = $scope;
				});
			}
			if(!isset($this->scopes_cached[$id = spl_object_id($node)])){
				return null;
			}
			$scope = $this->scopes_cached[$id];
			foreach($visitors as $visitor){
				$result = $visitor($node, $scope);
				if($result !== null){
					$this->scopes_cached = null;
					return $result;
				}
			}
			return null;
		});
	}

	/**
	 * @param class-string $class
	 * @param string $method
	 * @param Closure(MethodCall|StaticCall, Scope) : (null|int|Node) ...$visitors
	 */
	public function visitMethodCalls(string $class, string $method, Closure ...$visitors) : void{
		method_exists($class, $method) || throw new InvalidArgumentException("Method {$class}::{$method} does not exist");
		$method = strtolower($method);
		$this->visitWithScope(static function(Node $node, Scope $scope) use($class, $method, $visitors){
			if($node instanceof Expr){
				if($node instanceof MethodCall){
					if($node->name instanceof Identifier && $node->name->toLowerString() === $method){
						$type = $scope->getType($node->var);
						if($type instanceof ObjectType && $type->isInstanceOf($class)->yes()){
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
						$node->class instanceof Name &&
						$node->name instanceof Identifier &&
						$node->name->toLowerString() === $method &&
						$node->class->toString() === $class
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
		$this->visitWithScope(static function(Node $node, Scope $scope) use($visitors){
			if($node instanceof ClassMethod && $node->name instanceof Identifier){
				$class = $scope->getClassReflection()->getName();
				$method = $node->name->toString();
				foreach($visitors as $visitor){
					$result = $visitor($node, $scope, $class, $method);
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
	 * @return ClassMethod
	 */
	public function getMethodNode(string $class, string $method) : ClassMethod{
		method_exists($class, $method) || throw new InvalidArgumentException("Method {$class}::{$method} does not exist");
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
		return (new Standard())->printFormatPreserving($this->nodes_modified, $this->nodes_original, $this->tokens_original);
	}
}