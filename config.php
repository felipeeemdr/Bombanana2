<?php
declare(strict_types=1);

/** Configuração NEXO TRIO para Render PostgreSQL. */
$env = static function (string $key, string $fallback = ''): string {
    $value = getenv($key);
    return $value === false ? $fallback : (string)$value;
};

return [
    'app_env' => $env('APP_ENV', 'local'),
    'database_url' => $env('DATABASE_URL', ''),
    // Render fornece a URL interna do PostgreSQL via Blueprint.
    // Em conexões internas, libpq usa prefer como padrão seguro e compatível.
    'db_sslmode' => $env('DB_SSLMODE', 'prefer'),
];
