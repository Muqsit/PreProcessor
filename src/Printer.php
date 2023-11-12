<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

use PhpParser\PrettyPrinter\Standard;
use PHPStan\Node\Expr\AlwaysRememberedExpr;

class Printer extends Standard{

	protected function pPHPStan_Node_AlwaysRememberedExpr(AlwaysRememberedExpr $expr) : string{
		return $this->p($expr->getExpr());
	}
}