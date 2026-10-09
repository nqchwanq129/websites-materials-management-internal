<?php
declare(strict_types=1);

function connectDatabase(): PDO
{
    $file = dirname(__DIR__) . '/.env';
    if (!is_readable($file)) {
        throw new RuntimeException('Missing database configuration.');
    }
    $config = parse_ini_file($file, false, INI_SCANNER_RAW);
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
        if (!is_array($config) || !array_key_exists($key, $config)) {
            throw new RuntimeException('Incomplete database configuration.');
        }
    }
    if (!ctype_digit($config['DB_PORT']) || (int) $config['DB_PORT'] < 1 || (int) $config['DB_PORT'] > 65535
        || !preg_match('/^[a-zA-Z0-9_.-]+$/D', $config['DB_HOST'])
        || !preg_match('/^[a-zA-Z0-9_-]+$/D', $config['DB_NAME'])) {
        throw new RuntimeException('Invalid database configuration.');
    }
    return new PDO(
        "mysql:host={$config['DB_HOST']};port={$config['DB_PORT']};dbname={$config['DB_NAME']};charset=utf8mb4",
        $config['DB_USER'], $config['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]
    );
}
