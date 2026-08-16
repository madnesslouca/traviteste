<?php
use Api\ApiDispatcher;
use Core\WebService;
require "include/bootstrap.php";
function handle_v1_api($section, $action)
{
    $response = ['success' => true, 'error' => ['errorType' => null, 'errorMsg' => null]];
    $payload = json_decode(file_get_contents('php://input'), true);
    try {
        new ApiDispatcher($response, $payload, $section, $action);
        http_response_code(200);
    } catch (Exception $e) {
        $response['success'] = false;
        $response['error'] = [
            'errorType' => get_class($e),
            'errorMsg' => $e->getMessage(),
        ];
        http_response_code(400);
    }
    header("Content-Type: application/json");
    header("Access-Control-Allow-Origin: *");
    echo json_encode($response);
    exit();
}
function handle_v1_api_action($action)
{
    handle_v1_api(null, $action);
}

/**
 * OAuth is browser-facing, not JSON: the provider redirects the user back here,
 * so these routes answer with 302s and an HTML error page rather than going
 * through ApiDispatcher (which also demands a `lang` payload we do not have).
 */
function handle_oauth_start($provider)
{
    try {
        $flow = new Core\OAuth\Redirector();
        WebService::redirect($flow->start(
            $provider,
            isset($_GET['world']) ? (int)$_GET['world'] : 0
        ));
    } catch (Throwable $e) {
        oauth_error_page($e->getMessage());
    }
    exit();
}

function handle_oauth_callback($provider)
{
    try {
        $flow = new Core\OAuth\Redirector();
        WebService::redirect($flow->callback(
            $provider,
            isset($_GET['code']) ? $_GET['code'] : null,
            isset($_GET['state']) ? $_GET['state'] : null,
            isset($_GET['error_description']) ? $_GET['error_description'] : (isset($_GET['error']) ? $_GET['error'] : null)
        ));
    } catch (Throwable $e) {
        oauth_error_page($e->getMessage());
    }
    exit();
}

/**
 * The sign-in page.
 *
 * It lives here rather than in the Angular lobby because that lobby ships as a
 * minified bundle with no sources in the repository, so it cannot be extended.
 */
function handle_login_page()
{
    global $twig;
    $worlds = Core\Server::getGameWorldsListForRegistration(true);
    header('Content-Type: text/html; charset=utf-8');
    //Nothing here is cacheable: the provider list and the open worlds both
    //change without the page being touched.
    header('Cache-Control: no-store');
    echo $twig->render('login.html.twig', [
        'title'     => 'Sign in',
        'worlds'    => $worlds,
        'providers' => Core\OAuth\ProviderFactory::configured(),
        'error'     => isset($_GET['error']) ? substr((string)$_GET['error'], 0, 200) : null,
    ]);
    exit();
}

/**
 * Server-to-server coin API, authenticated with a shared token rather than a
 * user session: the caller is the watch-time system, not a browser.
 */
