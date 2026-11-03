<?php
declare(strict_types=1);

function slugs_favoritos(int $idUsuario): array
{
    return array_column(linhas(
        'SELECT f.slug FROM favorito v JOIN filme f ON f.id_filme = v.id_filme
         WHERE v.id_usuario = ? ORDER BY v.data_favorito', [$idUsuario]), 'slug');
}

function usuario_sessao_json(array $u): array
{
    return usuario_json($u) + [
        'email'     => $u['email'],
        'papel'     => $u['papel'],
        'favoritos' => slugs_favoritos((int) $u['id_usuario']),
    ];
}

// POST /api/auth/cadastro   { nome, email, senha }
function auth_cadastro(): void
{
    $c = corpo();
    $nome  = campo_texto($c, 'nome');
    $email = strtolower(campo_texto($c, 'email'));
    $senha = campo_bruto($c, 'senha');

    if ($nome === '' || $email === '' || $senha === '') erro('Preencha todos os campos.', 422);
    validar_nome($nome);
    validar_senha_nova($senha);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) erro('Informe um email válido.', 422);

    try {
        $id = (int) q(
            'INSERT INTO usuario (nome, email, senha) VALUES (?, ?, ?) RETURNING id_usuario',
            [$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]
        )->fetchColumn();
    } catch (PDOException $e) {
        if (violacao_unica($e)) erro('Este email já está cadastrado.', 409);
        throw $e;
    }

    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
    resposta(['usuario' => usuario_sessao_json(usuario_logado())], 201);
}

// POST /api/auth/login   { email, senha }
function auth_login(): void
{
    $c = corpo();
    $email = strtolower(campo_texto($c, 'email'));
    $senha = campo_bruto($c, 'senha');
    if ($email === '' || $senha === '') erro('Preencha email e senha.', 422);

    $u = linha('SELECT id_usuario, senha FROM usuario WHERE email = ?', [$email]);
    if (!$u) {
        password_hash($senha, PASSWORD_DEFAULT); // gasta o mesmo tempo, para não revelar se o email existe
        erro('Email ou senha incorretos.', 401);
    }
    $hash = $u['senha'];
    if (!password_verify($senha, $hash)) erro('Email ou senha incorretos.', 401);

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        q('UPDATE usuario SET senha = ? WHERE id_usuario = ?', [password_hash($senha, PASSWORD_DEFAULT), $u['id_usuario']]);
    }

    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id_usuario'];
    resposta(['usuario' => usuario_sessao_json(usuario_logado())]);
}

// POST /api/auth/logout
function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'],
            'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    }
    session_destroy();
    resposta(['ok' => true]);
}

// GET /api/auth/sessao  -> usado ao carregar a página (substitui getSessao())
function auth_sessao(): void
{
    $u = usuario_logado();
    resposta($u ? ['logado' => true, 'usuario' => usuario_sessao_json($u)] : ['logado' => false]);
}

// POST /api/auth/recuperar-senha   { email, senha, confirmar }
function auth_recuperar_senha(): void
{
    if (!env_bool('RESET_SENHA_SEM_EMAIL', false)) {
        erro('Recuperação de senha desativada. Fale com o administrador.', 403);
    }
    $c = corpo();
    $email = strtolower(campo_texto($c, 'email'));
    $senha = campo_bruto($c, 'senha');
    $conf  = campo_bruto($c, 'confirmar');

    if ($email === '' || $senha === '' || $conf === '') erro('Preencha todos os campos.', 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) erro('Informe um email válido.', 422);
    validar_senha_nova($senha);
    if ($senha !== $conf) erro('As senhas não coincidem.', 422);

    $n = q('UPDATE usuario SET senha = ? WHERE email = ?', [password_hash($senha, PASSWORD_DEFAULT), $email])->rowCount();
    if ($n === 0) erro('Nenhuma conta encontrada com este email.', 404);
    resposta(['ok' => true]);
}
