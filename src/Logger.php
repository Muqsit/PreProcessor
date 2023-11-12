<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

use InvalidArgumentException;
use PHP_Parallel_Lint\PhpConsoleColor\ConsoleColor;
use PHP_Parallel_Lint\PhpConsoleColor\InvalidStyleException;
use function in_array;
use function str_replace;
use const PHP_EOL;

final class Logger{

	public const LEVEL_INFO = 0;
	public const LEVEL_WARN = 1;

	public const STYLE_COLOR_BLACK = 0;
	public const STYLE_COLOR_RED = 1;
	public const STYLE_COLOR_GREEN = 2;
	public const STYLE_COLOR_YELLOW = 3;
	public const STYLE_COLOR_BLUE = 4;
	public const STYLE_COLOR_MAGENTA = 5;
	public const STYLE_COLOR_CYAN = 6;
	public const STYLE_COLOR_LIGHT_GRAY = 7;
	public const STYLE_COLOR_DARK_GRAY = 8;
	public const STYLE_COLOR_LIGHT_RED = 9;
	public const STYLE_COLOR_LIGHT_GREEN = 10;
	public const STYLE_COLOR_LIGHT_YELLOW = 11;
	public const STYLE_COLOR_LIGHT_BLUE = 12;
	public const STYLE_COLOR_LIGHT_MAGENTA = 13;
	public const STYLE_COLOR_LIGHT_CYAN = 14;
	public const STYLE_COLOR_WHITE = 15;

	readonly private ConsoleColor $console_color;

	/**
	 * @param list<self::LEVEL_*> $levels
	 */
	public function __construct(
		public array $levels = [self::LEVEL_INFO, self::LEVEL_WARN]
	){
		$this->console_color = new ConsoleColor();
	}

	/**
	 * @param string $text
	 * @param self::STYLE_* $style
	 * @return string
	 */
	public function style(string $text, int $style) : string{
		try{
			return $this->console_color->apply(match($style){
				self::STYLE_COLOR_BLACK => "black",
				self::STYLE_COLOR_RED => "red",
				self::STYLE_COLOR_GREEN => "green",
				self::STYLE_COLOR_YELLOW => "yellow",
				self::STYLE_COLOR_BLUE => "blue",
				self::STYLE_COLOR_MAGENTA => "magenta",
				self::STYLE_COLOR_CYAN => "cyan",
				self::STYLE_COLOR_LIGHT_GRAY => "light_gray",
				self::STYLE_COLOR_DARK_GRAY => "dark_gray",
				self::STYLE_COLOR_LIGHT_RED => "light_red",
				self::STYLE_COLOR_LIGHT_GREEN => "light_green",
				self::STYLE_COLOR_LIGHT_YELLOW => "light_yellow",
				self::STYLE_COLOR_LIGHT_BLUE => "light_blue",
				self::STYLE_COLOR_LIGHT_MAGENTA => "light_magenta",
				self::STYLE_COLOR_LIGHT_CYAN => "light_cyan",
				self::STYLE_COLOR_WHITE => "white",
				default => throw new InvalidStyleException("Unexpected style: {$style}")
			}, $text);
		}catch(InvalidStyleException $e){
			throw new InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
		}
	}

	public function info(string $text) : void{
		$this->log(self::LEVEL_INFO, $text);
	}

	public function warn(string $text) : void{
		$this->log(self::LEVEL_WARN, $this->style("warn: {$text}", self::STYLE_COLOR_YELLOW));
	}

	private function log(int $level, string $text) : void{
		if(in_array($level, $this->levels, true)){
			echo str_replace(PHP_EOL, "\\n", $text), PHP_EOL;
		}
	}
}