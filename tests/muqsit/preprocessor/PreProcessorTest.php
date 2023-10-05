<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

use PHPUnit\Framework\TestCase;
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
		$processor = $this->buildPreProcessorForSample("logger-debug-method-call.php");
		$processor->commentOut(\Logger::class, "debug");
		$this->assertDiff($processor, [
			[15, "\t\t/* \$this->getLogger()->debug(\"Plugin enabled timestamp: \" . \\time()) */;"],
			[18, "\t\t/* \$logger->debug(\"Logging from {\$this->getName()}\") */;"],
			[23, "\t\t/* \$child->debug(\"Hello world\") */;"],
			[25, "\t\t/* \$this->l1->debug(\"test phpdoc typed property\") */;"],
			[26, "\t\t/* \$this->l2->debug(\"test native typed property\") */;"]
		]);
	}
}