function handle_coins_api($action)
{
    header('Content-Type: application/json');
    try {
        Core\Coins\CoinsApi::assertAuthorised();
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Request body must be a JSON object.');
        }
        $api = new Core\Coins\CoinsApi();
        if (!in_array($action, ['credit', 'balance'], true)) {
            throw new RuntimeException('Unknown action.');
        }
        echo json_encode(['success' => true, 'data' => $api->$action($payload)]);
    } catch (Throwable $e) {
        //401 only for the token itself; a bad payload is the caller's mistake,
        //not an authentication problem.
        http_response_code($e->getMessage() === 'Unauthorised.' ? 401 : 400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

/** The shop: spend watch-time coins on in-game gold. */
function handle_shop_page()
{
    global $twig;
    $identityId = Core\OAuth\Redirector::currentIdentityId();
    if (!$identityId) {
        WebService::redirect(Core\OAuth\Redirector::loginUrl());
        exit();
    }
    $shop = new Core\Shop\ShopService();
    $identities = new Core\IdentityRepository();
    $worldId = Core\OAuth\Redirector::currentWorldId();

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $twig->render('shop.html.twig', [
        'title'    => 'Shop',
        'balance'  => $identities->getBalance($identityId),
        'packages' => $shop->getPackages(),
        'orders'   => $shop->getOrders($identityId),
        'world'    => $worldId,
        'csrf'     => shop_csrf_token(),
        'error'    => isset($_GET['error']) ? substr((string)$_GET['error'], 0, 200) : null,
        'bought'   => isset($_GET['bought']) ? (int)$_GET['bought'] : 0,
    ]);
    exit();
}

function handle_shop_buy()
{
    $identityId = Core\OAuth\Redirector::currentIdentityId();
    if (!$identityId) {
        WebService::redirect(Core\OAuth\Redirector::loginUrl());
        exit();
    }
    try {
        //Without this a third-party page could spend a signed-in player's coins
        //by posting to this endpoint on their behalf.
        $given = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
        if (!hash_equals(shop_csrf_token(), $given)) {
            throw new RuntimeException('Your session expired. Please try again.');
        }
        $shop = new Core\Shop\ShopService();
        $order = $shop->purchase(
            $identityId,
            isset($_POST['package']) ? (string)$_POST['package'] : '',
            Core\OAuth\Redirector::currentWorldId()
        );
        WebService::redirect('/shop/?bought=' . (int)$order['gold_amount']);
    } catch (Throwable $e) {
        WebService::redirect('/shop/?error=' . rawurlencode($e->getMessage()));
    }
    exit();
}

function shop_csrf_token()
{
    Core\OAuth\Redirector::startSession();
    if (empty($_SESSION['shop_csrf'])) {
        $_SESSION['shop_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['shop_csrf'];
}

function oauth_error_page($message)
{
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $back = htmlspecialchars(Core\OAuth\Redirector::loginUrl(), ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!doctype html>
<meta charset="utf-8">
<title>Sign-in failed</title>
<style>
  body{background:#14161a;color:#e8e6e3;font:16px/1.6 system-ui,sans-serif;display:grid;place-items:center;height:100vh;margin:0}
  .box{max-width:34rem;padding:2rem;background:#1c1f26;border:1px solid #2c313c;border-radius:12px}
  h1{margin:0 0 .5rem;font-size:1.25rem}
  p{margin:0 0 1.25rem;color:#aab}
  a{color:#9b7cff}
</style>
<div class="box">
  <h1>Sign-in failed</h1>
  <p>{$safe}</p>
  <a href="{$back}">Back to sign in</a>
</div>
HTML;
}

$dispatcher = FastRoute\cachedDispatcher(function (FastRoute\RouteCollector $r) {
    $r->addRoute('GET', '/login', 'handle_login_page');
    $r->addRoute('GET', '/login/', 'handle_login_page');
    $r->addRoute('GET', '/shop', 'handle_shop_page');
    $r->addRoute('GET', '/shop/', 'handle_shop_page');
    $r->addRoute('POST', '/shop/buy', 'handle_shop_buy');
    $r->addRoute('POST', '/v1/coins/{action}', 'handle_coins_api');
    $r->addRoute('GET', '/v1/oauth/{provider}/start', 'handle_oauth_start');
    $r->addRoute('GET', '/v1/oauth/{provider}/callback', 'handle_oauth_callback');
    $r->addRoute(['GET', 'POST', 'OPTIONS'], '/v1/{section}/{action}', 'handle_v1_api');
    $r->addRoute(['GET', 'POST', 'OPTIONS'], '/v1/{action}', 'handle_v1_api_action');
}, [
    'cacheFile' => __DIR__ . '/include/route.cache', /* required */
    'cacheDisabled' => true,     /* optional, enabled by default */
]);
// Fetch method and URI from somewhere
$httpMethod = strtoupper($_SERVER['REQUEST_METHOD']);
$uri = $_SERVER['REQUEST_URI'];
// Strip query string (?foo=bar) and decode URI
if (false !== $pos = strpos($uri, '?')) {
    $uri = substr($uri, 0, $pos);
}
$uri = rawurldecode($uri);
$routeInfo = $dispatcher->dispatch($httpMethod, $uri);
if ($httpMethod == 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: X-Requested-With, Content-Type, Origin, Authorization, Accept, Client-Security-Token, Accept-Encoding');
    http_response_code(200);
    return;
}
switch ($routeInfo[0]) {
    case FastRoute\Dispatcher::NOT_FOUND:
        http_response_code(404);
        break;
    case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
        $allowedMethods = $routeInfo[1];
        // ... 405 Method Not Allowed
        http_response_code(405);
        break;
    case FastRoute\Dispatcher::FOUND:
        $handler = $routeInfo[1];
        $vars = $routeInfo[2];
        // ... call $handler with $vars
        call_user_func_array($handler, $vars);
        break;
}