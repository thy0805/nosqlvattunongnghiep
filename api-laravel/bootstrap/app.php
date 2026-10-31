<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$nosqlApp = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \App\Http\Middleware\ValidateBearerToken::class,
        );
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') || $request->is('logout') ? null : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('logout') || $request->expectsJson(),
        );
    })->create();

$nosqlApp->bind(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, \App\Infrastructure\Mongo\StrictConfiguration::class);
$nosqlApp->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function (Application $app): void {
    \App\Infrastructure\Mongo\Isolation::validate($app['config']->get('nosql', []));
    if (PHP_SAPI === 'cli' && in_array(basename($_SERVER['argv'][0] ?? ''), ['artisan', 'c00-cli.php'], true)
        && !in_array($_SERVER['argv'][1] ?? 'list', ['about', 'list', 'package:discover', 'route:list', 'nosql:check', 'config:cache', 'config:clear', 'alerts:scan'], true)) {
        throw new \RuntimeException('NOSQL_C00_COMMAND_DISABLED');
    }
    if (array_keys($app['config']->get('database.connections', [])) !== ['mongodb']
        || $app['config']->get('database.default') !== 'mongodb'
        || $app['config']->get('database.connections.mongodb.driver') !== 'mongodb'
        || $app['config']->get('database.connections.mongodb.dsn') !== $app['config']->get('nosql.uri')
        || $app['config']->get('database.connections.mongodb.database') !== $app['config']->get('nosql.database')
        || $app['config']->get('mongodb.uri') !== $app['config']->get('nosql.uri')
        || $app['config']->get('mongodb.database') !== $app['config']->get('nosql.database')
        || array_diff(array_keys($app['config']->get('database.connections.mongodb', [])), ['driver', 'dsn', 'database', 'options'])
        || $app['config']->get('database.connections.mongodb.options') !== ['appName' => 'nosql186-laravel', 'serverSelectionTimeoutMS' => 2500]) {
        throw new \RuntimeException('NOSQL_CONNECTION_MAP_REJECTED');
    }
});

return $nosqlApp;
