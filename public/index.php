<?php

declare(strict_types=1);

use App\Models\UserModel;
use App\Support\Auth;
use App\Support\Database;
use App\Support\Session;
use App\Support\View;
use FastRoute\Dispatcher;

use function FastRoute\simpleDispatcher;

$config = require dirname(__DIR__) . '/config/bootstrap.php';

$httpMethod = $_SERVER['REQUEST_METHOD'];
$uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// The agent API (REST and MCP) is stateless: bearer-token auth inside its
// controllers, no session cookie, JSON errors instead of HTML pages.
$isApi = str_starts_with($uri, '/api/') || $uri === '/mcp' || str_starts_with($uri, '/mcp/');

if (!$isApi) {
    Session::start($config);
}

$database = new Database($config);
$auth = new Auth(new UserModel($database));

// Everything except signing in requires a session (Google Sign-In in
// production, the dev stub locally — see docs/SCHEMA.md Authentication).
$publicRoutes = [
    '/login',
    '/auth/dev-login',
    '/auth/google',
    '/auth/google/callback',
    '/cron/daily-summary', // guarded by CRON_SECRET, not a session
];

$jsonError = static function (int $status, string $code, string $message): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ['code' => $code, 'message' => $message]]);
};

$dispatcher = simpleDispatcher(require dirname(__DIR__) . '/config/routes.php');

$routeInfo = $dispatcher->dispatch($httpMethod, $uri);

switch ($routeInfo[0]) {
    case Dispatcher::NOT_FOUND:
        if ($isApi) {
            $jsonError(404, 'not_found', 'Unknown API endpoint.');
            break;
        }

        http_response_code(404);
        echo View::render('errors/404');
        break;

    case Dispatcher::METHOD_NOT_ALLOWED:
        header('Allow: ' . implode(', ', $routeInfo[1]));

        if ($isApi) {
            $jsonError(405, 'method_not_allowed', 'Method not allowed for this endpoint.');
            break;
        }

        http_response_code(405);
        echo View::render('errors/404');
        break;

    case Dispatcher::FOUND:
        if (!$isApi && !in_array($uri, $publicRoutes, true) && !$auth->check()) {
            header('Location: /login', true, 302);
            break;
        }

        [$controllerClass, $method] = $routeInfo[1];
        $controller = new $controllerClass($config, $database, $auth);
        echo $controller->{$method}($routeInfo[2]);
        break;
}
