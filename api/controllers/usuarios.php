<?php
declare(strict_types=1);

function itens_perfil(array $linhas): array
{
    return array_map(fn($f) => [
        'filme' => filme_json($f),
        'nota'  => $f['nota'] !== null ? (int) $f['nota'] : null,
        'data'  => $f['data_ref'] !== null ? iso($f['data_ref']) : null,
    ], $linhas);
}

// GET /api/usuarios/{id|me}   -> substitui openProfile() e abrirPerfilPublico()
function usuario_perfil(string $id): void
{
    $logado = usuario_logado();
    if ($id === 'me') {
        if (!$logado) erro('Faça login para continuar.', 401);
        $idU = (int) $logado['id_usuario'];
    } else {
        $idU = id_rota($id);
    }

    $u = linha('SELECT id_usuario, nome, email, avatar, data_cadastro FROM usuario WHERE id_usuario = ?', [$idU]);
    if (!$u) erro('Usuário não encontrado.', 404);

    $avaliados = linhas(
        sql_filme(', a.nota, a.data_avaliacao AS data_ref', 'JOIN avaliacao a ON a.id_filme = f.id_filme AND a.id_usuario = ?')
        . ' ORDER BY a.data_avaliacao DESC', [$idU]);

    $favoritos = linhas(
        sql_filme(', a.nota, a.data_avaliacao AS data_ref',
            'JOIN favorito v ON v.id_filme = f.id_filme AND v.id_usuario = ?
             LEFT JOIN avaliacao a ON a.id_filme = f.id_filme AND a.id_usuario = v.id_usuario')
        . ' ORDER BY v.data_favorito DESC', [$idU]);

    $notas = array_column($avaliados, 'nota');
    $dono = $logado && (int) $logado['id_usuario'] === $idU;

    resposta(['usuario' => usuario_json($u) + [
        'email'        => $dono ? $u['email'] : null,
        'membroDesde'  => iso($u['data_cadastro']),
        'totalReviews' => count($avaliados),
        'totalFavoritos' => count($favoritos),
        'mediaNota'    => $notas ? number_format(array_sum($notas) / count($notas), 1, '.', '') : null,
        'avaliados'    => itens_perfil($avaliados),
        'favoritos'    => itens_perfil($favoritos),
        'proprio'      => $dono,
    ]]);
}

// PUT /api/usuarios/me   { nome, senha_atual?, senha_nova? }   -> substitui salvarPerfil()
function usuario_atualizar(): void
{
    $u = exigir_login();
    $c = corpo();
    $nome = campo_texto($c, 'nome');
    $atual = campo_bruto($c, 'senha_atual');
    $nova  = campo_bruto($c, 'senha_nova');

    if ($nome === '') erro('Informe um nome válido.', 422);
    validar_nome($nome);

    if ($nova !== '') {
        if ($atual === '') erro('Informe a senha atual para alterá-la.', 422);
        $hash = (string) q('SELECT senha FROM usuario WHERE id_usuario = ?', [$u['id_usuario']])->fetchColumn();
        if (!password_verify($atual, $hash)) erro('Senha atual incorreta.', 403);
        validar_senha_nova($nova);
        q('UPDATE usuario SET nome = ?, senha = ? WHERE id_usuario = ?', [$nome, password_hash($nova, PASSWORD_DEFAULT), $u['id_usuario']]);
    } else {
        q('UPDATE usuario SET nome = ? WHERE id_usuario = ?', [$nome, $u['id_usuario']]);
    }
    resposta(['usuario' => usuario_sessao_json(usuario_logado())]);
}

// PUT /api/usuarios/me/avatar   { avatar: "data:image/png;base64,..." }   -> substitui salvarAvatarAutomatico()
function usuario_avatar(): void
{
    $u = exigir_login();
    $avatar = campo_bruto(corpo(), 'avatar');
    validar_avatar($avatar);
    q('UPDATE usuario SET avatar = ? WHERE id_usuario = ?', [$avatar, $u['id_usuario']]);
    resposta(['usuario' => usuario_sessao_json(usuario_logado())]);
}
