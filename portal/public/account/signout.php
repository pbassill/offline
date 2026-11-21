<?php
/**
 * Sign out. Asked for with a link, done with a form, so that no page on any
 * other site can end a session by pointing a browser here.
 */
declare(strict_types=1);

const PORTAL_ALLOW_POST = true;
const PORTAL_CSP = "default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";
require __DIR__ . '/../../app/bootstrap.php';
if (!function_exists('Offline\\sign_in')) {
    require '/usr/local/lib/offline/auth.php';
}

$session = portal_session();
if ($session === null) {
    header('Location: /', true, 303);
    exit;
}
$token = (string) $_COOKIE[Offline\SESSION_COOKIE];
$proof = hash_hmac('sha256', 'sign out', $token);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($proof, is_string($_POST['proof'] ?? null) ? $_POST['proof'] : '')) {
        http_response_code(403);
        exit;
    }
    Offline\sessions_close($token);
    openlog('offline-portal', LOG_PID, LOG_AUTH);
    syslog(LOG_INFO, 'sign-out: ' . $session['user']);
    header('Set-Cookie: ' . Offline\SESSION_COOKIE . '=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Strict');
    header('Location: /', true, 303);
    exit;
}

page_start('Sign out', 'signout');
?>
<section class="signin">
  <h1>Sign out?</h1>
  <p class="hero-sub">You are signed in as <?= e($session['user']) ?>. After signing out, this browser shows the children's pages until an adult signs in again.</p>
  <form method="post" action="/account/signout.php">
    <input type="hidden" name="proof" value="<?= e($proof) ?>">
    <button class="btn btn-primary btn-lg" type="submit"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Sign out</button>
    <a class="btn btn-lg btn-outline-secondary" href="/">Stay signed in</a>
  </form>
</section>
<?php page_end();
