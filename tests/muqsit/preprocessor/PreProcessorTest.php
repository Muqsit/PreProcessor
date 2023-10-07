<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

use PHPUnit\Framework\TestCase;
use pocketmine\utils\Utils;
use Symfony\Component\Filesystem\Path;
use function explode;
use function file_get_contents;
use function getcwd;
use function implode;
use const PHP_EOL;

final class PreProcessorTest extends TestCase{

	private function buildPreProcessorForSample(string $file) : PreProcessor{
		$processor = PreProcessor::fromPaths([Path::join(__DIR__, "samples", $file)]);
		$processor->logger->levels = [];
		return $processor;
	}

	/**
	 * @param PreProcessor $processor
	 * @param list<array{int, string}> $differences
	 */
	private function assertDiff(PreProcessor $processor, array $differences) : void{
		foreach($processor->exporter(getcwd()) as $path => $changed){
			$original = explode(PHP_EOL, file_get_contents($processor->parsed_files[$path]->file->getPathname()));
			foreach($differences as [$line, $replacement]){
				self::assertNotEquals($original[$line - 1], $replacement);
				$original[$line - 1] = $replacement;
			}
			self::assertEquals($changed, implode(PHP_EOL, $original));
		}
	}

	public function testCommentOutMethodCalls() : void{
		$processor = $this->buildPreProcessorForSample("comment-out-method-call.php");
		$processor->commentOut(\Logger::class, "debug"); // non-static method
		$processor->commentOut(Utils::class, "validateCallableSignature"); // static method
		$this->assertDiff($processor, [
			[24, "\t\t/* \$this->getLogger()->debug(\"Plugin enabled timestamp: \" . time()) */;"],
			[27, "\t\t/* \$logger->debug(\"Logging from {\$this->getName()}\") */;"],
			[32, "\t\t/* \$child->debug(\"Hello world\") */;"],
			[34, "\t\t/* \$this->l1->debug(\"test phpdoc typed property\") */;"],
			[35, "\t\t/* \$this->l2->debug(\"test native typed property\") */;"],
			[38, "\t\t\$l->info(\"x\"); /* \$l->debug(\"y\") */; \$l->notice(\"z\");"],
			[45, "\t\t/* \\pocketmine\\utils\\Utils::validateCallableSignature(static fn(\\pocketmine\\player\\Player \$player): bool => true, \$listener) */;"],
			[54, "\t\t/* \$utils::validateCallableSignature(static fn(\\pocketmine\\entity\\Entity \$entity): bool => true, \$listener) */;"]
		]);
	}

	public function testReplaceUnqualifiedFunctionCallsWithFullyQualified() : void{
		$processor = $this->buildPreProcessorForSample("uq-fcalls.php");
		$processor->replaceUQFunctionNamesToFQ();
		$this->assertDiff($processor, [
			[34, "\t\t\t\cos(\$this->y) * \cos(\$this->z) * \$this->radius, ((\sin(\$this->x) * \sin(\$this->y) * \cos(\$this->z)) - (\cos(\$this->x) * \sin(\$this->z))) * \$this->radius, ((\cos(\$this->x) * \sin(\$this->y) * \cos(\$this->z)) + (\sin(\$this->x) * \sin(\$this->z))) * \$this->radius,"],
			[35, "\t\t\t\cos(\$this->y) * \sin(\$this->z) * \$this->radius, ((\sin(\$this->x) * \sin(\$this->y) * \sin(\$this->z)) + (\cos(\$this->x) * \cos(\$this->z))) * \$this->radius, ((\cos(\$this->x) * \sin(\$this->y) * \sin(\$this->z)) - (\sin(\$this->x) * \cos(\$this->z))) * \$this->radius,"],
			[36, "\t\t\t-\sin(\$this->y) * \$this->radius, \sin(\$this->x) * \cos(\$this->y) * \$this->radius, \cos(\$this->x) * \cos(\$this->y) * \$this->radius"],
			[48, "\t\t\\assert(!empty(\$mat));"],
			[50, "\t\t\t\$x = \cos(\$d * \$i);"],
			[51, "\t\t\t\$z = \sin(\$d * \$i);"]
		]);
	}
}