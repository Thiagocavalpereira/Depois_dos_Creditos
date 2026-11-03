<?php
declare(strict_types=1);

/**
 * Roteador da API "Depois dos Créditos".
 * Todas as respostas são JSON. Autenticação por sessão PHP (cookie httpOnly).
 */
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require __DIR__ . '/helpers.php';
foreach (['auth', 'filmes', 'reviews', 'usuarios', 'denuncias'] as $c) require __DIR__ . "/controllers/$c.php";

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// CORS opcional (só se o front estiver em outro domínio/porta)
$origem = env('CORS_ORIGIN');
if ($origem && ($_SERVER['HTTP_ORIGIN'] ?? '') === $origem) {
    header("Access-Control-Allow-Origin: $origem");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Vary: Origin');
}

$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo === 'OPTIONS') { http_response_code(204); exit; }

$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$pos = strpos($caminho, '/api');
$caminho = $pos === false ? $caminho : substr($caminho, $pos + 4);
$caminho = preg_replace('#^/index\.php#', '', $caminho);
$caminho = '/' . trim($caminho, '/');

// Proteção CSRF: métodos que alteram dados só aceitam JSON (formulários de outros sites não conseguem enviar isso sem CORS).
if (in_array($metodo, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
    && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
    erro('Content-Type deve ser application/json.', 415);
}

$rotas = [
    ['POST', '/auth/cadastro',          'auth_cadastro'],
    ['POST', '/auth/login',             'auth_login'],
    ['POST', '/auth/logout',            'auth_logout'],
    ['GET',  '/auth/sessao',            'auth_sessao'],
    ['POST', '/auth/recuperar-senha',   'auth_recuperar_senha'],

    ['GET',  '/filmes',                 'filmes_listar'],
    ['POST', '/filmes',                 'filmes_criar'],
    ['GET',  '/filmes/([a-z0-9-]+)',    'filmes_obter'],
    ['GET',  '/filmes/([a-z0-9-]+)/reviews',  'reviews_listar'],
    ['POST', '/filmes/([a-z0-9-]+)/reviews',  'reviews_criar'],
    ['POST', '/filmes/([a-z0-9-]+)/favorito', 'favorito_alternar'],
    ['GET',  '/destaque-semana',        'filmes_destaque'],
    ['GET',  '/busca',                  'busca_global'],

    ['POST', '/reviews/(\d+)/respostas','respostas_criar'],
    ['POST', '/comentarios/(\d+)/curtir','curtir_alternar'],

    ['PUT',  '/usuarios/me',            'usuario_atualizar'],
    ['PUT',  '/usuarios/me/avatar',     'usuario_avatar'],
    ['GET',  '/usuarios/(me|\d+)',      'usuario_perfil'],

    ['POST', '/denuncias',              'denuncia_criar'],
    ['GET',  '/denuncias',              'denuncias_minhas'],
    ['GET',  '/admin/denuncias',        'denuncias_admin'],
];

try {
    iniciar_sessao();
    $caminhoExiste = false;
    foreach ($rotas as [$m, $padrao, $handler]) {
        if (!preg_match('#^' . $padrao . '$#', $caminho, $partes)) continue;
        $caminhoExiste = true;
        if ($m !== $metodo) continue;
        array_shift($partes);
        $handler(...$partes);
        exit;
    }
    erro($caminhoExiste ? 'Método não permitido.' : 'Rota não encontrada.', $caminhoExiste ? 405 : 404);
} catch (Throwable $e) {
    error_log('[DDC API] ' . $e);
    erro(env_bool('APP_DEBUG') ? $e->getMessage() : 'Erro interno do servidor.', 500);
}
