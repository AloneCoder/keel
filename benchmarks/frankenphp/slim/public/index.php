<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Slim\Factory\AppFactory;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$psr17 = new Psr17Factory();
AppFactory::setResponseFactory($psr17);
$app = AppFactory::create();
$app->get('/json/{id:\d+}', static function ($request, $response, array $args) {
    $response->getBody()->write(json_encode(['id' => (int) $args['id'], 'name' => 'item ' . $args['id'], 'ok' => true]));

    return $response->withHeader('Content-Type', 'application/json');
});
$creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);

$handler = static function () use ($app, $creator): void {
    $response = $app->handle($creator->fromGlobals());
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header("$name: $value", false);
        }
    }
    echo $response->getBody();
};

while (frankenphp_handle_request($handler)) {
    gc_collect_cycles();
}
