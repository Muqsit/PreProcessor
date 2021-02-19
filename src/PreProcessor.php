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
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\Parser\Php7;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\FileAnalyser;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\Rules\Registry;
use PHPStan\Type\ErrorType;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PreProcessor{

	/**
	 * @param string[] $paths
	 * @return self
	 */
	public static function fromPaths(array $paths) : self{
		$files = [];
		foreach($paths as $path){
			if(!file_exists($path)){
				throw new InvalidArgumentException("File {$path} does not exist");
			}

			$file = new SplFileInfo($path);
			if($file->getExtension() !== "php"){
				throw new InvalidArgumentException("{$path} is not a .php file");
			}

			$files[] = $file;
		}

		return new self($files);
	}

	public static function fromDirectory(string $directory) : self{
		if(!is_dir($directory)){
			throw new InvalidArgumentException("Directory {$directory} does not exist");
		}

		$files = [];
		/** @var SplFileInfo $file */
		foreach((new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory))) as $file){
			if($file->getExtension() === "php"){
				$files[] = $file;
			}
		}

		return new self($files);
	}

	/** @var ParsedFile[] */
	private $parsed_files = [];

	/**
	 * @param SplFileInfo[] $files
	 */
	public function __construct(array $files){
		$containerFactory = new ContainerFactory('/tmp');
		$container = $containerFactory->create('/tmp', [], []);

		$done = 0;
		$total = count($files);
		$scope_holders = [];

		/** @var FileAnalyser $file_analyser */
		$file_analyser = $container->getByType(FileAnalyser::class);
		$registry = new Registry([]);
		foreach($files as $file){
			$path = $file->getRealPath();
			$file_analyser->analyseFile($path, [], $registry, function(Node $node, Scope $scope) use($path, &$scope_holders){
				$index = ParsedFile::nodeHash($node);
				if($index !== null){
					$scope_holders[$path][$index] = $scope;
				}
			});
			Logger::info("[" . ++$done . " / {$total}] phpstan >> Reading {$path}");
		}

		$lexer = new Emulative([
			'usedAttributes' => [
				'comments',
				'startLine', 'endLine',
				'startTokenPos', 'endTokenPos'
			],
		]);
		$parser = new Php7($lexer);

		$done = 0;
		foreach($files as $file){
			$path = $file->getRealPath();
			Logger::info("[" . ++$done . " / {$total}] php-parser >> Reading {$path}");
			$nodes_original = $parser->parse(file_get_contents($path));
			$tokens_original = $lexer->getTokens();
			$this->parsed_files[$path] = new ParsedFile($file, $scope_holders[$path] ?? [], $nodes_original, $tokens_original);
		}
	}

	private function printLinePos(Node $node, Scope $scope) : string{
		return $scope->isInClass() ? "{$scope->getClassReflection()->getName()}:{$node->getLine()}" : "{$scope->getFile()}:{$node->getLine()}";
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
		$done = 0;
		$total = count($this->parsed_files);

		foreach($this->parsed_files as $path => $file){
			Logger::info("[" . ++$done . " / {$total}] preprocessor >> Searching for {$class}::{$method} references in {$path}");
			$file->visitMethodCalls($class, $method, function(Expr $node, Scope $scope) use($printer){
				$expression = $printer->prettyPrintExpr($node);
				Logger::info("[{$this->printLinePos($node, $scope)}] Commented out " . str_replace(PHP_EOL, "", $expression));
				return new ConstFetch(new Name("/* {$expression} */"));
			});
		}

		return $this;
	}

	public function replaceUQFunctionNamesToFQ() : self{
		$printer = new Standard();

		foreach($this->parsed_files as $path => $file){
			$file->visit(function(Node $node) use($printer, $path){
				if(
					$node instanceof Expr\FuncCall &&
					($namespaced_name = $node->name->getAttribute("namespacedName")) !== null &&
					!function_exists(implode("\\", $namespaced_name->parts)) &&
					function_exists("\\" . implode("\\", $node->name->parts))
				){
					$new = clone $node;
					$new->name = new Name\FullyQualified($node->name->parts);
					Logger::info("Replaced function call with unqualified name {$printer->prettyPrintExpr($node)} with fully qualified name {$printer->prettyPrintExpr($new)} in {$path}");
					return $new;
				}
				return null;
			});
		}

		return $this;
	}

	/**
	 * @param string $class
	 * @param string $method
	 * @return self
	 *
	 * @phpstan-param class-string $class
	 */
	public function inlineMethodCall(string $class, string $method) : self{
		foreach($this->parsed_files as $path => $file){
			$method_node = $file->getMethodNode($class, $method);
			$stmts = $method_node->getStmts();
			if(count($stmts) === 1){
				$params = [];
				foreach($method_node->params as $param){
					$params[] = $param->var->name;
				}

				$traverse_stmts = [];
				foreach($stmts as $stmt){
					$traverse_stmts[] = $stmt->expr;
				}

				$file->visitMethodCalls($class, $method, function(Expr $node, Scope $scope) use($traverse_stmts, $params){
					assert($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall);

					$mapping = [];
					foreach($node->args as $index => $arg){
						$mapping[$params[$index]] = $arg->value;
					}

					$traverser = new NodeTraverser();
					$traverser->addVisitor(new ClosureNodeVisitor(function(Node $node) use($mapping) {
						return $node instanceof Expr\Variable ? clone $mapping[$node->name] : clone $node;
					}));
					return $traverser->traverse($traverse_stmts)[0];
				});
			}
		}

		return $this;
	}

	/**
	 * @return self
	 *
	 * @phpstan-param class-string $class
	 */
	public function replaceIssetWithArrayKeyExists() : self{
		$printer = new Standard();
		foreach($this->parsed_files as $path => $file){
			$file->visitWithScope(function(Node $node, Scope $scope, string $index) use($path, $printer) {
				if(
					$node instanceof Expr\Isset_ &&
					count($node->vars) === 1 // TODO: Add support for multiple parameters
				){
					$var = $node->vars[0];
					if(
						$var instanceof Expr\ArrayDimFetch &&
						$scope->getType($var->var)->isArray()->yes()
					){
						$key_type = $scope->getType($var->dim);
						if(!($key_type->toInteger() instanceof ErrorType) || !($key_type->toString() instanceof ErrorType)){
							$array_key_exists_fcall = new Expr\FuncCall(new Name\FullyQualified(["array_key_exists"]), [$var->dim, $var->var]);
							Logger::info("Replaced isset -> array_key_exists: {$printer->prettyPrintExpr($node)} -> {$printer->prettyPrintExpr($array_key_exists_fcall)} in {$path}");
							return $array_key_exists_fcall;
						}
					}
				}
			});
		}

		return $this;
	}

	/**
	 * Example for $types: ["int", "string", "array", mysqli::class]
	 *
	 * @param string[] $types
	 * @return self
	 */
	public function removeTypeFromMethodParameters(array $types) : self{
		$done = 0;
		$total = count($this->parsed_files);
		foreach($this->parsed_files as $path => $file){
			Logger::info("[" . ++$done . " / {$total}] preprocessor >> Searching for types to remove");
			$file->visitClassMethods(static function(ClassMethod $node, Scope $scope, string $class, string $method) use($types){
				if($node->isPrivate() || $node->isFinal() || $scope->getClassReflection()->isFinal()){
					$changed = false;
					foreach($node->params as $x => $param){
						if($param->type !== null){
							$type_string = "";
							if($param->type instanceof NullableType){
								$type = $param->type->type;
								$type_string .= "?";
							}else{
								$type = $param->type;
							}
							$type_string .= $type->toString();
							if(in_array(($param->type instanceof NullableType ? $param->type->type : $param->type)->toString(), $types, true)){
								Logger::info("Removed type {$type_string} from parameter \"{$param->var->name}\" of method {$class}::{$method}");
								$param->type = null;
								$changed = true;
							}
						}
					}
					return $changed ? $node : null;
				}
				return null;
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
