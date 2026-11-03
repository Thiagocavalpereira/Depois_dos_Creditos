<?php
// Roteador do servidor PHP embutido (desenvolvimento e Render).
//   Local:  php -S localhost:8000 router.php
$caminho = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

// 1) API
if ($caminho === '/api' || str_starts_with($caminho, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

// 2) Só estes arquivos estáticos são públicos. Todo o resto (banco/*.sql, .git, .env,
//    Dockerfile, código PHP...) responde 404 em vez de ser baixado por qualquer pessoa.
$publico = $caminho === '/'
    || $caminho === '/index.html'
    || $caminho === '/style.css'
    || str_starts_with($caminho, '/img/')
    || str_starts_with($caminho, '/vid/');

if (!$publico || str_contains($caminho, '..')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Não encontrado';
    return true;
}

return false; // deixa o servidor entregar o arquivo
