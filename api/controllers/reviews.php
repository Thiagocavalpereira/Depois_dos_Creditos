<?php
declare(strict_types=1);

/**
 * Uma "review" do front = 1 linha em avaliacao (nota) + 1 comentário raiz (texto, id_comentario_pai NULL).
 * Uma "resposta" do front = comentário com id_comentario_pai preenchido (máx. 2 níveis).
 * Likes (de review e de resposta) = tabela curtida.
 */

function reviews_do_filme(array $filme, string $ordem): array
{
    $idFilme = (int) $filme['id_filme'];

    $raizes = linhas(
        'SELECT c.id_comentario, c.id_usuario, c.texto, c.data_comentario, u.nome, u.avatar, a.nota
         FROM comentario c
         JOIN usuario  u ON u.id_usuario = c.id_usuario
         JOIN avaliacao a ON a.id_usuario = c.id_usuario AND a.id_filme = c.id_filme
         WHERE c.id_filme = ? AND c.id_comentario_pai IS NULL', [$idFilme]);

    $respostas = linhas(
        'SELECT c.id_comentario, c.id_comentario_pai, c.id_usuario, c.texto, c.data_comentario, u.nome, u.avatar
         FROM comentario c JOIN usuario u ON u.id_usuario = c.id_usuario
         WHERE c.id_filme = ? AND c.id_comentario_pai IS NOT NULL
         ORDER BY c.data_comentario, c.id_comentario', [$idFilme]);

    $likes = [];
    foreach (linhas(
        'SELECT k.id_comentario, k.id_usuario FROM curtida k
         JOIN comentario c ON c.id_comentario = k.id_comentario WHERE c.id_filme = ?', [$idFilme]) as $k) {
        $likes[(int) $k['id_comentario']][] = (string) $k['id_usuario'];
    }

    $reviews = [];
    foreach ($raizes as $r) {
        $id = (int) $r['id_comentario'];
        $reviews[$id] = [
            'id'         => (string) $id,
            'filmeId'    => $filme['slug'],
            'userId'     => (string) $r['id_usuario'],
            'userName'   => $r['nome'],
            'userAvatar' => avatar_ou_padrao($r['avatar']),
            'nota'       => (int) $r['nota'],
            'texto'      => $r['texto'],
            'likes'      => $likes[$id] ?? [],
            'respostas'  => [],
            'data'       => iso($r['data_comentario']),
        ];
    }

    // Respostas: pai = a review (nível 1) ou outra resposta (nível 2)
    $pai = [];
    foreach ($respostas as $r) $pai[(int) $r['id_comentario']] = (int) $r['id_comentario_pai'];
    foreach ($respostas as $r) {
        $id = (int) $r['id_comentario'];
        $p  = (int) $r['id_comentario_pai'];
        $raiz = isset($reviews[$p]) ? $p : ($pai[$p] ?? 0);
        if (!isset($reviews[$raiz])) continue;
        $reviews[$raiz]['respostas'][] = [
            'id'         => (string) $id,
            'parentId'   => isset($reviews[$p]) ? null : (string) $p,
            'userId'     => (string) $r['id_usuario'],
            'userName'   => $r['nome'],
            'userAvatar' => avatar_ou_padrao($r['avatar']),
            'texto'      => $r['texto'],
            'likes'      => $likes[$id] ?? [],
            'data'       => iso($r['data_comentario']),
        ];
    }

    $lista = array_values($reviews);
    usort($lista, match ($ordem) {
        'recentes' => fn($a, $b) => strcmp($b['data'], $a['data']),
        'antigas'  => fn($a, $b) => strcmp($a['data'], $b['data']),
        default    => fn($a, $b) => count($b['likes']) <=> count($a['likes']) ?: strcmp($b['data'], $a['data']),
    });
    return $lista;
}

// GET /api/filmes/{slug}/reviews?ordem=populares|recentes|antigas  -> substitui getReviews().filter(filmeId)
function reviews_listar(string $slug): void
{
    $filme = filme_por_slug($slug);
    $ordem = (string) ($_GET['ordem'] ?? 'populares');
    resposta([
        'filme'   => filme_json($filme),
        'reviews' => reviews_do_filme($filme, $ordem),
    ]);
}

// POST /api/filmes/{slug}/reviews   { nota: 1..10, texto }   -> substitui enviarReview()
function reviews_criar(string $slug): void
{
    $u = exigir_login();
    $filme = filme_por_slug($slug);
    $c = corpo();

    $nota  = $c['nota'] ?? null;
    $texto = campo_texto($c, 'texto');
    if (!is_int($nota) && !(is_string($nota) && ctype_digit($nota))) erro('Selecione uma nota de 1 a 10.', 422);
    $nota = (int) $nota;
    if ($nota < 1 || $nota > 10) erro('Selecione uma nota de 1 a 10.', 422);
    if (tam($texto) < 10) erro('Escreva um comentário com pelo menos 10 caracteres.', 422);
    if (tam($texto) > 5000) erro('O comentário pode ter no máximo 5000 caracteres.', 422);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO avaliacao (id_usuario, id_filme, nota) VALUES (?, ?, ?)', [$u['id_usuario'], $filme['id_filme'], $nota]);
        $id = (int) q(
            'INSERT INTO comentario (id_usuario, id_filme, texto) VALUES (?, ?, ?) RETURNING id_comentario',
            [$u['id_usuario'], $filme['id_filme'], $texto]
        )->fetchColumn();
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if (violacao_unica($e)) erro('Você já avaliou este filme.', 409);
        throw $e;
    }
    resposta(['ok' => true, 'id' => (string) $id], 201);
}

// POST /api/reviews/{id}/respostas   { texto, parentId? }   -> substitui enviarResposta()
function respostas_criar(string $idReview): void
{
    $u = exigir_login();
    $idReview = id_rota($idReview);
    $review = linha('SELECT id_comentario, id_filme FROM comentario WHERE id_comentario = ? AND id_comentario_pai IS NULL', [$idReview]);
    if (!$review) erro('Avaliação não encontrada.', 404);

    $c = corpo();
    $texto = campo_texto($c, 'texto');
    if (tam($texto) < 3) erro('Escreva uma resposta com pelo menos 3 caracteres.', 422);
    if (tam($texto) > 2000) erro('A resposta pode ter no máximo 2000 caracteres.', 422);

    // Se respondendo a outra resposta, "achata" para no máximo 2 níveis (igual ao front).
    $pai = $idReview;
    $parentId = $c['parentId'] ?? null;
    if ($parentId !== null && $parentId !== '') {
        $parentId = (int) $parentId;
        $p = linha('SELECT id_comentario, id_comentario_pai FROM comentario WHERE id_comentario = ? AND id_filme = ?',
                   [$parentId, $review['id_filme']]);
        if (!$p || $p['id_comentario_pai'] === null) erro('Resposta de origem não encontrada.', 404);
        if ((int) $p['id_comentario_pai'] === $idReview) {
            $pai = (int) $p['id_comentario'];
        } else {
            $avo = linha('SELECT id_comentario_pai FROM comentario WHERE id_comentario = ?', [$p['id_comentario_pai']]);
            if (!$avo || (int) $avo['id_comentario_pai'] !== $idReview) erro('Resposta de origem inválida.', 422);
            $pai = (int) $p['id_comentario_pai'];
        }
    }

    $id = (int) q(
        'INSERT INTO comentario (id_usuario, id_filme, id_comentario_pai, texto) VALUES (?, ?, ?, ?) RETURNING id_comentario',
        [$u['id_usuario'], $review['id_filme'], $pai, $texto]
    )->fetchColumn();
    resposta(['ok' => true, 'id' => (string) $id], 201);
}

// POST /api/comentarios/{id}/curtir   -> substitui toggleLike() e toggleLikeResposta()
function curtir_alternar(string $id): void
{
    $u = exigir_login();
    $id = id_rota($id);
    if (!linha('SELECT 1 FROM comentario WHERE id_comentario = ?', [$id])) erro('Comentário não encontrado.', 404);

    $removeu = q('DELETE FROM curtida WHERE id_usuario = ? AND id_comentario = ?', [$u['id_usuario'], $id])->rowCount();
    if ($removeu === 0) {
        q('INSERT INTO curtida (id_usuario, id_comentario) VALUES (?, ?) ON CONFLICT DO NOTHING', [$u['id_usuario'], $id]);
    }
    $total = (int) q('SELECT COUNT(*) FROM curtida WHERE id_comentario = ?', [$id])->fetchColumn();
    resposta(['curtido' => $removeu === 0, 'total' => $total]);
}
