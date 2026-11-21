<?php
/**
 * Sign in. One of the two pages that accept a form; both run in the
 * "offline-auth" PHP pool, the only part of the portal that can read the
 * account records or open a session. Served over HTTPS only.
 */
declare(strict_types=1);

const PORTAL_ALLOW_POST = true;
const PORTAL_CSP = "default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";
require __DIR__ . '/../../app/bootstrap.php';
if (!function_exists('Offline\\sign_in')) {
    require '/usr/local/lib/offline/auth.php';
}

$next = (string) ($_POST['next'] ?? $_GET['next'] ?? '/');
if (preg_match('/^\/[A-Za-z0-9._~\/?=&%+-]{0,300}$/', $next) !== 1 || str_starts_with($next, '//') || str_starts_with($next, '/account/')) {
    $next = '/';
}
if (($_SERVER['HTTPS'] ?? '') !== 'on') {
    // The plain site never shows this page; the web server redirects before it is reached.
    http_response_code(403);
    exit;
}
if (portal_is_adult()) {
    header('Location: ' . $next, true, 303);
    exit;
}

$problem = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // A form sent from another site is not one of ours, whatever it contains.
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $site = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin');
    if (($origin !== '' && $origin !== 'https://' . ($_SERVER['HTTP_HOST'] ?? '')) || !in_array($site, ['same-origin', 'none'], true)) {
        http_response_code(403);
        exit;
    }
    $name = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $result = Offline\sign_in($name, $password, $address);
    openlog('offline-portal', LOG_PID, LOG_AUTH);
    if ($result['ok']) {
        syslog(LOG_INFO, 'sign-in: ' . $result['user'] . ' from ' . $address);
        header('Set-Cookie: ' . Offline\SESSION_COOKIE . '=' . $result['token'] . '; Path=/; Max-Age=' . Offline\SESSION_SECONDS . '; Secure; HttpOnly; SameSite=Strict');
        header('Location: ' . $next, true, 303);
        exit;
    }
    syslog(LOG_WARNING, 'sign-in refused from ' . $address . ($result['wait'] > 0 ? ' (held for ' . $result['wait'] . ' s)' : ''));
    http_response_code($result['wait'] > 0 ? 429 : 401);
    $problem = $result['wait'] > 0
        ? 'Too many wrong attempts. Wait ' . max(1, (int) ceil($result['wait'] / 60)) . ' minutes and try again.'
        : 'That name and password do not match an adult\'s account.';
}

page_start('Sign in', 'signin');
?>
<section class="signin">
  <h1>Sign in</h1>
  <p class="hero-sub">The whole library, every film and the pages about this system are for the adults of the house. Everything on the children's pages is open without signing in.</p>
<?php if ($problem !== null) : ?>
  <div class="notice notice-warn" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <?= e($problem) ?></div>
<?php endif; ?>
  <form method="post" action="/account/signin.php" autocomplete="on">
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <div class="mb-3">
      <label class="form-label" for="signin-name">Account name</label>
      <input class="form-control form-control-lg" id="signin-name" name="name" type="text" required maxlength="31" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus>
    </div>
    <div class="mb-3">
      <label class="form-label" for="signin-password">Password</label>
      <input class="form-control form-control-lg" id="signin-password" name="password" type="password" required maxlength="256" autocomplete="current-password">
    </div>
    <button class="btn btn-primary btn-lg" type="submit"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> Sign in</button>
    <a class="btn btn-lg btn-outline-secondary" href="/">Back to the children's pages</a>
  </form>
  <p class="row-note">Accounts are made by an administrator with <code>sudo offline user add NAME --adult</code>. A session lasts twelve hours and ends when this system restarts. If your browser warned about this page's certificate, install the household's certificate authority from <a href="/household-ca.crt">/household-ca.crt</a> after checking its fingerprint against <code>offline tls</code>.</p>
</section>
<?php page_end();
