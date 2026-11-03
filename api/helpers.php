<?php
declare(strict_types=1);

const AVATAR_PADRAO = 'https://cdn-icons-png.flaticon.com/512/149/149071.png';
const MOTIVOS_DENUNCIA = ['spam', 'ofensivo', 'falso', 'spoiler', 'outro'];

function resposta(mixed $dados, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function erro(string $mensagem, int $status = 400): never
{
    resposta(['erro' => $mensagem], $status);
}

/** Corpo JSON da requisição como array. */
function corpo(): array
{
    static $c = null;
    if ($c !== null) return $c;
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return $c = [];
    $j = json_decode($raw, true);
    if (!is_array($j)) erro('JSON inválido.', 400);
    return $c = $j;
}

function campo_texto(array $c, string $chave): string
{
    $v = $c[$chave] ?? '';
    return is_string($v) ? trim($v) : '';
}

function campo_bruto(array $c, string $chave): string
{
    $v = $c[$chave] ?? '';
    return is_string($v) ? $v : '';
}

// ---------- sessão / autenticação ----------

function iniciar_sessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
    session_cache_limiter('');
    session_name('ddc_sid');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function usuario_logado(): ?array
{
    $id = $_SESSION['uid'] ?? null;
    if (!$id) return null;
    $u = linha('SELECT id_usuario, nome, email, avatar, papel FROM usuario WHERE id_usuario = ?', [(int) $id]);
    if (!$u) {
        unset($_SESSION['uid']);
        return null;
    }
    return $u;
}

function exigir_login(): array
{
    $u = usuario_logado();
    if (!$u) erro('Faça login para continuar.', 401);
    return $u;
}

function exigir_admin(): array
{
    $u = exigir_login();
    if ($u['papel'] !== 'admin') erro('Acesso restrito a administradores.', 403);
    return $u;
}

// ---------- validações ----------

function validar_nome(string $nome): void
{
    // Restringe caracteres: o front insere o nome em atributos HTML sem escapar (alt="..."),
    // então aspas e < > precisam ser barradas aqui.
    if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} ._\-]{1,99}$/u', $nome)) {
        erro('Nome inválido. Use de 2 a 100 letras, números, espaço, ponto, hífen ou _.', 422);
    }
}

function validar_senha_nova(string $senha): void
{
    if (tam($senha) < 6) erro('A senha deve ter pelo menos 6 caracteres.', 422);
    if (strlen($senha) > 72) erro('A senha deve ter no máximo 72 caracteres.', 422);
}

/** Só aceita imagem em data URL (png/jpeg/gif/webp, até 2 MB) — evita injeção de HTML no src do front. */
function validar_avatar(string $avatar): void
{
    if (strlen($avatar) > 2_900_000
        || !preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/]+=*$#', $avatar)) {
        erro('Imagem inválida. Use PNG, JPG, GIF ou WEBP de até 2 MB.', 422);
    }
}

// ---------- formatação (formato que o front já espera) ----------

function avatar_ou_padrao(?string $a): string
{
    return ($a !== null && $a !== '') ? $a : AVATAR_PADRAO;
}

function usuario_json(array $u): array
{
    return [
        'id'     => (string) $u['id_usuario'],
        'nome'   => $u['nome'],
        'avatar' => avatar_ou_padrao($u['avatar'] ?? null),
    ];
}

function iso(string $ts): string
{
    return (new DateTimeImmutable($ts))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
}

function id_rota(string $v): int
{
    return (int) $v;
}

/** Quantidade de caracteres (UTF-8) sem depender da extensão mbstring. */
function tam(string $s): int
{
    return (int) preg_match_all('/./su', $s);
}
