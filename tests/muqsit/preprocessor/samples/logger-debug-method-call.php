<?php

declare(strict_types=1);

use pocketmine\plugin\PluginBase;

final class SampleLoggerDebug extends PluginBase{

	/** @var Logger */
	private $l1;

	private Logger $l2;

	protected function onEnable() : void{
		$this->getLogger()->debug("Plugin enabled timestamp: " . time());

		$logger = $this->getServer()->getLogger();
		$logger->debug("Logging from {$this->getName()}");

		$logger->notice("This isn't a debug message");

		$child = new PrefixedLogger($this->getLogger(), "Prefix");
		$child->debug("Hello world");

		$this->l1->debug("test phpdoc typed property");
		$this->l2->debug("test native typed property");
	}
}