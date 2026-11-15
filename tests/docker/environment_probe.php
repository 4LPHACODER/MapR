<?php

use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

// Run only in a disposable checkout with synthetic Docker environment values.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->bootstrapWith([
    LoadEnvironmentVariables::class,
    LoadConfiguration::class,
]);

$expected = [
    'database.connections.mysql.host' => 'db',
    'database.connections.mysql.database' => 'mapr_review',
    'database.connections.mysql.username' => 'review',
    'database.connections.mysql.password' => 'review-only-password',
    'database.connections.mysql.url' => null,
    'session.domain' => null,
    'app.key' => 'base64:'.base64_encode(str_repeat('r', 32)),
];
foreach ($expected as $key => $value) {
    if ($app['config']->get($key) !== $value) {
        fwrite(STDERR, "Unexpected configuration: {$key}\n");
        exit(1);
    }
}
if ($app->environmentPath() !== '/run/mapr') {
    fwrite(STDERR, "Unexpected Laravel environment directory\n");
    exit(1);
}
echo 'Docker environment isolated; cached='.($app->configurationIsCached() ? 'yes' : 'no').PHP_EOL;
