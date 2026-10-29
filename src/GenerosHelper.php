<?php

if (!function_exists('curlJson')) {
    function curlJson(string $url): array {
        $GLOBALS['curl_ultimo_error'] = '';
        $resp = false;
        $http = 0;

        for ($intento = 0; $intento < 2; $intento++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_USERAGENT      => 'ReadsApp/1.0 (contacto@tudominio.com)',
                CURLOPT_SSL_VERIFYPEER => ($intento === 0),
                CURLOPT_SSL_VERIFYHOST => ($intento === 0) ? 2 : 0,
            ]);
            $resp  = curl_exec($ch);
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno && $intento === 0 && in_array($errno, [35, 51, 58, 60, 77], true)) continue;
            if ($errno) {
                $GLOBALS['curl_ultimo_error'] = "cURL {$errno}: {$err}";
                return [];
            }
            break;
        }

        if ($http === 429) { $GLOBALS['curl_ultimo_error'] = 'HTTP 429: límite de peticiones de Google, espera un poco'; return []; }
        if ($http >= 400)  { $GLOBALS['curl_ultimo_error'] = "HTTP {$http}"; return []; }

        return $resp ? (json_decode($resp, true) ?: []) : [];
    }
}

// Reglas para las categorías de las APIs: género en español => patrón (inglés o español)
function reglasGeneros(): array {
    return [
        'Fantasía'            => 'fantas',
        'Ciencia ficción'     => 'science fiction|sci-fi|ciencia ficci',
        'Romance'             => 'romanc',
        'Thriller'            => 'thriller|suspens',
        'Misterio y crimen'   => 'mystery|crime|detective|misterio|polic[ií]a',
        'Terror'              => 'horror|terror',
        'Histórica'           => 'histor',
        'Juvenil'             => 'young adult|juvenile|teen|juvenil',
        'Distopía'            => 'dystop|dist[oó]p',
        'Aventuras'           => 'adventure|aventur',
        'Humor'               => 'humor|humour|comed',
        'Biografías'          => 'biograph|biograf|memoir',
        'Desarrollo personal' => 'self-help|autoayuda|personal growth|desarrollo personal|psycholog',
        'Poesía'              => 'poetry|poes',
        'Drama'               => 'drama',
        'Cómic y manga'       => 'comic|c[oó]mic|graphic novel|manga',
        'Paranormal'          => 'paranormal|vampir|supernatural',
        'Erótica'             => 'erotic|er[oó]tic',
        'Contemporánea'       => 'contempor',
    ];
}

// Pasa los textos de género (en inglés o español) a géneros en español, máximo 3 por libro.
function normalizarGeneros(array $tokens): array {
    $gen = [];
    foreach ($tokens as $t) {
        $t = trim((string)$t);
        if ($t === '' || $t === '-' || $t === '[manual]') continue;
        foreach (reglasGeneros() as $nombre => $patron) {
            if (preg_match('/(?:' . $patron . ')/iu', $t)) $gen[$nombre] = true;
        }
    }
    return array_slice(array_keys($gen), 0, 3);
}

function separarGeneros(string $raw): array {
    return array_values(array_filter(array_map('trim', preg_split('/[,;|\/]+/u', $raw) ?: [])));
}

function generoEsManual(?string $valor): bool {
    return str_contains((string)$valor, '[manual]');
}

function generoPendiente(?string $valor): bool {
    $v = trim((string)$valor);
    if ($v === '') return true;
    if ($v === '-' || generoEsManual($v)) return false;
    return (bool)preg_match('/Literatura|Otros|Ficción/u', $v) || !normalizarGeneros(separarGeneros($v));
}

function condicionGeneroPendiente(string $col): string {
    return "(`{$col}` IS NULL OR `{$col}` = '' OR (`{$col}` NOT LIKE '%[manual]%' AND (`{$col}` LIKE '%Literatura%' OR `{$col}` LIKE '%Otros%' OR `{$col}` LIKE '%Ficción%')))";
}

