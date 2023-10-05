<?php

declare(strict_types=1);

use pocketmine\plugin\PluginBase;

final class SampleLoggerDebug extends PluginBase{

	protected function onEnable() : void{
		$this->getLogger()->debug("Plugin enabled timestamp: " . time());

		$logger = $this->getServer()->getLogger();
		$logger->debug("Logging from {$this->getName()}");

		$child = new PrefixedLogger($this->getLogger(), "Prefix");
		$child->debug("Hello world");
	}
}