<?php
declare(strict_types=1);

/** Conexão PDO com o Postgres do Supabase (uma por requisição). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
        env('DB_HOST', 'localhost'),
        env('DB_PORT', '5432'),
        env('DB_NAME', 'postgres'),
        env('DB_SSLMODE', 'require')
    );

    $pdo = new PDO($dsn, env('DB_USER', 'postgres'), env('DB_PASSWORD', ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Necessário para o pooler em modo "transaction" (porta 6543), que não aceita prepared statements nativos.
        PDO::ATTR_EMULATE_PREPARES   => true,
    ]);
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function linhas(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function linha(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function violacao_unica(PDOException $e): bool
{
    return $e->getCode() === '23505';
}
