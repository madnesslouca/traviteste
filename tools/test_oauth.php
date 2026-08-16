<?php
/**
 * Checks the OAuth sign-in flow without talking to Twitch or Kick.
 *
 * Everything after the provider hands back a profile is ours, and it is the only
 * way into the game now that password sign-in is gone. This exercises that half
 * directly: state handling, PKCE derivation, first-time registration, resolving
 * the user id the game assigns later, and the handshake.
 *
 * What it cannot cover is the network round trip itself - the token exchange and
 * the profile parsing - which still needs one real sign-in to confirm.
 *
 * Usage: docker compose run --rm -T php php /travian/tools/test_oauth.php
 */
require "/travian/sections/api/include/bootstrap.php";

use Core\IdentityRepository;
use Core\OAuth\AbstractProvider;
use Core\OAuth\KickProvider;
use Core\OAuth\LoginFlow;
use Core\OAuth\TwitchProvider;
use Core\Server;
use Database\ServerDB;

$failures = 0;

function check($label, $actual, $expected)
{
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) {
        $failures++;
    }
    printf("  %s %-48s got %s, expected %s\n",
        $ok ? "\033[0;32mok\033[0m  " : "\033[0;31mFAIL\033[0m",
        $label,
        var_export($actual, true),
        var_export($expected, true));
}

function truthy($label, $actual)
{
    check($label, (bool)$actual, true);
}

$identities = new IdentityRepository();
$flow = new LoginFlow($identities);

$worlds = Server::getGameWorldsListForRegistration(true);
if (!sizeof($worlds)) {
    exit("No open game world to test against.\n");
}
$worldId = (int)$worlds[0]['id'];
$server = Server::getServerById($worldId);
$serverDB = ServerDB::getInstance($server['configFileLocation']);

$suffix = bin2hex(random_bytes(4));

echo "\nWorld {$worldId} ({$server['worldId']})\n";

// ------------------------------------------------------------------ PKCE
echo "\nPKCE\n";
$verifier = AbstractProvider::generateCodeVerifier();
check('verifier length is within RFC 7636 (43-128)',
    strlen($verifier) >= 43 && strlen($verifier) <= 128, true);
check('verifier is URL safe', preg_match('/^[A-Za-z0-9\-._~]+$/', $verifier) === 1, true);

$kick = new KickProvider('cid', 'secret', 'http://localhost:8080/v1/oauth/kick/callback');
$twitch = new TwitchProvider('cid', 'secret', 'http://localhost:8080/v1/oauth/twitch/callback');
check('kick requires PKCE', $kick->requiresPkce(), true);
check('twitch does not', $twitch->requiresPkce(), false);

$url = $kick->getAuthorizationUrl('state123', $verifier);
parse_str(parse_url($url, PHP_URL_QUERY), $params);
//The challenge must be the base64url of the SHA-256 of the verifier: get this
//wrong and Kick rejects every single sign-in at the token exchange.
$expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
check('challenge is base64url(sha256(verifier))', $params['code_challenge'], $expectedChallenge);
check('challenge method', $params['code_challenge_method'], 'S256');
check('kick scope', $params['scope'], 'user:read');

$twitchUrl = $twitch->getAuthorizationUrl('state123');
parse_str(parse_url($twitchUrl, PHP_URL_QUERY), $twitchParams);
check('twitch scope asks for the e-mail', $twitchParams['scope'], 'user:read:email');
check('twitch sends no challenge', isset($twitchParams['code_challenge']), false);

// ------------------------------------------------------------------ state
echo "\nState\n";
$state = $identities->createState('kick', $verifier, $worldId);
check('state is long enough to be unguessable', strlen($state) >= 32, true);

$round = $identities->consumeState($state, 'kick');
truthy('state can be consumed once', $round);
check('verifier survives the round trip', $round['code_verifier'], $verifier);
check('world survives the round trip', (int)$round['world_unique_id'], $worldId);

check('the same state cannot be replayed', $identities->consumeState($state, 'kick'), false);

$state2 = $identities->createState('kick', $verifier, $worldId);
check('a state cannot be used by another provider', $identities->consumeState($state2, 'twitch'), false);
$identities->consumeState($state2, 'kick');

