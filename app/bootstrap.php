<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/management.php';
require_once __DIR__ . '/profile.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' blob:; base-uri \'self\'; form-action \'self\'; frame-ancestors \'none\'');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('qlvt_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function validCsrfToken(): bool
{
    return is_string($_POST['csrf_token'] ?? null)
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function redirectTo(string $page): never
{
    header('Location: index.php?page=' . $page, true, 303);
    exit;
}

function respondJson(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
