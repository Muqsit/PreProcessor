<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

use Exception;
use InvalidArgumentException;
use PhpParser\Lexer;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser\Php7;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\FileAnalyser;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\RuleErrorTransformer;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\Collectors\Registry as CollectorRegistry;
use PHPStan\Dependency\DependencyResolver;
use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\Parser\RichParser;
use PHPStan\Rules\Registry as RuleRegistry;
use PHPStan\Type\ErrorType;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use function array_key_exists;
use function assert;
use function count;
use function current;
use function file_get_contents;
use const PHP_EOL;

final class PreProcessor{

	/**
	 * @param list<string> $paths
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

	/** @var array<string, ParsedFile> */
	private array $parsed_files = [];

	/**
	 * @param list<SplFileInfo> $files
	 */
	public function __construct(array $files){
		$container = $this->createContainer();
		$lexer = $this->createLexer();
		$parser = new Php7($lexer);
		$analyser = $this->createAnalyzer($container, $lexer, $parser);

		$done = 0;
		$total = count($files);
		$scope_holders = [];
		foreach($files as $file){
			$path = $file->getRealPath();
			Logger::info("[" . ++$done . " / {$total}] phpstan >> Reading {$path}");
			$analyser->analyseFile($path, [], $container->getByType(RuleRegistry::class), $container->getByType(CollectorRegistry::class), function(Node $node, Scope $scope) use($path, &$scope_holders) : void{
				$index = ParsedFile::nodeHash($node);
				if($index !== null){
					if(array_key_exists($path, $scope_holders) && array_key_exists($index, $scope_holders[$path])){
						throw new RuntimeException("Found node hash collision when reading {$path}");
					}
					$scope_holders[$path][$index] = $scope;
				}
			});
		}

		$done = 0;
		foreach($files as $file){
			$path = $file->getRealPath();
			Logger::info("[" . ++$done . " / {$total}] php-parser >> Reading {$path}");
			$nodes_original = $parser->parse(file_get_contents($path));
			$tokens_original = $lexer->getTokens();
			$this->parsed_files[$path] = new ParsedFile($file, $scope_holders[$path] ?? [], $nodes_original, $tokens_original);
		}
	}

	private function createContainer() : Container{
		$containerFactory = new ContainerFactory('/tmp');
		$container = $containerFactory->create('/tmp', [], []);
		foreach($container->getParameter("bootstrapFiles") as $bootstrapFile){
			(static function (string $file) : void {
				require_once $file;
			})($bootstrapFile);
		}
		return $container;
	}

	private function createLexer() : Lexer{
		return new Lexer(['usedAttributes' => ['comments', 'startLine', 'endLine', 'startTokenPos', 'endTokenPos']]);
	}

	private function createAnalyzer(Container $container, Lexer $lexer, Php7 $parser) : FileAnalyser{
		return new FileAnalyser(
			$container->getByType(ScopeFactory::class),
			$container->getByType(NodeScopeResolver::class),
			new RichParser($parser, $lexer, new NameResolver(), $container),
			$container->getByType(DependencyResolver::class),
			$container->getByType(RuleErrorTransformer::class),
			false
		);
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
	 * WARNING: This preprocessing is quite aggressive as it breaks cases where
	 * nullable values are stored in arrays.
	 * In $a = ["key" => null] for example, isset($a["key"]) would return false
	 * while array_key_exists("key", $a) returns true.
	 *
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

	public function optimizeFinalGetters() : self{
		$done = 0;
		$total = count($this->parsed_files);

		$non_public_properties = [];
		$method_to_property_mapping = [];
		foreach($this->parsed_files as $path => $file){
			Logger::info("[" . ++$done . " / {$total}] preprocessor >> Searching for final class getters to optimize");
			$file->visitClassMethods(static function(ClassMethod $node, Scope $scope, string $class, string $method) use(&$method_to_property_mapping, &$non_public_properties, $path){
				$class_reflection = $scope->getClassReflection();
				if(
					$class_reflection === null || // scope is not in a class
					$node->isPrivate() ||
					(!$class_reflection->isFinal() && !$node->isFinal()) ||
					$class_reflection->getParentClass() !== null
				){
					return null;
				}

				if(count($node->stmts) !== 1){
					return null;
				}

				$stmt = current($node->stmts);
				assert($stmt !== false);
				if(
					!($stmt instanceof Node\Stmt\Return_) ||
					!($stmt->expr instanceof Expr\PropertyFetch) ||
					!($stmt->expr->var instanceof Expr\Variable) ||
					$stmt->expr->var->name !== "this"
				){
					return null;
				}

				$method_to_property_mapping["{$class}::{$method}"] = [$class, $method, $stmt->expr->name->name];

				$property = $class_reflection->getProperty($stmt->expr->name->name, $scope);
				if(!$property->isPublic()){
					$non_public_properties["{$class}::{$stmt->expr->name->name}"] = [$path, $class, $stmt->expr->name->name];
				}
				return null;
			});
		}

		$printer = new Standard();
		$done = 0;
		$total = count($non_public_properties);
		foreach($non_public_properties as [$path, $class, $property]){
			Logger::info("[" . ++$done . " / {$total}] preprocessor >> Updating visibility of final class getters");
			$this->parsed_files[$path]->visitWithScope(static function(Node $node, Scope $scope, string $index) use($class, $property, $path) {
				$class_reflection = $scope->getClassReflection();
				if($class_reflection === null || $class_reflection->getName() !== $class){
					return null;
				}

				if($node instanceof Node\Stmt\Property){
					// check for non constructor promoted properties
					if($node->props[0]->name->name !== $property){
						return null;
					}
				}elseif($node instanceof Node\Param){
					// check for constructor promoted properties
					if(
						($node->flags & Class_::VISIBILITY_MODIFIER_MASK) === 0 || // has no visibility specified
						($node->flags & Class_::MODIFIER_PUBLIC) !== 0 // has public visibility
					){
						return null;
					}
				}else{
					return null;
				}

				$node->flags = Class_::MODIFIER_PUBLIC;
				Logger::info("Updated visibility of property {$class}::\${$property} to public in {$path}");
				return $node;
			});
		}

		$done = 0;
		$total = count($method_to_property_mapping);
		foreach($method_to_property_mapping as [$class, $method, $property]){
			Logger::info("[" . ++$done . " / {$total}] preprocessor >> Replacing getter method calls with property-fetch");
			foreach($this->parsed_files as $path => $file){
				$file->visitMethodCalls($class, $method, function(Expr $node, Scope $scope) use($property, $printer, $path){
					if(!($node instanceof Expr\MethodCall)){
						return null;
					}

					$replacement = new Expr\PropertyFetch($node->var, $property);
					Logger::info("Replaced getter method call {$printer->prettyPrintExpr($node)} with property call {$printer->prettyPrintExpr($replacement)} in {$path}");
					return $replacement;
				});
			}
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
