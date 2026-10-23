<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';

function opt(string $name): ?string
{
    global $argv;
    foreach ($argv as $a) {
        if ($a === "--$name") return '1';
        if (str_starts_with($a, "--$name=")) return substr($a, strlen($name) + 3);
    }
    return null;
}

function ask(string $label, bool $hidden = false): string
{
    fwrite(STDOUT, $label);
    $tty = $hidden && stream_isatty(STDIN);
    if ($tty) shell_exec('stty -echo');
    $v = trim((string) fgets(STDIN));
    if ($tty) { shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
    return $v;
}

function askPassword(): string
{
    if ($env = getenv('INA_POSTAJE_ADMIN_PASSWORD')) return $env;
    while (true) {
        $p = ask('Lozinka (min. 12 znakova): ', true);
        $problem = InaPostaje\Auth::passwordProblem($p, ask('Ponovite lozinku: ', true));
        if (!$problem) return $p;
        fwrite(STDERR, $problem . "\n");
    }
}
