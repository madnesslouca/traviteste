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
 * The signed-in viewer, as the bot needs to be told about them.
 *
 * @return array|null ['identity_id','platform','platform_user_id','user_name']
 */
function shop_current_viewer()
{
    $identityId = Core\OAuth\Redirector::currentIdentityId();
    if (!$identityId) {
        return null;
    }
    $stmt = Database\IdentityDB::getInstance()->prepare(
        'SELECT id, provider, provider_user_id, provider_login FROM oauth_identities WHERE id = :id'
    );
    $stmt->execute(['id' => $identityId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return [
        'identity_id'      => (int)$row['id'],
        'platform'         => $row['provider'],
        'platform_user_id' => $row['provider_user_id'],
        'user_name'        => $row['provider_login'],
    ];
}

/**
 * The in-game shop: spends the bot's watch-time points on gold.
 *
 * Points are never held on this side - the balance and the items come from the
 * bot, which is also what debits them.
 */
function handle_shop_page()
{
    global $twig;
    $viewer = shop_current_viewer();
    if (!$viewer) {
        WebService::redirect(Core\OAuth\Redirector::loginUrl());
        exit();
    }
    $bot = new Core\Bot\BotClient();
    $catalogue = new Core\Shop\GoldCatalogue($bot);

    $balance = ['points' => 0, 'currency_name' => 'pontos'];
    $items = [];
    $botError = null;
    try {
        $balance = $bot->getBalance($viewer['platform'], $viewer['platform_user_id'], $viewer['user_name']);
        $items = $catalogue->listGoldItems();
    } catch (Throwable $e) {
        //The stream bot being down must not take the page with it: show what we
        //can and say plainly that the shop is unavailable right now.
        $botError = $e->getMessage();
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $twig->render('shop.html.twig', [
        'title'     => 'Shop',
        'balance'   => $balance['points'],
        'currency'  => $balance['currency_name'],
        'items'     => $items,
        'history'   => $catalogue->recentDeliveries($viewer['identity_id']),
        'csrf'      => shop_csrf_token(),
        'botError'  => $botError,
        'error'     => isset($_GET['error']) ? substr((string)$_GET['error'], 0, 200) : null,
        'bought'    => isset($_GET['bought']) ? (int)$_GET['bought'] : 0,
    ]);
    exit();
}

function handle_shop_buy()
{
    $viewer = shop_current_viewer();
    if (!$viewer) {
        WebService::redirect(Core\OAuth\Redirector::loginUrl());
        exit();
    }
    try {
        //Without this a third-party page could spend a signed-in player's points
        //by posting to this endpoint on their behalf.
        $given = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
        if (!hash_equals(shop_csrf_token(), $given)) {
            throw new RuntimeException('Your session expired. Please try again.');
        }
        $itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
        $bot = new Core\Bot\BotClient();
        $catalogue = new Core\Shop\GoldCatalogue($bot);
        $gold = $catalogue->goldFor($itemId);

        //The bot debits and records the redemption in one transaction; there is
        //nothing to undo here if the delivery below fails.
        $bot->purchase($viewer['platform'], $viewer['platform_user_id'], $viewer['user_name'], $itemId);

        //Deliver straight away so buying in-game feels immediate. If this throws,
        //the redemption stays pending and the scheduled consumer picks it up.
        try {
            (new Core\Shop\RedemptionConsumer($bot))->run(50);
        } catch (Throwable $e) {
            error_log('redemption delivery after purchase failed: ' . $e->getMessage());
        }
        WebService::redirect('/shop/?bought=' . $gold);
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