// ------------------------------------------------------------ first sign-in
echo "\nFirst sign-in (no account yet)\n";
$profile = [
    'id'     => 'tw_' . $suffix,
    'login'  => 'viewer' . $suffix,
    'name'   => 'Viewer ' . $suffix,
    'email'  => "viewer{$suffix}@example.invalid",
    'avatar' => null,
];
$result = $flow->enter('twitch', $profile, $worldId);
check('sent to activation', strpos($result['url'], 'activate.php?token=') !== false, true);
check('no game user yet', $result['game_uid'], 0);
truthy('identity was created', $result['identity_id']);

$link = $identities->findGameAccount($result['identity_id'], $worldId);
truthy('username was reserved', $link);
check('username derives from the provider login', $link['username'], 'viewer' . $suffix);
check('uid still pending', $link['game_uid'], null);

$activationCount = (int)$serverDB->query(
    "SELECT COUNT(id) FROM activation WHERE name='" . $link['username'] . "'")->fetchColumn();
check('activation row created in the game', $activationCount, 1);

echo "\nSigning in again before activating\n";
$again = $flow->enter('twitch', $profile, $worldId);
check('still sent to activation', strpos($again['url'], 'activate.php?token=') !== false, true);
$link2 = $identities->findGameAccount($result['identity_id'], $worldId);
check('keeps the same reserved username', $link2['username'], $link['username']);

// --------------------------------------------------- after the game creates it
echo "\nAfter the player activates\n";
//Stand in for activate.php, which is what normally turns an activation row into
//a real user.
//desc1, desc2 and note are NOT NULL without a default; leaving them out makes
//the insert fail silently, because ServerDB uses PDO's default ERRMODE_SILENT.
$inserted = $serverDB->exec(
    "INSERT INTO users (name, email, password, kid, race, desc1, desc2, note, signupTime)
     VALUES ('{$link['username']}', 'a{$suffix}@example.invalid', 'x', 0, 1, '', '', '', " . time() . ")"
);
if (!$inserted) {
    $error = $serverDB->errorInfo();
    exit("Could not create the test player: " . $error[2] . "\n");
}
$newUid = (int)$serverDB->lastInsertId();

$third = $flow->enter('twitch', $profile, $worldId);
check('now sent into the game', strpos($third['url'], 'login.php?') !== false, true);
check('handshake in the url', strpos($third['url'], 'handshake=') !== false, true);
check('uid resolved', $third['game_uid'], $newUid);

$link3 = $identities->findGameAccount($result['identity_id'], $worldId);
check('link updated with the real uid', (int)$link3['game_uid'], $newUid);

parse_str(parse_url($third['url'], PHP_URL_QUERY), $loginParams);
$handshakeRow = $serverDB->query(
    "SELECT uid FROM login_handshake WHERE token='" . sha1($loginParams['handshake']) . "'")->fetch();
truthy('handshake stored for the game to consume', $handshakeRow);
check('handshake points at the right player', (int)$handshakeRow['uid'], $newUid);

// ------------------------------------------------------------ name collision
echo "\nA Kick user with the same name\n";
$kickProfile = [
    'id'     => 'kick_' . $suffix,
    'login'  => 'viewer' . $suffix,   //same login, different provider
    'name'   => 'Viewer ' . $suffix,
    'email'  => "kick{$suffix}@example.invalid",
    'avatar' => null,
];
$kickResult = $flow->enter('kick', $kickProfile, $worldId);
$kickLink = $identities->findGameAccount($kickResult['identity_id'], $worldId);
check('gets a different username', $kickLink['username'] !== $link['username'], true);
check('derived from the original', strpos($kickLink['username'], 'viewer' . $suffix) === 0, true);
check('is a separate identity', $kickResult['identity_id'] !== $result['identity_id'], true);

// ------------------------------------------------------------------- cleanup
echo "\nCleaning up\n";
$serverDB->exec("DELETE FROM users WHERE id={$newUid}");
$serverDB->exec("DELETE FROM activation WHERE name LIKE 'viewer{$suffix}%'");
$serverDB->exec("DELETE FROM login_handshake WHERE uid={$newUid}");
$db = \Database\IdentityDB::getInstance();
$db->exec("DELETE FROM oauth_identities WHERE provider_user_id IN ('tw_{$suffix}', 'kick_{$suffix}')");
echo "  done\n";

echo "\n";
if ($failures) {
    echo "\033[0;31m{$failures} check(s) failed\033[0m\n";
    exit(1);
}
echo "\033[0;32mall checks passed\033[0m\n";
exit(0);
