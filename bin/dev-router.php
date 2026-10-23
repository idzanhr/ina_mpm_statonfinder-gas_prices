<?php
// Local development only (from the repo root):  php -S 127.0.0.1:8000 -t . bin/dev-router.php
// Mirrors the production whitelist in deploy/cloudpanel-vhost.conf, so app/, sql/, var/
// and anything else not part of the site return 404 locally too.
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

$notFound = function () use ($root): bool {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    if (is_file("$root/404.html")) readfile("$root/404.html"); else echo 'Not found';
    return true;
};

if ($path === '/') $path = '/index.html';
if (preg_match('#^/(privatnost|kolacici|index|404)$#', $path)) $path .= '.html';
if ($path === '/customer-gas-updater') { header('Location: /customer-gas-updater/', true, 301); return true; }

if ($path === '/customer-gas-updater/' || preg_match('#^/customer-gas-updater/(index|api)\.php$#', $path)) {
    $script = $path === '/customer-gas-updater/' ? '/customer-gas-updater/index.php' : $path;
    $_SERVER['SCRIPT_NAME'] = $script;
    chdir("$root/customer-gas-updater");
    require "$root$script";
    return true;
}

$allowed = preg_match('#^/(?:index|privatnost|kolacici|404)\.html$#', $path)
    || in_array($path, ['/data/stations.json', '/robots.txt', '/favicon.svg', '/favicon.png', '/apple-touch-icon.png'], true)
    || preg_match('#^/assets/(?:css|js)/[\w.-]+\.(?:css|js)$#', $path)
    || preg_match('#^/customer-gas-updater/assets/[\w.-]+\.(?:css|js)$#', $path);

if (!$allowed || !is_file($root . $path)) return $notFound();
if (str_ends_with($path, '.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . $path);
    return true;
}
return false; // let the built-in server send the static file with the right MIME type