// Guarda un género elegido por el usuario 
function valorGeneroManual(array $generos): string {
    $generos = array_slice(array_values(array_unique($generos)), 0, 3);
    return $generos ? implode(', ', $generos) . ', [manual]' : '-';
}

function soloGenerosNucleo(array $generos): array {
    $nucleo = ['Fantasía', 'Ciencia ficción', 'Romance', 'Thriller', 'Misterio y crimen', 'Terror', 'Juvenil', 'Distopía', 'Paranormal', 'Erótica', 'Histórica'];
    return array_values(array_intersect($generos, $nucleo));
}

// Deduce géneros a partir del título y la sinopsis.
function inferirGenerosDeTexto(string $texto): array {
    $texto = mb_strtolower(html_entity_decode(strip_tags($texto), ENT_QUOTES, 'UTF-8'), 'UTF-8');
    if (mb_strlen($texto) < 60) return [];

    // géneros
    $claves = [
        'Fantasía' => [
            'fantas|romantasy|\bfae\b|\bfey\b|dragon|drag[oó]n|hechicer|brujer',
            'magia|m[aá]gic|magic|bruj|witch|wizard|elfo|hada\b|reino|kingdom|maldici|curse|profec|prophec|sorcer',
        ],
        'Romance' => [
            'romanc|romantasy|enemies to lovers|enemigos a amantes|slow burn|dark romance|rom-com|comedia rom[aá]ntica',
            'enamor|\bamor\b|\blove\b|pasi[oó]n|passion|boda|wedding|beso|kiss|deseo|desire',
        ],
        'Ciencia ficción' => [
            'ciencia ficci|science fiction|sci-fi|space opera|cyberpunk',
            'galaxi|nave espacial|spaceship|androide|robot|alien|extraterrest|planeta|planet|inteligencia artificial',
        ],
        'Thriller' => [
            'thriller|suspens',
            'asesin|murder|killer|conspira|secuestr|psic[oó]pata|giro final',
        ],
        'Misterio y crimen' => [
            'misterio|mystery|detective|whodunit|novela negra|crime fiction',
            'investigaci|crimen|crime|desaparici|polic[ií]a|sospechos',
        ],
        'Terror' => [
            'horror|terror\b|novela de terror',
            'pesadilla|fantasma|ghost|haunted|embrujad|miedo',
        ],
        'Histórica' => [
            'novela hist[oó]ric|historical fiction|ficci[oó]n hist[oó]rica',
            'siglo|century|segunda guerra|world war|medieval',
        ],
        'Juvenil' => [
            'young adult|novela juvenil|literatura juvenil',
            'adolescent|instituto|high school|\bteen',
        ],
        'Distopía' => [
            'distop|dystop',
            'totalitar|r[eé]gimen|apocal',
        ],
        'Aventuras' => [
            'novela de aventuras|adventure novel',
            'aventur|adventure|expedici|\bquest\b',
        ],
        'Desarrollo personal' => [
            'autoayuda|self-help|desarrollo personal',
            'h[aá]bitos|habits|productiv|mindset',
        ],
        'Paranormal' => [
            'paranormal|vampir|lic[aá]ntrop|werewolf',
            'demoni|demon|sobrenatural|supernatural|[aá]ngel',
        ],
        'Erótica' => [
            'er[oó]tic|erotic|spicy|dark romance',
            '',
        ],
        'Contemporánea' => [
            'contempor',
            '',
        ],
    ];

    $puntos = [];
    foreach ($claves as $genero => [$explicitas, $indicios]) {
        $p = 0;
        foreach (explode('|', $explicitas) as $t) {
            if ($t !== '' && preg_match('/' . $t . '/iu', $texto)) $p += 4;
        }
        foreach (explode('|', $indicios) as $t) {
            if ($t !== '' && preg_match('/' . $t . '/iu', $texto)) $p += 1;
        }
        if ($p >= 4) $puntos[$genero] = $p;
    }
    arsort($puntos);
    return array_slice(array_keys($puntos), 0, 2);
}

// Parámetro de la clave de Google Books
function claveGoogleBooks(): string {
    $k = function_exists('config') ? (string)config('google_books_key', '') : '';
    return $k !== '' ? '&key=' . urlencode($k) : '';
}

