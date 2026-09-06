<?php

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use FastRoute\Dispatcher;

require_once __DIR__ . '/src/components/Log.php';

// Не показывать ошибки клиентам: пишем в лог и отдаём JSON 500
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('log_error_initialization', '1');
ini_set('log_error_file', __DIR__ . '/logs/php_errors.log');

set_exception_handler(function (Throwable $e): void {
    $log = \Api\components\Log::get('debug');
    $log->error('Unhandled exception', [
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'errors' => ['500 internal'],
        ], JSON_UNESCAPED_UNICODE);
    }

    exit;
});

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    http_response_code(204);
    exit;
}

$request = Request::createFromGlobals();

// Initialize configuration, JWT and Database
require_once __DIR__ . '/src/Configurator.php';
require_once __DIR__ . '/src/JwtHelper.php';
require_once __DIR__ . '/src/db/DatabaseManager.php';

$config = \Api\Configurator::load();
\Api\JwtHelper::init($config);
\Api\db\DatabaseManager::boot();

$dispatcher = require __DIR__.'/src/routes/api.php';

$routeInfo = $dispatcher->dispatch(
    $request->getMethod(),
    $request->getPathInfo()
);

switch ($routeInfo[0]) {

    case Dispatcher::NOT_FOUND:

        http_response_code(404);
        echo '404';
        break;

    case Dispatcher::METHOD_NOT_ALLOWED:

        http_response_code(405);
        echo 'Method Not Allowed';
        break;

    case Dispatcher::FOUND:

        if (is_array($routeInfo[1])) {
            $handler = $routeInfo[1]['handler'];
        } else {
            $handler = $routeInfo[1];
        }

        if (is_array($routeInfo[1]) && !empty($routeInfo[1]['middleware']) && is_callable($routeInfo[1]['middleware'])) {
            $middlewareResult = call_user_func_array($routeInfo[1]['middleware'], [$request]);

            if ($middlewareResult !== true) {
                $middlewareResult->send();
                break;
            }
        }

        $vars = $routeInfo[2];

        if (is_callable($handler)) {
            // Route is a closure (like /user/profile)
            $response = $handler($request, ...array_values($vars));
            $response->send();
        } else {
            // Route is controller@method
            [$controllerName, $method] = explode('@', $handler);

            $class = "Api\\app\\controllers\\{$controllerName}";

            $controller = new $class();
            $response = $controller->$method(
                $request,
                ...array_values($vars)
            );

            $response->send();
        }

        break;
}
