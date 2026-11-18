<?php

declare(strict_types=1);

// Under the PHP dev server there is no Apache to serve real files directly
// (.htaccess RewriteCond !-f), so hand them back before touching the router.
// Decode first (percent-encoded asset names), then resolve and require the
// result to be a non-PHP file inside public/ — so traversal paths and a
// direct hit on migrate.php fall through to the router's 404 instead.
if (PHP_SAPI === 'cli-server') {
    $staticPath = rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));
    $staticFile = $staticPath === '/' ? false : realpath(__DIR__ . $staticPath);

    if (
        $staticFile !== false
        && is_file($staticFile)
        && str_starts_with($staticFile, __DIR__ . DIRECTORY_SEPARATOR)
        && !str_ends_with(strtolower($staticFile), '.php')
    ) {
        return false;
    }
}

// Private app: stay out of search engines. .htaccess sets the same header,
// but on IONOS that only reaches Apache-served responses (static files, the
// old-host 301) — the FastCGI layer drops .htaccess headers from PHP
// responses, so every routed page sends it here itself.
header('X-Robots-Tag: noindex, nofollow');

use App\Models\UserModel;
use App\Services\SlackOpsNotifier;
use App\Support\Auth;
use App\Support\Database;
use App\Support\Session;
use App\Support\SlackClient;
use App\Support\View;
use FastRoute\Dispatcher;

use function FastRoute\simpleDispatcher;

$config = require dirname(__DIR__) . '/config/bootstrap.php';

// Last-resort handler: display_errors is off in production, so an uncaught
// Throwable used to be a blank page that nobody heard about. Answer with a
// clean 500 and (best effort) DM the ops users — at most one DM per hour,
// deduped through slack_notice_log so a crash loop can't flood Slack.
set_exception_handler(static function (\Throwable $e) use ($config): void {
    http_response_code(500);

    $path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
    $isApiPath = str_starts_with($path, '/api/')
        || $path === '/mcp'
        || str_starts_with($path, '/mcp/')
        || str_starts_with($path, '/slack/')
        || str_starts_with($path, '/cron/');

    if ($isApiPath) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => [
            'code' => 'internal',
            'message' => $config->isLocal() ? $e->getMessage() : 'Internal error.',
        ]]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><title>Rolo</title>'
            . '<h1>Something went wrong</h1><p>The error has been reported.</p>';

        if ($config->isLocal()) {
            echo '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES) . '</pre>';
        }
    }

    try {
        // One DM per hour: the database being unreachable is itself a top
        // thing to report, so a failed dedupe check sends rather than stays
        // silent (the flood risk is bounded by this app's two users).
        $fresh = true;

        try {
            $stmt = (new Database($config))->pdo()->prepare(
                'INSERT IGNORE INTO slack_notice_log (kind, notice_date, slack_user)
                 VALUES (:kind, CURDATE(), :user)'
            );
            $stmt->execute([
                'kind' => 'error-' . (new DateTimeImmutable('now', $config->appTimezone))->format('H'),
                'user' => 'ops',
            ]);
            $fresh = $stmt->rowCount() > 0;
        } catch (\Throwable) {
            // Dedupe unavailable: report anyway.
        }

        if ($fresh) {
            (new SlackOpsNotifier($config, new SlackClient($config)))->notify(sprintf(
                ':rotating_light: Rolo 500: %s: %s (%s %s)',
                $e::class,
                mb_substr($e->getMessage(), 0, 300),
                (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
                $path,
            ));
        }
    } catch (\Throwable) {
        // Reporting a crash must never become a second crash.
    }
});

$httpMethod = $_SERVER['REQUEST_METHOD'];
$uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// The agent API (REST, MCP) and the Slack webhook are stateless: token or
// signature auth inside their controllers, no session cookie, JSON errors
// instead of HTML pages.
$isApi = str_starts_with($uri, '/api/')
    || $uri === '/mcp'
    || str_starts_with($uri, '/mcp/')
    || str_starts_with($uri, '/slack/');

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
    '/cron/slack-debrief', // guarded by CRON_SECRET, not a session
    '/cron/deployed', // guarded by CRON_SECRET, not a session
    '/privacy', // the OAuth consent screen links here; must load signed out
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
            // Remember where a signed-out GET was headed (the Slack Open
            // button, a bookmark), so sign-in resumes there instead of
            // dumping the person on the dashboard. A POST can't be resumed.
            $next = '';

            if ($httpMethod === 'GET' && $uri !== '/') {
                $query = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY);
                $next = '?next=' . rawurlencode($uri . ($query !== '' ? '?' . $query : ''));
            }

            header('Location: /login' . $next, true, 302);
            break;
        }

        [$controllerClass, $method] = $routeInfo[1];
        $controller = new $controllerClass($config, $database, $auth);
        echo $controller->{$method}($routeInfo[2]);
        break;
}
