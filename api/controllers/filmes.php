<?php
declare(strict_types=1);

/** SELECT base de filme com gêneros, média e total de avaliações. $cols/$joins permitem estender. */
function sql_filme(string $cols = '', string $joins = ''): string
{
    return "SELECT f.id_filme, f.slug, f.titulo, f.sinopse, f.ano_lancamento, f.duracao, f.poster_url,
        COALESCE((SELECT string_agg(g.nome_genero, ', ' ORDER BY g.nome_genero)
                  FROM possui p JOIN genero g ON g.id_genero = p.id_genero
                  WHERE p.id_filme = f.id_filme), '') AS genero,
        (SELECT ROUND(AVG(a.nota), 1) FROM avaliacao a WHERE a.id_filme = f.id_filme) AS media,
        (SELECT COUNT(*) FROM avaliacao a WHERE a.id_filme = f.id_filme) AS total
        $cols
        FROM filme f $joins";
}

function filme_json(array $f): array
{
    return [
        'id'           => $f['slug'],
        'idInterno'    => (int) $f['id_filme'],
        'titulo'       => $f['titulo'],
        'genero'       => $f['genero'],
        'ano'          => $f['ano_lancamento'] !== null ? (int) $f['ano_lancamento'] : null,
        'duracao'      => $f['duracao'] !== null ? (int) $f['duracao'] : null,
        'imagem'       => $f['poster_url'],
        'descricao'    => $f['sinopse'],
        'media'        => $f['media'],           // "9.0" ou null
        'totalReviews' => (int) $f['total'],
    ];
}

function filme_por_slug(string $slug): array
{
    $f = linha(sql_filme() . ' WHERE f.slug = ?', [$slug]);
    if (!$f) erro('Filme não encontrado.', 404);
    return $f;
}

// GET /api/filmes   -> substitui o array "filmes" + calcularMedia/contarReviews
function filmes_listar(): void
{
    $f = linhas(sql_filme() . ' ORDER BY f.id_filme');
    resposta(['filmes' => array_map('filme_json', $f)]);
}

// GET /api/filmes/{slug}
function filmes_obter(string $slug): void
{
    resposta(['filme' => filme_json(filme_por_slug($slug))]);
}

// GET /api/destaque-semana   -> substitui getDestaqueSemana()
function filmes_destaque(): void
{
    $f = linha(
        sql_filme('', "JOIN (SELECT a.id_filme, AVG(a.nota) m, COUNT(*) c FROM avaliacao a
                             WHERE a.data_avaliacao >= now() - interval '7 days' GROUP BY a.id_filme) x
                       ON x.id_filme = f.id_filme") . ' ORDER BY x.m DESC, x.c DESC LIMIT 1'
    ) ?? linha(
        sql_filme('', 'JOIN (SELECT id_filme, AVG(nota) m FROM avaliacao GROUP BY id_filme) x ON x.id_filme = f.id_filme')
        . ' ORDER BY x.m DESC LIMIT 1'
    ) ?? linha(sql_filme() . ' ORDER BY f.id_filme LIMIT 1');

    if (!$f) erro('Nenhum filme cadastrado.', 404);
    resposta(['filme' => filme_json($f)]);
}

function escapar_like(string $s): string
{
    return addcslashes($s, '\\%_');
}

// GET /api/busca?q=termo&tipo=todos|filmes|usuarios   -> substitui buscarFilmes/buscarUsuariosLista
function busca_global(): void
{
    $termo = trim((string) ($_GET['q'] ?? ''));
    $tipo  = (string) ($_GET['tipo'] ?? 'todos');
    if ($termo === '' || tam($termo) > 100) resposta(['filmes' => [], 'usuarios' => []]);
    $like = '%' . escapar_like($termo) . '%';

    $filmes = [];
    if ($tipo !== 'usuarios') {
        $filmes = array_map('filme_json', linhas(
            sql_filme() . " WHERE f.titulo ILIKE ?
                OR CAST(f.ano_lancamento AS TEXT) LIKE ?
                OR EXISTS (SELECT 1 FROM possui p JOIN genero g ON g.id_genero = p.id_genero
                           WHERE p.id_filme = f.id_filme AND g.nome_genero ILIKE ?)
                ORDER BY f.titulo LIMIT 8", [$like, $like, $like]));
    }
    $usuarios = [];
    if ($tipo !== 'filmes') {
        $usuarios = array_map('usuario_json', linhas(
            'SELECT id_usuario, nome, avatar FROM usuario WHERE nome ILIKE ? ORDER BY nome LIMIT 8', [$like]));
    }
    resposta(['filmes' => $filmes, 'usuarios' => $usuarios]);
}

function gerar_slug(string $titulo): string
{
    $s = function_exists('iconv') ? (iconv('UTF-8', 'ASCII//TRANSLIT', $titulo) ?: $titulo) : $titulo;
    $s = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $s), '-'));
    return $s !== '' ? $s : 'filme';
}

// POST /api/filmes  (somente admin)  { titulo, sinopse, ano, duracao, poster_url, generos: ["Drama"] }
function filmes_criar(): void
{
    $admin = exigir_admin();
    $c = corpo();
    $titulo = campo_texto($c, 'titulo');
    if ($titulo === '' || tam($titulo) > 200) erro('Informe um título válido.', 422);

    $ano = isset($c['ano']) ? (int) $c['ano'] : null;
    $dur = isset($c['duracao']) ? (int) $c['duracao'] : null;
    $poster = campo_texto($c, 'poster_url');
    if ($poster !== '' && !filter_var($poster, FILTER_VALIDATE_URL)) erro('URL do pôster inválida.', 422);
    if ($poster !== '' && !preg_match('#^https?://#i', $poster)) erro('URL do pôster inválida.', 422);

    $slug = gerar_slug($titulo);
    if (linha('SELECT 1 FROM filme WHERE slug = ?', [$slug])) $slug .= '-' . ($ano ?: substr(bin2hex(random_bytes(2)), 0, 4));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $id = (int) q(
            'INSERT INTO filme (slug, titulo, sinopse, ano_lancamento, duracao, poster_url, id_admin)
             VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id_filme',
            [$slug, $titulo, campo_texto($c, 'sinopse') ?: null, $ano, $dur, $poster ?: null, $admin['id_usuario']]
        )->fetchColumn();

        foreach ((array) ($c['generos'] ?? []) as $nome) {
            if (!is_string($nome) || trim($nome) === '') continue;
            $nome = trim($nome);
            $g = linha('SELECT id_genero FROM genero WHERE nome_genero = ?', [$nome])
                ?? linha('INSERT INTO genero (nome_genero) VALUES (?) RETURNING id_genero', [$nome]);
            q('INSERT INTO possui (id_filme, id_genero) VALUES (?, ?) ON CONFLICT DO NOTHING', [$id, $g['id_genero']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    resposta(['filme' => filme_json(filme_por_slug($slug))], 201);
}

// POST /api/filmes/{slug}/favorito   -> substitui toggleFavorito()
function favorito_alternar(string $slug): void
{
    $u = exigir_login();
    $f = filme_por_slug($slug);
    $ja = q('DELETE FROM favorito WHERE id_usuario = ? AND id_filme = ?', [$u['id_usuario'], $f['id_filme']])->rowCount();
    if ($ja === 0) {
        q('INSERT INTO favorito (id_usuario, id_filme) VALUES (?, ?) ON CONFLICT DO NOTHING', [$u['id_usuario'], $f['id_filme']]);
    }
    resposta(['favorito' => $ja === 0]);
}
