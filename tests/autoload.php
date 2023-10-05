<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

require_once "../vendor/autoload.php";

$loader = new ClassLoader();
$loader->add("muqsit\\preprocessor", __DIR__, true);
$loader->register();