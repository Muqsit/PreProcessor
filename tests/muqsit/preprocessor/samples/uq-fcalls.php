<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

use Generator;
use function deg2rad;
use const M_PI;
use const M_PI_2;

final class Circle_TestUQFcalls{

	public static function fromAircraftAxes(float $radius, float $yaw, float $pitch, float $roll) : self{
		return new self($radius, deg2rad($pitch) - M_PI_2, -deg2rad($yaw - M_PI_2), $roll);
	}

	/**
	 * @var array{
	 *     float, float, float,
	 *     float, float, float,
	 *     float, float, float
	 * }
	 */
	private array $rotation_matrix;

	public function __construct(
		readonly public float $radius,
		readonly public float $x,
		readonly public float $y,
		readonly public float $z
	){
		$this->rotation_matrix = [
			cos($this->y) * cos($this->z) * $this->radius, ((sin($this->x) * sin($this->y) * cos($this->z)) - (cos($this->x) * sin($this->z))) * $this->radius, ((cos($this->x) * sin($this->y) * cos($this->z)) + (sin($this->x) * sin($this->z))) * $this->radius,
			cos($this->y) * sin($this->z) * $this->radius, ((sin($this->x) * sin($this->y) * sin($this->z)) + (cos($this->x) * cos($this->z))) * $this->radius, ((cos($this->x) * sin($this->y) * sin($this->z)) - (sin($this->x) * cos($this->z))) * $this->radius,
			-sin($this->y) * $this->radius, sin($this->x) * cos($this->y) * $this->radius, cos($this->x) * cos($this->y) * $this->radius
		];
		echo("Created circle of radius: {$this->radius}");
	}

	/**
	 * @param int $count
	 * @return Generator<array{float, float, float}>
	 */
	public function generatePoints(int $count) : Generator{
		$d = 2 * M_PI / $count;
		$mat = $this->rotation_matrix;
		assert(!empty($mat));
		for($i = 0; $i < $count; ++$i){
			$x = cos($d * $i);
			$z = sin($d * $i);
			$y = 0;
			list(
				$m1, $m2, $m3,
				$m4, $m5, $m6,
				$m7, $m8, $m9
			) = $mat;
			yield [
				($x * $m1) + ($y * $m2) + ($z * $m3),
				($x * $m4) + ($y * $m5) + ($z * $m6),
				($x * $m7) + ($y * $m8) + ($z * $m9)
			];
		}
	}
}