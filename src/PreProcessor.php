<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

use InvalidArgumentException;
use PhpParser\Lexer;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\Parser\Php7;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\Type\ErrorType;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
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
			file_exists($path) || throw new InvalidArgumentException("File {$path} does not exist");
			$file = new SplFileInfo($path);
			$file->getExtension() === "php" || throw new InvalidArgumentException("{$path} is not a .php file");
			$files[] = $file;
		}
		return new self($files);
	}

	public static function fromDirectory(string $directory) : self{
		is_dir($directory) || throw new InvalidArgumentException("Directory {$directory} does not exist");
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
		$done = 0;
		$total = count($files);
		$scope_factory = $container->getByType(ScopeFactory::class);
		$scope_resolver = $container->getByType(NodeScopeResolver::class);
		foreach($files as $file){
			$path = $file->getRealPath();
			Logger::info("[" . ++$done . " / {$total}] php-parser >> Reading {$path}");
			$nodes_original = $parser->parse(file_get_contents($path));
			$tokens_original = $lexer->getTokens();
			$this->parsed_files[$path] = new ParsedFile($scope_factory, $scope_resolver, $file, $nodes_original, $tokens_original);
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

	private function printLinePos(Node $node, Scope $scope) : string{
		return $scope->isInClass() ? "{$scope->getClassReflection()->getName()}:{$node->getLine()}" : "{$scope->getFile()}:{$node->getLine()}";
	}

	/**
	 * @param class-string $class
	 * @param string $method
	 * @return self
	 */
	public function commentOut(string $class, string $method) : self{
		$printer = new Standard();
		$done = 0;
		$total = count($this->parsed_files);
		foreach($this->parsed_files as $path => $file){
			Logger::info("[" . ++$done . " / {$total}] preprocessor >> Searching for {$class}::{$method} references in {$path}");
			$file->visitMethodCalls($class, $method, function(MethodCall|StaticCall $node, Scope $scope) use($printer){
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
					$node instanceof FuncCall &&
					($namespaced_name = $node->name->getAttribute("namespacedName")) !== null &&
					!function_exists(implode("\\", $namespaced_name->parts)) &&
					function_exists("\\" . implode("\\", $node->name->parts))
				){
					$new = clone $node;
					$new->name = new FullyQualified($node->name->parts);
					Logger::info("Replaced function call with unqualified name {$printer->prettyPrintExpr($node)} with fully qualified name {$printer->prettyPrintExpr($new)} in {$path}");
					return $new;
				}
				return null;
			});
		}
		return $this;
	}

	/**
	 * @param class-string $class
	 * @param string $method
	 * @return self
	 */
	public function inlineMethodCall(string $class, string $method) : self{
		foreach($this->parsed_files as $file){
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
				$file->visitMethodCalls($class, $method, function(MethodCall|StaticCall $node, Scope $scope) use($traverse_stmts, $params){
					$mapping = [];
					foreach($node->args as $index => $arg){
						$mapping[$params[$index]] = $arg->value;
					}

					$traverser = new NodeTraverser();
					$traverser->addVisitor(new ClosureNodeVisitor(function(Node $node) use($mapping) {
						return $node instanceof Variable ? clone $mapping[$node->name] : clone $node;
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
	 * @return self
	 */
	public function replaceIssetWithArrayKeyExists() : self{
		$printer = new Standard();
		foreach($this->parsed_files as $path => $file){
			$file->visitWithScope(function(Node $node, Scope $scope) use($path, $printer) {
				if(
					$node instanceof Isset_ &&
					count($node->vars) === 1 // TODO: Add support for multiple parameters
				){
					$var = $node->vars[0];
					if(
						$var instanceof ArrayDimFetch &&
						$scope->getType($var->var)->isArray()->yes()
					){
						$key_type = $scope->getType($var->dim);
						if(!($key_type->toInteger() instanceof ErrorType) || !($key_type->toString() instanceof ErrorType)){
							$array_key_exists_fcall = new FuncCall(new FullyQualified(["array_key_exists"]), [$var->dim, $var->var]);
							Logger::info("Replaced isset -> array_key_exists: {$printer->prettyPrintExpr($node)} -> {$printer->prettyPrintExpr($array_key_exists_fcall)} in {$path}");
							return $array_key_exists_fcall;
						}
					}
				}
				return null;
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
		foreach($this->parsed_files as $file){
			Logger::info("[" . ++$done . " / {$total}] preprocessor >> Searching for types to remove");
			$file->visitClassMethods(static function(ClassMethod $node, Scope $scope, string $class, string $method) use($types){
				if($node->isPrivate() || $node->isFinal() || $scope->getClassReflection()->isFinal()){
					$changed = false;
					foreach($node->params as $param){
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
			++$done;
			$file->visitClassMethods(function(ClassMethod $node, Scope $scope, string $class, string $method) use(&$method_to_property_mapping, &$non_public_properties, $done, $total, $path){
				Logger::info("[" . $done . " / {$total}] [{$class}::{$method}] preprocessor >> Searching for final class getters to optimize");
				$class_reflection = $scope->getClassReflection();
				if(
					$class_reflection === null || // scope is not in a class
					$node->isPrivate() ||
					(!$class_reflection->isFinal() && !$node->isFinal())
				){
					return null;
				}

				if(count($node->stmts) !== 1){
					return null;
				}

				$stmt = current($node->stmts);
				assert($stmt !== false);
				if(
					!($stmt instanceof Return_) ||
					!($stmt->expr instanceof PropertyFetch) ||
					!($stmt->expr->var instanceof Variable) ||
					$stmt->expr->var->name !== "this"
				){
					return null;
				}

				$property = $class_reflection->getProperty($stmt->expr->name->name, $scope);
				if(!$property->getDeclaringClass()->is($class)){
					// if class has a parent class, the property may not be defined in this class
					return null;
				}

				$method_to_property_mapping["{$class}::{$method}"] = [$class, $method, $stmt->expr->name->name];
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
			$this->parsed_files[$path]->visitWithScope(static function(Node $node, Scope $scope) use($class, $property, $path) {
				$class_reflection = $scope->getClassReflection();
				if($class_reflection === null || $class_reflection->getName() !== $class){
					return null;
				}

				if($node instanceof Property){
					// check for non constructor promoted properties
					if($node->props[0]->name->name !== $property){
						return null;
					}
				}elseif($node instanceof Param){
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

				$node->flags &= ~Class_::MODIFIER_PRIVATE;
				$node->flags &= ~Class_::MODIFIER_PROTECTED;
				$node->flags |= Class_::MODIFIER_PUBLIC;
				Logger::info("Updated visibility of property {$class}::\${$property} to public in {$path}");
				return $node;
			});
		}

		$done = 0;
		$total = count($method_to_property_mapping);
		foreach($method_to_property_mapping as [$class, $method, $property]){
			Logger::info("[" . ++$done . " / {$total}] preprocessor >> Replacing getter method calls to {$class}::{$method} with property-fetch");
			foreach($this->parsed_files as $path => $file){
				$file->visitMethodCalls($class, $method, function(MethodCall|StaticCall $node, Scope $scope) use($property, $printer, $path){
					if(!($node instanceof MethodCall)){
						return null;
					}
					$replacement = new PropertyFetch($node->var, $property);
					Logger::info("Replaced getter method call {$printer->prettyPrintExpr($node)} with property call {$printer->prettyPrintExpr($replacement)} in {$path}");
					return $replacement;
				});
			}
		}
		return $this;
	}

	public function export(string $output_folder, bool $overwrite = false) : void{
		is_dir($output_folder) || throw new InvalidArgumentException("Directory {$output_folder} does not exist.");
		$cwd = getcwd();
		$printer = new Standard();
		foreach($this->parsed_files as $path => $file){
			if(str_starts_with($path, $cwd)){
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
				if(file_put_contents($target, $file->export($printer)) !== false){
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
