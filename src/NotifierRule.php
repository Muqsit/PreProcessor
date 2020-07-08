<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

use Closure;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

final class NotifierRule implements Rule{

	/** @var array<int, Closure(Node, Scope) : void> */
	private static $listeners = [];

	public static function registerListener(Closure $listener) : void{
		self::$listeners[spl_object_id($listener)] = $listener;
	}

	public static function unregisterListener(Closure $listener) : void{
		unset(self::$listeners[spl_object_id($listener)]);
	}

	public function getNodeType() : string{
		return Node::class;
	}

	public function processNode(Node $node, Scope $scope) : array{
		foreach(self::$listeners as $listener){
			$listener($node, $scope);
		}
		return [];
	}
}