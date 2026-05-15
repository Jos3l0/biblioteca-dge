<?php
/**
 * Exportador de recursos de Lectura
 * 6 grupos de filtros con categorías + etiquetas, incluyendo hijos recursivos
 */

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASSWORD', '1234');
define('DB_NAME', 'recursos_db');
define('DB_PREFIX', 'exfc47jf_');
define('OUTPUT_DIR', __DIR__ . '/material-json');
define('POSTS_PER_FILE', 400);
define('WP_URL', 'https://test-1.mendoza.edu.ar');

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($mysqli->connect_error) { die("Error: " . $mysqli->connect_error); }
$mysqli->set_charset('utf8mb4');

// ---- Configuración de filtros ----
$FILTER_GROUPS = [
    'lectura' => [
        'pequenos-lectores',
        'grandes-lectores',
        'recursos-fluidez-lectora',
        'recursos-de-alfabetizacion',
        'puentes-de-lectura',
        'texto-literarios',
    ],
];

// ---- Función: limpiar HTML ----
function clean_html($html) {
    if (!$html) return '';
    $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
    $html = preg_replace('/<link[^>]*rel=["\']stylesheet["\'][^>]*>/i', '', $html);
    $html = preg_replace('/<iframe[^>]*src=["\'][^"\']*viewer\.html[^"\']*["\'][^>]*>.*?<\/iframe>/is', '', $html);
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    return trim($html);
}

// ---- Función: texto plano ----
function strip_tags_content($html) {
    if (!$html) return '';
    $html = preg_replace('/\[[^\]]*\]/', ' ', $html);
    $html = strip_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = preg_replace('/\s+/', ' ', $html);
    return trim($html);
}

// ---- Función: hijos recursivos ----
function getTermDescendants($term_id, $mysqli) {
    $all = [$term_id];
    $sql = "SELECT term_id FROM " . DB_PREFIX . "term_taxonomy WHERE parent = ? AND taxonomy = 'category'";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $term_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $all = array_merge($all, getTermDescendants($row['term_id'], $mysqli));
    }
    return array_unique($all);
}

// ---- Función: obtener todos los términos de un post ----
function getPostTerms($post_id, $mysqli) {
    $sql = "SELECT t.name, t.slug, tt.taxonomy
            FROM " . DB_PREFIX . "term_relationships tr
            JOIN " . DB_PREFIX . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            JOIN " . DB_PREFIX . "terms t ON tt.term_id = t.term_id
            WHERE tr.object_id = ? AND tt.taxonomy IN ('category', 'post_tag')";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $post_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $terms = [];
    while ($row = $res->fetch_assoc()) { $terms[] = $row; }
    return $terms;
}

// ---- Obtener posts ----
echo "Obteniendo posts publicados...\n";
$sql = "SELECT ID, post_title, post_content, post_date, post_name
        FROM " . DB_PREFIX . "posts
        WHERE post_type = 'post' AND post_status = 'publish'
        ORDER BY post_date DESC, ID DESC";
$result = $mysqli->query($sql);
$posts = $result->fetch_all(MYSQLI_ASSOC);
echo "Total posts: " . count($posts) . "\n";

if (!is_dir(OUTPUT_DIR)) { mkdir(OUTPUT_DIR, 0755, true); }

// ---- Pre-calcular filtro por post ----
echo "Calculando categorías por post...\n";
$filterPostIds = []; // [slug => [post_id, ...]]
$allFilterSlugs = $FILTER_GROUPS['lectura'];

foreach ($posts as $post) {
    $post_id = (int)$post['ID'];
    $terms = getPostTerms($post_id, $mysqli);
    $postSlugs = array_column($terms, 'slug');

    foreach ($allFilterSlugs as $slug) {
        // Buscar si el post tiene alguno de los slugs (directo o en hijos)
        foreach ($terms as $term) {
            if ($term['slug'] === $slug) {
                $filterPostIds[$slug][] = $post_id;
                break;
            }
        }
    }
}

// ---- Exportar posts en chunks ----
echo "Exportando posts...\n";
$postChunks = array_chunk($posts, POSTS_PER_FILE);
$postFiles = [];

