<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

final class Logger{

	public static function raw(string $type, string $message) : void{
		echo "[{$type}] {$message}" . PHP_EOL;
	}

	public static function info(string $message) : void{
		self::raw("INFO", $message);
	}

	public static function warning(string $message) : void{
		self::raw("WARNING", $message);
	}

	public static function error(string $message) : void{
		self::raw("ERROR", $message);
	}
}