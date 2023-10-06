<?php

declare(strict_types=1);

namespace muqsit\preprocessor;

use Closure;
use Logger;
use PrefixedLogger;
use pocketmine\entity\Entity;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Utils;
use Ramsey\Uuid\Uuid;

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

		$l = $this->l1;
		$l->info("x"); $l->debug("y"); $l->notice("z");
	}

	/**
	 * @param Closure(Player) : bool $listener
	 */
	public function registerPlayerListener(Closure $listener) : void{
		Utils::validateCallableSignature(static fn(Player $player) : bool => true, $listener);
		$listener($this->getServer()->getPlayerByUUID(Uuid::fromString(Uuid::NIL)));
	}

	/**
	 * @param Closure(Entity) : bool $listener
	 */
	public function registerEntityListener(Closure $listener) : void{
		$utils = Utils::class;
		$utils::validateCallableSignature(static fn(Entity $entity) : bool => true, $listener);
		$listener($this->getServer()->getWorldManager()->findEntity(0));
	}
}