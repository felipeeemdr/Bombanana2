<?php
declare(strict_types=1);

/** Abre uma conexão PDO com PostgreSQL usando a URL do Render. */
function nexo_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $configFile = dirname(__DIR__) . '/config.php';
    if (!is_file($configFile)) {
        throw new RuntimeException('O arquivo config.php não foi encontrado. Consulte o guia de implantação no Render.');
    }

    $cfg = require $configFile;
    $databaseUrl = trim((string)($cfg['database_url'] ?? ''));
    if ($databaseUrl === '' || !preg_match('#^postgres(?:ql)?://#i', $databaseUrl)) {
        throw new RuntimeException('DATABASE_URL não está configurada. Vincule o PostgreSQL ao Web Service pelo render.yaml.');
    }

    $parts = parse_url($databaseUrl);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
        throw new RuntimeException('DATABASE_URL não parece ser uma URL PostgreSQL válida.');
    }

    $host = (string)$parts['host'];
    $port = isset($parts['port']) ? (int)$parts['port'] : 5432;
    $database = ltrim((string)$parts['path'], '/');
    $username = rawurldecode((string)($parts['user'] ?? ''));
    $password = rawurldecode((string)($parts['pass'] ?? ''));

    if ($host === '' || $database === '' || $username === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException('DATABASE_URL está incompleta. Verifique a conexão interna do Render PostgreSQL.');
    }

    $query = [];
    if (isset($parts['query'])) {
        parse_str((string)$parts['query'], $query);
    }
    $sslMode = (string)($query['sslmode'] ?? ($cfg['db_sslmode'] ?? 'prefer'));
    $allowedSslModes = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
    if (!in_array($sslMode, $allowedSslModes, true)) {
        $sslMode = 'prefer';
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
        $host,
        $port,
        $database,
        $sslMode
    );

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 8,
    ];

    try {
        $pdo = new PDO($dsn, $username, $password, $options);
        $pdo->exec("SET TIME ZONE 'UTC'");
    } catch (PDOException $e) {
        error_log('NEXO PostgreSQL connection: ' . $e->getMessage());
        throw new RuntimeException('Não foi possível conectar ao PostgreSQL do Render. Confira DATABASE_URL e os logs do serviço.');
    }

    return $pdo;
}