// Busca las categorías del libro en Google Books y, si no hay, en Open Library
function buscarGenerosOnline(string $titulo, string $autores, string $libroId): array {
    return buscarDatosOnline($titulo, $autores, $libroId)['categorias'];
}
// Busca categorías y sinopsis del libro. 'error' solo se rellena con fallos TEMPORALES
function buscarDatosOnline(string $titulo, string $autores, string $libroId): array {
    $res = ['categorias' => [], 'descripcion' => '', 'error' => ''];

    $anotar = function () use (&$res) {
        $e = (string)($GLOBALS['curl_ultimo_error'] ?? '');
        if ($e !== '' && preg_match('/^(cURL|HTTP 429|HTTP 5)/', $e)) $res['error'] = $e;
    };
    $tomar = function (array $vol) use (&$res) {
        if (!$res['categorias'] && !empty($vol['categories'])) $res['categorias'] = (array)$vol['categories'];
        if ($res['descripcion'] === '' && !empty($vol['description'])) $res['descripcion'] = (string)$vol['description'];
    };
    $yaHayGenero = fn() => (bool)normalizarGeneros($res['categorias']);

    $tituloLimpio = trim(preg_replace('/\s+/', ' ', preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $titulo)));
    $autor = trim(explode(',', $autores)[0] ?? '');
    $tl  = mb_strtolower($tituloLimpio, 'UTF-8');
    $key = claveGoogleBooks();

    // 1. Google Books por ISBN o por ID de Google
    if (preg_match('/^(\d{9}[\dXx]|\d{13})$/', $libroId)) {
        $json = curlJson('https://www.googleapis.com/books/v1/volumes?q=' . urlencode('isbn:' . $libroId) . $key);
        $anotar();
        $tomar($json['items'][0]['volumeInfo'] ?? []);
    } elseif (preg_match('/^[A-Za-z0-9_-]{12}$/', $libroId)) {
        $json = curlJson('https://www.googleapis.com/books/v1/volumes/' . urlencode($libroId) . ($key !== '' ? '?' . ltrim($key, '&') : ''));
        $anotar();
        $tomar($json['volumeInfo'] ?? []);
    }
    if ($yaHayGenero()) return $res;

    // 2. Google Books por título + autor
    $q = 'intitle:"' . $tituloLimpio . '"' . ($autor !== '' ? ' inauthor:"' . $autor . '"' : '');
    $json = curlJson('https://www.googleapis.com/books/v1/volumes?maxResults=5&q=' . urlencode($q) . $key);
    $anotar();
    foreach ($json['items'] ?? [] as $item) {
        $vol = $item['volumeInfo'] ?? [];
        $t = mb_strtolower($vol['title'] ?? '', 'UTF-8');
        if ($t === '') continue;
        similar_text($tl, $t, $pct);
        if ($pct >= 60 || str_contains($t, $tl) || str_contains($tl, $t)) {
            $tomar($vol);
            if ($yaHayGenero()) return $res;
        }
    }

    // 3. Open Library (temas del libro)
    $params = ['title' => $tituloLimpio, 'limit' => 3, 'fields' => 'title,subject'];
    if ($autor !== '') $params['author'] = $autor;
    $json = curlJson('https://openlibrary.org/search.json?' . http_build_query($params));
    $anotar();
    foreach ($json['docs'] ?? [] as $doc) {
        $s = $doc['subject'] ?? [];
        $t = mb_strtolower($doc['title'] ?? '', 'UTF-8');
        if (!$s || $t === '') continue;
        similar_text($tl, $t, $pct);
        if ($pct >= 60 || str_contains($t, $tl) || str_contains($tl, $t)) {
            $nucleo = soloGenerosNucleo(normalizarGeneros(array_slice($s, 0, 40)));
            if ($nucleo) { $res['categorias'] = $nucleo; return $res; }
        }
    }

    return $res;
}
// Columnas de listas_lectura en minúsculas => nombre real
function columnasListasLectura(PDO $pdo): array {
    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM listas_lectura")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[strtolower($c['Field'])] = $c['Field'];
    }
    return $cols;
}

