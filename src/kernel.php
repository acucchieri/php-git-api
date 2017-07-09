<?php

use GitApi\Git\Client;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/*
 * Config
 */
require_once __DIR__.'/../etc/config.php';

/*
 * Routes
 */
require_once __DIR__.'/../etc/routes.php';

/*
 * Services
 */
$app['git.client'] = function ($app) {
    return new GitApi\Git\Client($app['git'], $app['url_generator']);
};

/*
 * Logger
 */
$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.level' => ('prod' === $app['env']) ? Logger::ERROR : Logger::DEBUG,
    'monolog.logfile' => __DIR__.'/../var/logs/app.log',
));

/*
 * Error handler
 */
$app->error(function (\Exception $e, Request $request, $code) use ($app) {
    $code = ($code) ?: 500;
    switch ($code) {
        case 403:
            $message = ($e->getMessage()) ?: 'Forbidden';
            break;

        case 404:
            $message = ($e->getMessage()) ?: 'Not Found';
            break;

        default:
            $message = 'Oops! something went wrong';
            break;
    }

    return new JsonResponse(['code' => $code, 'message' => $message], $code);
});
