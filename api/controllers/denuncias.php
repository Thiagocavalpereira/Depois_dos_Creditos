<?php
declare(strict_types=1);

// POST /api/denuncias   { reviewId, motivo, detalhes? }   -> substitui enviarDenuncia()
function denuncia_criar(): void
{
    $u = exigir_login();
    $c = corpo();
    $idReview = (int) ($c['reviewId'] ?? 0);
    $motivo   = campo_texto($c, 'motivo');
    $detalhes = campo_texto($c, 'detalhes');

    if (!in_array($motivo, MOTIVOS_DENUNCIA, true)) erro('Motivo inválido.', 422);
    if (tam($detalhes) > 1000) erro('Os detalhes podem ter no máximo 1000 caracteres.', 422);

    $review = linha('SELECT id_comentario, id_usuario FROM comentario WHERE id_comentario = ?', [$idReview]);
    if (!$review) erro('Avaliação não encontrada.', 404);
    if ((int) $review['id_usuario'] === (int) $u['id_usuario']) erro('Você não pode denunciar sua própria avaliação.', 403);

    try {
        $id = (int) q(
            'INSERT INTO denuncia (id_usuario_denunciado, id_usuario_denunciante, id_comentario, motivo, detalhes)
             VALUES (?, ?, ?, ?, ?) RETURNING id_denuncia',
            [$review['id_usuario'], $u['id_usuario'], $idReview, $motivo, $detalhes ?: null]
        )->fetchColumn();
    } catch (PDOException $e) {
        if (violacao_unica($e)) erro('Você já denunciou esta avaliação.', 409);
        throw $e;
    }
    resposta(['ok' => true, 'id' => (string) $id], 201);
}

function denuncias_json(array $linhas): array
{
    return array_map(fn($d) => [
        'id'             => (string) $d['id_denuncia'],
        'reviewId'       => $d['id_comentario'] !== null ? (string) $d['id_comentario'] : null,
        'userId'         => (string) $d['id_usuario_denunciante'],
        'userName'       => $d['denunciante'],
        'reviewUserName' => $d['denunciado'],
        'filmeTitulo'    => $d['filme_titulo'] ?? 'Filme',
        'motivo'         => $d['motivo'],
        'detalhes'       => $d['detalhes'] ?? '',
        'data'           => iso($d['data_denuncia']),
    ], $linhas);
}

const SQL_DENUNCIAS = 'SELECT d.id_denuncia, d.id_comentario, d.id_usuario_denunciante, d.motivo, d.detalhes, d.data_denuncia,
        du.nome AS denunciante, dd.nome AS denunciado, f.titulo AS filme_titulo
    FROM denuncia d
    JOIN usuario du ON du.id_usuario = d.id_usuario_denunciante
    JOIN usuario dd ON dd.id_usuario = d.id_usuario_denunciado
    LEFT JOIN comentario c ON c.id_comentario = d.id_comentario
    LEFT JOIN filme f ON f.id_filme = c.id_filme';

// GET /api/denuncias   -> minhas denúncias (aba "Denúncias" do perfil; substitui renderDenunciasTab)
function denuncias_minhas(): void
{
    $u = exigir_login();
    resposta(['denuncias' => denuncias_json(linhas(
        SQL_DENUNCIAS . ' WHERE d.id_usuario_denunciante = ? ORDER BY d.data_denuncia DESC', [$u['id_usuario']]))]);
}

// GET /api/admin/denuncias   -> todas as denúncias (somente admin)
function denuncias_admin(): void
{
    exigir_admin();
    resposta(['denuncias' => denuncias_json(linhas(SQL_DENUNCIAS . ' ORDER BY d.data_denuncia DESC LIMIT 200'))]);
}
