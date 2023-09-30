<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

use Closure;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

final class ClosureNodeVisitor extends NodeVisitorAbstract{

	/**
	 * @param Closure(Node) : (Node|int) $enter_node
	 * @see NodeTraverser for return values
	 */
	public function __construct(
		readonly private Closure $enter_node
	){}

	public function enterNode(Node $node){
		return ($this->enter_node)($node);
	}
}