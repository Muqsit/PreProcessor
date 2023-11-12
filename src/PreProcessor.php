<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

use Generator;
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
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser\Php7;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ErrorType;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Filesystem\Path;
use function assert;
use function count;
use function current;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function getcwd;
use function implode;
use function in_array;
use function is_dir;
use function mkdir;
use function sprintf;

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
		return self::fromFiles($files);
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
		return self::fromFiles($files);
	}

	/**
	 * @param list<SplFileInfo> $files
	 */
	public static function fromFiles(array $files) : self{
		$container = self::createContainer();
		$lexer = self::createLexer();
		$parser = new Php7($lexer);
		$scope_factory = $container->getByType(ScopeFactory::class);
		$scope_resolver = $container->getByType(NodeScopeResolver::class);
		$logger = new Logger();
		$parsed_files = [];
		foreach($files as $file){
			$path = $file->getRealPath();
			$nodes_original = $parser->parse(file_get_contents($path));
			$tokens_original = $lexer->getTokens();
			$parsed_files[$path] = new ParsedFile($scope_factory, $scope_resolver, $file, $nodes_original, $tokens_original);
		}
		return new self($container, $lexer, $parser, $scope_factory, $scope_resolver, $logger, $parsed_files);
	}

	private static function createContainer() : Container{
		$containerFactory = new ContainerFactory('/tmp');
		$container = $containerFactory->create('/tmp', [], []);
		foreach($container->getParameter("bootstrapFiles") as $bootstrapFile){
			(static function (string $file) : void {
				require_once $file;
			})($bootstrapFile);
		}
		return $container;
	}

	private static function createLexer() : Lexer{
		return new Lexer(['usedAttributes' => ['comments', 'startLine', 'endLine', 'startTokenPos', 'endTokenPos']]);
	}

	/**
	 * @param Container $container
	 * @param Lexer $lexer
	 * @param Php7 $parser
	 * @param ScopeFactory $scope_factory
	 * @param NodeScopeResolver $scope_resolver
	 * @param Logger $logger
	 * @param array<string, ParsedFile> $parsed_files
	 */
	public function __construct(
		readonly public Container $container,
		readonly public Lexer $lexer,
		readonly public Php7 $parser,
		readonly public ScopeFactory $scope_factory,
		readonly public NodeScopeResolver $scope_resolver,
		readonly public Logger $logger,
		readonly public array $parsed_files
	){}

	private function printLinePos(Node $node, Scope $scope) : string{
		return $scope->isInClass() ? "{$scope->getClassReflection()->getName()}:{$node->getLine()}" : "{$scope->getFile()}:{$node->getLine()}";
	}

	/**
	 * @param class-string $class
	 * @param string $method
	 * @return self
	 */
	public function commentOut(string $class, string $method) : self{
		$printer = new Printer();
		$done = 0;
		$total = count($this->parsed_files);
		foreach($this->parsed_files as $path => $file){
			$this->logger->info(
				$this->logger->style("searching ", Logger::STYLE_COLOR_WHITE) .
				$this->logger->style("{$class}::{$method}", Logger::STYLE_COLOR_LIGHT_CYAN) .
				$this->logger->style(" to comment out in ", Logger::STYLE_COLOR_WHITE) .
				$this->logger->style($path, Logger::STYLE_COLOR_WHITE) .
				$this->logger->style(sprintf(" (%.2f%%)", (++$done / $total) * 100), Logger::STYLE_COLOR_CYAN)
			);
			$file->visitMethodCalls($class, $method, function(MethodCall|StaticCall $node, Scope $scope) use($printer){
				$expression = $printer->prettyPrintExpr($node);
				$this->logger->info(sprintf("    commented out %s at %s", $this->logger->style($expression, Logger::STYLE_COLOR_LIGHT_MAGENTA), $this->printLinePos($node, $scope)));
				return new ConstFetch(new Name("/* {$expression} */"));
			});
		}
		return $this;
	}

	public function replaceUQFunctionNamesToFQ() : self{
		$printer = new Printer();
		$done = 0;
		$total = count($this->parsed_files);
		foreach($this->parsed_files as $path => $file){
			$this->logger->info(
				$this->logger->style(sprintf("searching non-fqn fcalls in %s", $path), Logger::STYLE_COLOR_WHITE) .
				$this->logger->style(sprintf(" (%.2f%%)", (++$done / $total) * 100), Logger::STYLE_COLOR_CYAN)
			);
			$file->visit(function(Node $node) use($printer) : ?FuncCall{
				if(
					$node instanceof FuncCall &&
					($namespaced_name = $node->name->getAttribute("namespacedName")) !== null &&
					!function_exists(implode("\\", $namespaced_name->parts)) &&
					function_exists("\\" . implode("\\", $node->name->parts))
				){
					$new = clone $node;
					$new->name = new FullyQualified($node->name->parts);
					$this->logger->info(sprintf("    non-fqn fcall treated as %s", $this->logger->style($printer->prettyPrintExpr($new), Logger::STYLE_COLOR_LIGHT_MAGENTA)));
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
		$printer = new Printer();
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
							$this->logger->info("Replaced isset -> array_key_exists: {$printer->prettyPrintExpr($node)} -> {$printer->prettyPrintExpr($array_key_exists_fcall)} in {$path}");
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
		foreach($this->parsed_files as $path => $file){
			$this->logger->info(
				$this->logger->style("searching ", Logger::STYLE_COLOR_WHITE) .
				$this->logger->style(implode(", ", $types), Logger::STYLE_COLOR_LIGHT_CYAN) .
				$this->logger->style(" types to remove in ", Logger::STYLE_COLOR_WHITE) .
				$this->logger->style($path, Logger::STYLE_COLOR_WHITE) .
				$this->logger->style(sprintf(" (%.2f%%)", (++$done / $total) * 100), Logger::STYLE_COLOR_CYAN)
			);
			$file->visitClassMethods(function(ClassMethod $node, Scope $scope, ClassReflection $class, string $method) use($types){
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
								$this->logger->info(
									"    removed type " .
									$this->logger->style($type_string, Logger::STYLE_COLOR_LIGHT_MAGENTA) .
									" from parameter " .
									$this->logger->style($param->var->name, Logger::STYLE_COLOR_LIGHT_MAGENTA) .
									" of method " .
									$this->logger->style("{$class->getName()}::{$method}", Logger::STYLE_COLOR_LIGHT_MAGENTA)
								);
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
			$this->logger->info(
				$this->logger->style("searching final class getters to optimize in {$path}", Logger::STYLE_COLOR_WHITE) .
				$this->logger->style(sprintf(" (%.2f%%)", (++$done / $total) * 100), Logger::STYLE_COLOR_CYAN)
			);
			$file->visitClassMethods(function(ClassMethod $node, Scope $scope, ClassReflection $class_reflection, string $method) use(&$method_to_property_mapping, &$non_public_properties, $path){
				$class_name = $class_reflection->getName();
				$this->logger->info("    analyzing " . $this->logger->style("{$class_name}::{$method}", Logger::STYLE_COLOR_LIGHT_MAGENTA));
				if(
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
				if(!$property->getDeclaringClass()->is($class_name)){
					// if class has a parent class, the property may not be defined in this class
					return null;
				}

				$method_to_property_mapping["{$class_name}::{$method}"] = [$class_name, $method, $stmt->expr->name->name];
				if(!$property->isPublic()){
					$non_public_properties["{$class_name}::{$stmt->expr->name->name}"] = [$path, $class_name, $stmt->expr->name->name];
				}
				return null;
			});
		}

		$printer = new Printer();
		$done = 0;
		$total = count($non_public_properties);
		foreach($non_public_properties as [$path, $class, $property]){
			++$done;
			$this->parsed_files[$path]->visitWithScope(function(Node $node, Scope $scope) use($class, $property, $done, $total) {
				$class_reflection = $scope->getClassReflection();
				if($class_reflection === null || $class_reflection->getName() !== $class){
					return null;
				}

				if($node instanceof Property){
					// check for non constructor promoted properties
					if($node->props[0]->name->name !== $property){
						return null;
					}
					if(($node->flags & Class_::MODIFIER_PUBLIC) !== 0){ // has public visibility
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
				$this->logger->info(
					"    updated visibility of property " .
					$this->logger->style("{$class}::\${$property}", Logger::STYLE_COLOR_LIGHT_MAGENTA) .
					$this->logger->style(sprintf(" (%.2f%%)", ($done / $total) * 100), Logger::STYLE_COLOR_MAGENTA)
				);
				return $node;
			});
		}

		$done = 0;
		$total = count($method_to_property_mapping);
		foreach($method_to_property_mapping as [$class, $method, $property]){
			$this->logger->info(
				$this->logger->style("searching ", Logger::STYLE_COLOR_WHITE) .
				$this->logger->style("{$class}::{$method}", Logger::STYLE_COLOR_LIGHT_CYAN) .
				$this->logger->style(" method calls to inline in {$path}", Logger::STYLE_COLOR_WHITE) .
				$this->logger->style(sprintf(" (%.2f%%)", (++$done / $total) * 100), Logger::STYLE_COLOR_CYAN)
			);
			foreach($this->parsed_files as $path => $file){
				$file->visitMethodCalls($class, $method, function(MethodCall|StaticCall $node, Scope $scope) use($property, $printer){
					if(!($node instanceof MethodCall)){
						return null;
					}
					$replacement = new PropertyFetch($node->var, $property);
					$this->logger->info(
						"    inlined method call " .
						$this->logger->style($printer->prettyPrintExpr($node), Logger::STYLE_COLOR_LIGHT_MAGENTA) .
						" with " .
						$this->logger->style($printer->prettyPrintExpr($replacement), Logger::STYLE_COLOR_LIGHT_MAGENTA)
					);
					return $replacement;
				});
			}
		}
		return $this;
	}

	/**
	 * @param string $output_folder
	 * @return Generator<string, string>
	 */
	public function exporter(string $output_folder) : Generator{
		is_dir($output_folder) || throw new InvalidArgumentException("Directory {$output_folder} does not exist.");
		$cwd = getcwd();
		$base_path = Path::makeAbsolute($output_folder, $cwd);
		$printer = new Printer();
		foreach($this->parsed_files as $path => $file){
			yield Path::join($base_path, Path::makeRelative($path, $cwd)) => $file->export($printer);
		}
	}

	public function export(string $output_folder, bool $overwrite = false) : void{
		foreach($this->exporter($output_folder) as $path => $contents){
			if(!$overwrite && file_exists($path)){
				$this->logger->warn("failed to write {$path}, file already exists");
				continue;
			}
			$directory = Path::getDirectory($path);
			if(!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)){
				throw new RuntimeException(sprintf("Directory '%s' was not created", $directory));
			}
			if(file_put_contents($path, $contents) === false){
				$this->logger->info("failed to write {$path}");
				continue;
			}
			$this->logger->info("wrote modified {$path}");
		}
	}
}
