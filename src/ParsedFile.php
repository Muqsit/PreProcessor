<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\EncapsedStringPart;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\MutatingScope;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;

final class ParsedFile{

	public static function exprHash(Expr $node) : ?string{
		static $printer = null;
		return $node instanceof EncapsedStringPart ? null : $node->getLine() . ":" . ($printer ?? $printer = new Standard())->prettyPrintExpr($node);
	}

	/** @var Scope[] */
	private $scopes;

	/** @var Node[] */
	private $nodes_original;

	/** @var Node[] */
	private $nodes_modified;

	/** @var mixed[] */
	private $tokens_original;

	/**
	 * @param array $scopes
	 * @param array $nodes_original
	 * @param array $tokens_original
	 */
	public function __construct(array $scopes, array $nodes_original, array $tokens_original){
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

	/**
	 * @param Closure[] $visitors
	 *
	 * @phpstan-param Closure(Expr, Scope, string) : null|int|Node ...$visitors
	 */
	public function visit(Closure ...$visitors) : void{
		$traverser = new NodeTraverser();
		$traverser->addVisitor(new ClosureNodeVisitor(function(Node $node) use($visitors) {
			if($node instanceof Expr && ($scope_index = self::exprHash($node)) !== null && isset($this->scopes[$scope_index])){
				$scope = $this->scopes[$scope_index];
				foreach($visitors as $visitor){
					$result = $visitor($node, $scope, $scope_index);
					if($result !== null){
						return $result;
					}
				}
			}
			return null;
		}));

		$this->nodes_modified = $traverser->traverse($this->nodes_modified);
	}

	/**
	 * @param string $class
	 * @param string $method
	 * @param Closure ...$visitors
	 *
	 * @phpstan-param class-string $class
	 * @phpstan-param Closure(MethodCall|StaticCall) : null|int|Node ...$visitors
	 */
	public function visitClassMethods(string $class, string $method, Closure ...$visitors) : void{
		$class_type = new ObjectType($class);
		$method = strtolower($method);
		$this->visit(static function(Expr $expr, Scope $scope, string $index) use($class_type, $class, $method, $visitors) {
			if($scope instanceof MutatingScope){
				if($expr instanceof MethodCall){
					if($expr->name->toLowerString() === $method){
						$type = $scope->getType($expr->var);
						if($class_type->accepts($type, true)){
							foreach($visitors as $visitor){
								$return = $visitor($expr);
								if($return !== null){
									return $return;
								}
							}
						}
					}
				}elseif($expr instanceof StaticCall){
					if($class_type->accepts(new ObjectType($expr->name->toString()),true)){
						foreach($visitors as $visitor){
							$return = $visitor($expr);
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

	public function export() : string{
		$printer = new Standard();
		return $printer->printFormatPreserving($this->nodes_modified, $this->nodes_original, $this->tokens_original);
	}
}