foreach ($postChunks as $chunkIndex => $chunk) {
    $postsData = [];
    foreach ($chunk as $post) {
        $post_id = (int)$post['ID'];

        // Imagen destacada
        $thumb_id = null;
        $sql_meta = "SELECT meta_value FROM " . DB_PREFIX . "postmeta WHERE post_id = ? AND meta_key = '_thumbnail_id'";
        $stmt_meta = $mysqli->prepare($sql_meta);
        $stmt_meta->bind_param('i', $post_id);
        $stmt_meta->execute();
        $res_meta = $stmt_meta->get_result();
        if ($row_meta = $res_meta->fetch_assoc()) { $thumb_id = (int)$row_meta['meta_value']; }

        $featured_image_url = null;
        if ($thumb_id) {
            $sql_img = "SELECT guid FROM " . DB_PREFIX . "posts WHERE ID = ? AND post_type = 'attachment'";
            $stmt_img = $mysqli->prepare($sql_img);
            $stmt_img->bind_param('i', $thumb_id);
            $stmt_img->execute();
            $res_img = $stmt_img->get_result();
            if ($row_img = $res_img->fetch_assoc()) {
                $featured_image_url = str_replace('http://localhost/recursos', WP_URL, $row_img['guid']);
            }
        }

        // Términos del post
        $terms = getPostTerms($post_id, $mysqli);
        $searchBlobParts = [];

        $postData = [
            'id' => $post_id,
            'title' => $post['post_title'],
            'slug' => $post['post_name'],
            'date' => $post['post_date'],
            'featured_image_url' => $featured_image_url,
            'content_html_clean' => str_replace('http://localhost/recursos', WP_URL, clean_html($post['post_content'])),
            'content_text' => str_replace('http://localhost/recursos', WP_URL, strip_tags_content($post['post_content'])),
            'lectura' => [],
        ];

        foreach ($FILTER_GROUPS as $group => $slugs) {
            foreach ($slugs as $slug) {
                $catData = array_values(array_filter($terms, fn($t) => $t['slug'] === $slug));
                if (!empty($catData)) {
                    $postData[$group][] = ['name' => $catData[0]['name'], 'slug' => $catData[0]['slug']];
                    $searchBlobParts[] = $catData[0]['name'];
                }
            }
        }
        $postData['search_blob'] = implode(' ', $searchBlobParts);
        $postsData[] = $postData;
    }

    $fileName = 'posts-' . ($chunkIndex + 1) . '.json';
    file_put_contents(OUTPUT_DIR . '/' . $fileName, json_encode($postsData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $postFiles[] = $fileName;
    echo "  - $fileName (" . count($postsData) . " posts)\n";
}

// ---- Generar índice de filtros ----
echo "Generando índices de filtros...\n";

$allSlugs = $FILTER_GROUPS['lectura'];
$index = [];

foreach ($allSlugs as $slug) {
    // Obtener nombre del término (de category o post_tag)
    $sql = "SELECT t.name, t.term_id FROM " . DB_PREFIX . "terms t
            JOIN " . DB_PREFIX . "term_taxonomy tt ON t.term_id = tt.term_id
            WHERE t.slug = ? AND tt.taxonomy IN ('category', 'post_tag') LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $res = $stmt->get_result();
    $term = $res->fetch_assoc();

    if (!$term) {
        echo "  [WARN] Término no encontrado: $slug\n";
        $index[$slug] = ['name' => $slug, 'total_posts' => 0, 'ids' => []];
        continue;
    }

    $termId = (int)$term['term_id'];
    $termName = $term['name'];

    // Contar posts: término directo + hijos
    $allTermIds = getTermDescendants($termId, $mysqli);
    $termTaxonomy = 'category';
    $placeholders = implode(',', array_fill(0, count($allTermIds), '?'));

    $sql2 = "SELECT DISTINCT tr.object_id FROM " . DB_PREFIX . "term_relationships tr
             JOIN " . DB_PREFIX . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             WHERE tt.term_id IN ($placeholders)";
    $stmt2 = $mysqli->prepare($sql2);
    $types = str_repeat('i', count($allTermIds));
    $stmt2->bind_param($types, ...$allTermIds);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    $ids = [];
    while ($row = $res2->fetch_assoc()) { $ids[] = (int)$row['object_id']; }

    // También agregar posts que tienen etiquetas directas (no en hijos)
    $sql3 = "SELECT DISTINCT tr.object_id FROM " . DB_PREFIX . "term_relationships tr
             JOIN " . DB_PREFIX . "term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             WHERE tt.term_id = ?";
    $stmt3 = $mysqli->prepare($sql3);
    $stmt3->bind_param('i', $termId);
    $stmt3->execute();
    $res3 = $stmt3->get_result();
    while ($row = $res3->fetch_assoc()) {
        if (!in_array((int)$row['object_id'], $ids)) $ids[] = (int)$row['object_id'];
    }

    $index[$slug] = [
        'name' => $termName,
        'total_posts' => count($ids),
        'ids' => $ids,
    ];
    echo "  - $termName: " . count($ids) . " posts\n";
}

file_put_contents(OUTPUT_DIR . '/material-lectura.json', json_encode($index, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$indexFile = [
    'version' => '1.0',
    'generated_at' => date('c'),
    'source' => ['tag_slug' => 'material', 'post_status' => 'publish'],
    'counts' => ['posts' => array_sum(array_column($index, 'total_posts')), 'lectura' => count($FILTER_GROUPS['lectura'])],
    'files' => ['posts' => $postFiles, 'lectura' => 'material-lectura.json'],
];
file_put_contents(OUTPUT_DIR . '/index.json', json_encode($indexFile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "  - index.json\n";

echo "\n=== Exportación completa ===\n";
echo "Posts: " . count($posts) . "\n";
echo "Archivos de posts: " . count($postFiles) . "\n";
echo "Lectura: " . count($FILTER_GROUPS['lectura']) . " términos\n";

$mysqli->close();