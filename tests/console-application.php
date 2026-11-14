<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;

// PHPStan's Symfony extension boots the console to learn command signatures and the container's
// service types. Dev rather than test, because the dev container is the one with every service
// wired — the test environment swaps a few for doubles.
require __DIR__.'/../vendor/autoload.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__.'/../.env');

$kernel = new Kernel('dev', true);
$kernel->boot();

return new Application($kernel);
