# PreProcessor
Comment out debug code before pushing it to production.

## Example Usage

### Preprocess a list of PHP files
```php
require_once "Client.php";
require_once "Server.php";
require_once "Logger.php";

use muqsit\preprocessor\PreProcessor;
use proxy\Logger;

PreProcessor::fromPaths(["src/proxy/Client.php", "src/proxy/Server.php", "src/proxy/Logger.php"]) // files to preprocess
	->commentOut(Logger::class, "debug")
	->export("./preprocessed-src"); // preprocessed files written to ./preprocessed-src folder
```
Result:
```diff
final class Client{

	/** @var Logger */
	private $logger;

	public function onLogin(){
-		$this->logger->debug("Client logged in");
+		/* $this->logger->debug("Client logged in "); */
	}
}
```

### Preprocess a directory
```php
require "vendor/autoload.php";

use muqsit\preprocessor\PreProcessor;
use pocketmine\entity\Entity;
use pocketmine\Player;
use pocketmine\utils\Utils;

PreProcessor::fromDirectory("./src")
	->commentOut(Logger::class, "debug")
	->commentOut(Utils::class, "testValidInstance")
	->commentOut(Utils::class, "validateCallableSignature")
	->commentOut(Client::class, "debugClientStatus")
	->export("./preprocessed-src");
```
Result:
```diff
final class Player extends Client implements LoggerHolder{

	public function onJoin() : void{
-		$this->getLogger()->debug("Player {$this->getUUID()} joined");
+		/* $this->getLogger()->debug("Player {$this->getUUID()} joined"); */

-		$this->sendMessage("You joined the server"); $this->debugClientStatus();
+		$this->sendMessage("You joined the server"); /* $this->debugClientStatus(); */
	}

	public function registerQuitListener(Closure $listener) : void{
-		Utils::validateCallableSignature(function(Player $player) : void{}, $listener);
+		/* Utils::validateCallableSignature(function(Player $player) : void{}, $listener); */
	}
}
```
