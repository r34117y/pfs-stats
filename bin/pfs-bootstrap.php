<?php

declare (strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
$kernel = new App\Kernel($_SERVER['APP_ENV'] ?? 'dev', false);
$kernel->boot();
$connection = $kernel->getContainer()->get('doctrine')->getConnection();
$reporting = error_reporting(E_ALL & ~E_DEPRECATED);
try {
    $connection->connect();
} finally {
    error_reporting($reporting);
}
return $connection;
