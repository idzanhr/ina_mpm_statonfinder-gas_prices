<?php
declare(strict_types=1);

// Hidden admin. app/ sits next to this folder; nginx never serves it (see deploy/).
require dirname(__DIR__) . '/app/bootstrap.php';

use InaPostaje\{Auth, Config, Fuels, HttpError, Render, Stations, Totp, Web};

Web::securityHeaders();
Auth::startSession();
$self = Config::get('admin_path');
$e = [Render::class, 'e'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Web::assertCsrf();
        $action = $_POST['action'] ?? '';
        switch ($action) {
            case 'login':
                if (!Auth::attemptPassword((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                    Web::flash('Pogrešna e-mail adresa ili lozinka.', 'error');
                }
                break;
            case 'totp':
                $code = trim((string) ($_POST['code'] ?? ''));
                $ok = preg_match('/^\d[\d\s]*$/', $code) ? Auth::attemptTotp($code) : Auth::attemptRecoveryCode($code);
                if (!$ok) Web::flash('Neispravan ili već iskorišten kod.', 'error');
                elseif (!preg_match('/^\d[\d\s]*$/', $code)) Web::flash('Prijavljeni ste rezervnim kodom. Preostalo rezervnih kodova: ' . Auth::unusedRecoveryCodes() . '.', 'info');
                break;
            case 'enroll':
                $codes = Auth::confirmEnrollment((string) ($_POST['code'] ?? ''), true);
                if ($codes === null) Web::flash('Kod nije ispravan. Provjerite vrijeme na mobitelu i pokušajte ponovno.', 'error');
                else $_SESSION['show_recovery'] = $codes;
                break;
            case 'change-password':
                if (Auth::stage() !== 'change-password') throw new HttpError(403, 'Nedopušteno.');
                $problem = Auth::passwordProblem((string) ($_POST['new'] ?? ''), (string) ($_POST['repeat'] ?? ''));
                if ($problem) Web::flash($problem, 'error');
                else { Auth::changePassword((string) $_POST['new']); Web::flash('Lozinka je spremljena.', 'info'); }
                break;
            case 'logout':
                Auth::logout();
                break;
        }
    } catch (HttpError $err) {
        if ($err->status === 429 && Config::get('lockout_redirect')) {
            Auth::logout();
            Web::redirect(Config::get('lockout_redirect'));
        }
        Web::flash($err->getMessage(), 'error');
    }
    // A failed sign-in step that hits the limit leaves the site, like the original.
    if (in_array($action ?? '', ['login', 'totp', 'enroll'], true) && Auth::isLocked() && Config::get('lockout_redirect')) {
        Auth::logout();
        Web::redirect(Config::get('lockout_redirect'));
    }
    Web::redirect($self);
}

$stage = Auth::stage();
$flash = Web::flash();
$csrf = Web::csrfToken();

/** Admin asset URL with a content hash, so browsers may cache it long-term. */
function asset(string $rel): string
{
    global $self;
    return $self . $rel . '?v=' . substr(hash_file('sha256', __DIR__ . '/' . $rel), 0, 10);
}

function layout(string $title, string $body, string $width = 'auth'): void
{
    global $self, $csrf, $stage;
    $e = [Render::class, 'e'];
    $signedIn = !in_array($stage, ['login', 'no-admin'], true);
    ?><!doctype html>
<html lang="hr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow, noarchive">
  <meta name="color-scheme" content="light dark">
<?= Render::headAssets(dirname(__DIR__)) ?>

  <title><?= $e($title) ?> – INA postaje urednik</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="stylesheet" href="<?= $e(asset('assets/admin.css')) ?>">
</head>
<body>
<main class="page">
  <div class="container mid">
    <div class="admin-top">
      <h1 class="display admin-title">Urednik</h1>
      <div class="admin-top-actions">
        <a class="btn-ghost" href="/">← Popis</a>
        <?php if ($signedIn): ?>
        <form method="post" action="<?= $e($self) ?>"><input type="hidden" name="csrf" value="<?= $e($csrf) ?>"><button class="btn-ghost" name="action" value="logout">Odjava</button></form>
        <?php endif ?>
        <button type="button" class="theme-toggle" data-theme-toggle>
          <svg class="when-light" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
          <span class="sr-only when-light">Prebaci na tamni način</span><span class="sr-only when-dark">Prebaci na svijetli način</span>
          <svg class="when-dark" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
        </button>
      </div>
    </div>
    <?= $body ?>
  </div>
</main>
</body>
</html>
<?php
}

function flashHtml(?array $flash): string
{
    if (!$flash) return '';
    return '<p class="flash flash-' . Render::e($flash[0]) . '" role="alert">' . Render::e($flash[1]) . '</p>';
}

$hidden = '<input type="hidden" name="csrf" value="' . $e($csrf) . '">';
$locked = in_array($stage, ['login', 'totp', 'enroll'], true) && Auth::isLocked();

if ($stage === 'no-admin') {
    layout('Postavljanje', '<div class="panel auth-box"><h2>Administrator nije postavljen</h2><p class="muted small">Na poslužitelju pokrenite <code>php bin/install.php</code> kako biste kreirali bazu i jedini administratorski račun.</p></div>');
} elseif ($locked) {
    layout('Zaključano', '<div class="panel auth-box"><h2>Privremeno zaključano</h2><p class="muted small">Previše neuspjelih pokušaja prijave. Pokušajte ponovno za ' . (int) Config::get('lockout_minutes') . ' minuta.</p></div>');
} elseif ($stage === 'login') {
    layout('Prijava', '
    <form method="post" action="' . $e($self) . '" class="panel auth-box">' . $hidden . '
      <h2>Prijava</h2>
      <p class="muted small">Samo za administratora. Nakon lozinke slijedi kod iz aplikacije za autentifikaciju.</p>
      ' . flashHtml($flash) . '
      <label class="field"><span>E-mail</span><input type="email" name="email" autocomplete="username" required autofocus></label>
      <label class="field"><span>Lozinka</span><input type="password" name="password" autocomplete="current-password" required></label>
      <button class="btn-primary wide" name="action" value="login">Prijavi se</button>
    </form>');
} elseif ($stage === 'totp') {
    layout('Dvostruka provjera', '
    <form method="post" action="' . $e($self) . '" class="panel auth-box accent">' . $hidden . '
      <h2>Dvostruka provjera</h2>
      <p class="muted small">Unesite 6-znamenkasti kod iz aplikacije za autentifikaciju. Ako nemate pristup mobitelu, unesite jedan od rezervnih kodova.</p>
      ' . flashHtml($flash) . '
      <label class="field"><span>Kod</span><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="16" required autofocus></label>
      <button class="btn-primary wide" name="action" value="totp">Potvrdi</button>
      <button class="btn-ghost wide" name="action" value="logout" formnovalidate>Odjava</button>
    </form>');
} elseif ($stage === 'enroll') {
    if (!Config::get('allow_web_enrollment')) {
        layout('2FA', '<div class="panel auth-box"><h2>Dvostruka provjera nije postavljena</h2><p class="muted small">Web postavljanje je isključeno. Na poslužitelju pokrenite <code>php bin/reset-2fa.php --print</code>.</p></div>');
    } else {
        $secret = Auth::pendingSecret();
        $uri = Totp::uri($secret, Auth::admin()['email'], Config::get('totp_issuer'));
        layout('Postavljanje 2FA', '
    <form method="post" action="' . $e($self) . '" class="panel auth-box accent">' . $hidden . '
      <h2>Postavite dvostruku provjeru</h2>
      <p class="muted small">Obavezno. Skenirajte QR kod u aplikaciji za autentifikaciju (Google Authenticator, Microsoft Authenticator, 1Password, Aegis…), zatim unesite kod koji aplikacija prikaže.</p>
      ' . flashHtml($flash) . '
      <div class="qr" data-qr="' . $e($uri) . '" role="img" aria-label="QR kod za dvofaktorsku provjeru"></div>
      <details class="small"><summary>Ne možete skenirati? Unesite ključ ručno</summary><code class="secret">' . $e(trim(chunk_split($secret, 4, ' '))) . '</code><p class="muted">Vrsta: vremenski (TOTP), 6 znamenki, 30 sekundi.</p></details>
      <label class="field"><span>6-znamenkasti kod</span><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required></label>
      <button class="btn-primary wide" name="action" value="enroll">Aktiviraj</button>
      <button class="btn-ghost wide" name="action" value="logout" formnovalidate>Odjava</button>
    </form>
    <script src="' . $e(asset('assets/qrcode.js')) . '"></script><script src="' . $e(asset('assets/qr-render.js')) . '"></script>');
    }
} elseif ($stage === 'change-password') {
    layout('Nova lozinka', '
    <form method="post" action="' . $e($self) . '" class="panel auth-box accent">' . $hidden . '
      ' . recoveryHtml() . '
      <h2>Postavite novu lozinku</h2>
      <p class="muted small">Vaš račun koristi privremenu lozinku. Prije nastavka postavite vlastitu (najmanje 12 znakova).</p>
      ' . flashHtml($flash) . '
      <input type="text" name="username" autocomplete="username" value="' . $e(Auth::admin()['email']) . '" hidden>
      <label class="field"><span>Nova lozinka</span><input type="password" name="new" autocomplete="new-password" minlength="12" required autofocus></label>
      <label class="field"><span>Ponovite lozinku</span><input type="password" name="repeat" autocomplete="new-password" minlength="12" required></label>
      <button class="btn-primary wide" name="action" value="change-password">Spremi lozinku</button>
    </form>');
} else {
    $boot = [
        'csrf' => $csrf,
        'api' => $self . 'api.php',
        'counties' => Stations::COUNTIES,
        'fuelCategories' => Fuels::CATEGORIES,
        'email' => Auth::admin()['email'],
        'recoveryLeft' => Auth::unusedRecoveryCodes(),
        // Loaded on demand: only needed when moving 2FA to a new phone.
        'qrScripts' => [asset('assets/qrcode.js'), asset('assets/qr-render.js')],
    ];
    layout('Uređivanje', recoveryHtml() . flashHtml($flash) . '
    <div id="app" data-boot="' . $e(json_encode($boot, JSON_UNESCAPED_UNICODE)) . '"><p class="muted">Učitavanje…</p></div>
    <script src="' . $e(asset('assets/admin.js')) . '"></script>', 'wide');
}

/** Recovery codes are shown exactly once, right after 2FA is enrolled. */
function recoveryHtml(): string
{
    $codes = $_SESSION['show_recovery'] ?? null;
    if (!$codes) return '';
    if (Auth::stage() === 'editor') unset($_SESSION['show_recovery']);
    $list = implode('', array_map(fn($c) => '<li><code>' . Render::e($c) . '</code></li>', $codes));
    return '<div class="panel recovery"><h2>Rezervni kodovi</h2><p class="muted small">Spremite ih na sigurno mjesto (npr. upravitelj lozinki). Svaki kod vrijedi jednom i zamjenjuje kod iz aplikacije ako izgubite mobitel. Neće se ponovno prikazati.</p><ul class="codes">' . $list . '</ul></div>';
}
