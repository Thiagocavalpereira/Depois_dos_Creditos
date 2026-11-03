<?php
// Para desenvolvimento: php -S localhost:8000 router.php  (dentro da pasta do projeto)
$caminho = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($caminho === '/api' || str_starts_with($caminho, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}
return false; // arquivos estáticos (index.html, style.css, img/, vid/)