// Columna donde se guardan los géneros: usa una existente o crea "generos"
function columnaGeneros(PDO $pdo): ?string {
    try {
        $existentes = columnasListasLectura($pdo);
        foreach (['generos', 'genero', 'genres', 'genre'] as $cand) {
            if (isset($existentes[$cand])) return $existentes[$cand];
        }
        $pdo->exec("ALTER TABLE listas_lectura ADD COLUMN generos VARCHAR(255) NULL");
        return 'generos';
    } catch (Exception $e) {
        return null;
    }
}

// Detecta los géneros de un libro a partir de lo que ya se ha descargado de la API
function detectarGenerosLibro(array $libroAPI, string $titulo, string $autor, string $id, string $descripcion, bool $online = false): array {
    // 1. Categorías de la propia API 
    if (isset($libroAPI['volumeInfo'])) {
        $cats = array_values(array_filter((array)($libroAPI['volumeInfo']['categories'] ?? []), 'is_string'));
        $gens = normalizarGeneros($cats);
    } else {
        $subjects = array_slice(array_values(array_filter(array_merge((array)($libroAPI['subjects'] ?? []), (array)($libroAPI['subject'] ?? [])), 'is_string')), 0, 40);
        $gens = soloGenerosNucleo(normalizarGeneros($subjects));
    }
    if ($gens) return $gens;

    // 2. Búsqueda extra en Google Books / Open Library 
    if ($online) {
        $gens = normalizarGeneros(buscarGenerosOnline($titulo, $autor, $id));
        if ($gens) return $gens;
    }

    // 3. Sinopsis
    $desc = trim(strip_tags($descripcion));
    if ($desc !== '' && $desc !== 'Sin descripción disponible.') {
        return inferirGenerosDeTexto($titulo . ' ' . $desc);
    }
    return [];
}

// Guarda los géneros en el libro del usuario solo si todavía no tiene ninguno válido
function guardarGenerosSiVacios(PDO $pdo, int $usuarioId, int $registroId, string $col, array $generos): void {
    if (!$generos) return;
    $pdo->prepare("UPDATE listas_lectura SET `{$col}` = ? WHERE id = ? AND usuario_id = ? AND " . condicionGeneroPendiente($col))
        ->execute([implode(', ', $generos), $registroId, $usuarioId]);
}

// Emoji y color de cada género (se usan en el mapa y en la página de detalle)
function estiloGenero(string $nombre): array {
    static $estilos = [
        'Fantasía' => ['🐉', '#7c4dcc'], 'Romance' => ['💕', '#e0508a'], 'Thriller' => ['🔪', '#3d4b6e'],
        'Misterio y crimen' => ['🔍', '#506683'], 'Terror' => ['👻', '#7a2a3c'], 'Ciencia ficción' => ['🚀', '#2aa6c9'],
        'Histórica' => ['🏰', '#b8803c'], 'Juvenil' => ['🎒', '#f0a23a'], 'Distopía' => ['🏚️', '#6b8294'],
        'Aventuras' => ['🧭', '#3fa56b'], 'Humor' => ['😄', '#e9b72c'], 'Biografías' => ['👤', '#8a6a4f'],
        'Desarrollo personal' => ['🌱', '#4fae98'], 'Poesía' => ['🪶', '#c77dd1'], 'Drama' => ['🎭', '#d46a4b'],
        'Cómic y manga' => ['💥', '#ef6c6c'], 'Paranormal' => ['🦇', '#8250a8'], 'Erótica' => ['🌶️', '#c2185b'],
        'Contemporánea' => ['🏙️', '#4f8fd6'],
    ];
    return $estilos[$nombre] ?? ['📚', '#8892a6'];
}

// Nota exacta para mostrar: 4.35 -> "4,35", 4.5 -> "4,5", 4 -> "4"
if (!function_exists('formatoNota')) {
    function formatoNota(float $v): string {
        return str_replace('.', ',', rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'));
    }
}