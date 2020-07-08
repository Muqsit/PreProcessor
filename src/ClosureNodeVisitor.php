<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

use Closure;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class ClosureNodeVisitor extends NodeVisitorAbstract{

	/** @var Closure */
	private $enter_node;

	public function __construct(Closure $enter_node){
		$this->enter_node = $enter_node;
	}

	public function enterNode(Node $node){
		return ($this->enter_node)($node);
	}
}