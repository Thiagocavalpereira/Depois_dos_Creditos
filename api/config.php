<?php
declare(strict_types=1);

/** Lê uma variável de api/.env (ou do ambiente do servidor). */
function env(string $chave, ?string $padrao = null): ?string
{
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $arquivo = __DIR__ . '/.env';
        if (is_file($arquivo)) {
            foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linha) {
                $linha = trim($linha);
                if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) continue;
                [$k, $v] = explode('=', $linha, 2);
                $vars[trim($k)] = trim($v, " \t\"'");
            }
        }
    }
    if (isset($vars[$chave]) && $vars[$chave] !== '') return $vars[$chave];
    $amb = getenv($chave);
    return ($amb !== false && $amb !== '') ? $amb : $padrao;
}

function env_bool(string $chave, bool $padrao = false): bool
{
    $v = env($chave);
    return $v === null ? $padrao : in_array(strtolower($v), ['1', 'true', 'sim', 'yes', 'on'], true);
}
