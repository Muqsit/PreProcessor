<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

use Exception;
use InvalidArgumentException;
use PhpParser\Lexer\Emulative;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PhpParser\Parser\Php7;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\Analyser;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\ContainerFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PreProcessor{

	/**
	 * @param string[] $paths
	 * @return self
	 */
	public static function fromPaths(array $paths) : self{
		return new self($paths);
	}

	/**
	 * @param string $directory
	 * @return self
	 */
	public static function fromDirectory(string $directory) : self{
		if(!is_dir($directory)){
			throw new InvalidArgumentException("Directory {$directory} does not exist");
		}

		$paths = [];
		/** @var SplFileInfo $file */
		foreach((new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory))) as $file){
			if($file->getExtension() === "php"){
				$paths[] = $file->getRealPath();
			}
		}

		return new self($paths);
	}

	/** @var ParsedFile[] */
	private $parsed_files = [];

	/**
	 * @param string[] $paths
	 */
	public function __construct(array $paths){
		$scope_holders = [];
		NotifierRule::registerListener($listener = function(Node $node, Scope $scope) use(&$scope_holders) : void{
			if($node instanceof Expr){
				$index = ParsedFile::exprHash($node);
				if($index !== null){
					$scope_holders[(new SplFileInfo($scope->getFile()))->getRealPath()][$index] = $scope;
				}
			}
		});

		$containerFactory = new ContainerFactory('/tmp');
		$container = $containerFactory->create('/tmp', [sprintf('%s/config.level%s.neon', $containerFactory->getConfigDirectory(), 8), "phpstan.neon"], []);

		/** @var Analyser $analyser */
		$analyser = $container->getByType(Analyser::class);

		$done = 0;
		$total = count($paths);
		$analyser->analyse($paths, static function(string $file) use($total, &$done) : void{
			Logger::info("[" . ++$done . " / {$total}] phpstan >> Reading {$file}");
		}, null, false, $paths)->getErrors();

		NotifierRule::unregisterListener($listener);


		$lexer = new Emulative([
			'usedAttributes' => [
				'comments',
				'startLine', 'endLine',
				'startTokenPos', 'endTokenPos'
			],
		]);
		$parser = new Php7($lexer);

		$done = 0;
		foreach($scope_holders as $path => $scopes){
			Logger::info("[" . ++$done . " / {$total}] php-parser >> Reading {$path}");
			$nodes_original = $parser->parse(file_get_contents($path));
			$tokens_original = $lexer->getTokens();
			$this->parsed_files[$path] = new ParsedFile($scopes, $nodes_original, $tokens_original);
		}
	}

	/**
	 * @param string $class
	 * @param string $method
	 * @return self
	 *
	 * @phpstan-param class-string $class
	 */
	public function commentOut(string $class, string $method) : self{
		$printer = new Standard();
		foreach($this->parsed_files as $file){
			$file->visitClassMethods($class, $method, function(Expr $node) use($printer) {
				$expression = $printer->prettyPrintExpr($node);
				Logger::info("Commented out " . str_replace(PHP_EOL, "", $expression));
				return new ConstFetch(new Name("/* {$expression} */"));
			});
		}

		return $this;
	}

	public function export(string $output_folder, bool $overwrite = false) : void{
		if(!is_dir($output_folder)){
			throw new Exception("Directory {$output_folder} does not exist.");
		}

		$cwd = getcwd();
		foreach($this->parsed_files as $path => $file){
			if(strpos($path, $cwd) === 0){
				$target = $output_folder . substr($path, strlen($cwd));
			}else{
				$target = $output_folder . "/" . $path;
			}

			if($overwrite || !file_exists($target)){
				$directory = (new SplFileInfo($target))->getPath();
				if(!is_dir($directory)){
					/** @noinspection MkdirRaceConditionInspection */
					mkdir($directory, 0777, true);
				}
				if(file_put_contents($target, $file->export()) !== false){
					Logger::info("Wrote modified {$path} to {$target}");
				}else{
					Logger::info("Failed to write {$path} to {$target}");
				}
			}else{
				Logger::warning("Failed to write {$path} to {$target}, file already exists");
			}
		}
	}
}
