<?php
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');

require_once "../src/Database.php";

function obtenerContenidoUrl($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res) return $res;
    }
    
    $opts = [
        "http" => ["header" => "User-Agent: Mozilla/5.0\r\n"],
        "ssl"  => ["verify_peer" => false, "verify_peer_name" => false]
    ];
    return @file_get_contents($url, false, stream_context_create($opts));
}

// Portadas HD de OpenLibrary por ISBN (sin marcas grises de Google)
$respaldoGarantizado = [
    [
        'id' => 'zafon_1',
        'titulo' => 'El juego del ángel',
        'autor' => 'Carlos Ruiz Zafón',
        'portada' => 'https://covers.openlibrary.org/b/isbn/9788408081258-M.jpg'
    ],
    [
        'id' => 'zafon_2',
        'titulo' => 'El prisionero del cielo',
        'autor' => 'Carlos Ruiz Zafón',
        'portada' => 'https://covers.openlibrary.org/b/isbn/9788408105824-M.jpg'
    ],
    [
        'id' => 'zafon_3',
        'titulo' => 'El laberinto de los espíritus',
        'autor' => 'Carlos Ruiz Zafón',
        'portada' => 'https://covers.openlibrary.org/b/isbn/9788408163381-M.jpg'
    ],
    [
        'id' => 'zafon_4',
        'titulo' => 'La sombra del viento',
        'autor' => 'Carlos Ruiz Zafón',
        'portada' => 'https://covers.openlibrary.org/b/isbn/9788408043645-M.jpg'
    ],
    [
        'id' => 'falcones_1',
        'titulo' => 'La catedral del mar',
        'autor' => 'Ildefonso Falcones',
        'portada' => 'https://covers.openlibrary.org/b/isbn/9788408066279-M.jpg'
    ],
    [
        'id' => 'reverte_1',
        'titulo' => 'El problema final',
        'autor' => 'Arturo Pérez-Reverte',
        'portada' => 'https://covers.openlibrary.org/b/isbn/9788420476148-M.jpg'
    ]
];

try {
    $db = new Database();

    // Leer el último libro de la base de datos
    $stmt = $db->pdo->query("SELECT * FROM listas_lectura ORDER BY id DESC LIMIT 1");
    $ultimo = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $autor = trim($ultimo['autores'] ?? $ultimo['autor'] ?? $ultimo['author'] ?? '');
    $titulo = trim($ultimo['titulo'] ?? $ultimo['title'] ?? '');

    $libros = [];

    // Buscar en Google Books para obtener metadatos e ISBN
    $query = !empty($autor) ? "inauthor:\"$autor\"" : (!empty($titulo) ? "intitle:\"$titulo\"" : "novela narrativa espanol");
    $url = "https://www.googleapis.com/books/v1/volumes?q=" . urlencode($query) . "&langRestrict=es&maxResults=8";
    
    $json = obtenerContenidoUrl($url);

    if ($json) {
        $data = json_decode($json, true);
        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $info = $item['volumeInfo'] ?? [];
                $t = $info['title'] ?? '';
                $a = !empty($info['authors']) ? implode(', ', $info['authors']) : ($autor ?: 'Varios autores');
                
                // Extraer ISBN para consultar la portada real en OpenLibrary
                $isbn = '';
                if (!empty($info['industryIdentifiers'])) {
                    foreach ($info['industryIdentifiers'] as $idnt) {
                        if (in_array($idnt['type'] ?? '', ['ISBN_13', 'ISBN_10'])) {
                            $isbn = $idnt['identifier'] ?? '';
                            break;
                        }
                    }
                }

                $img = '';
                if (!empty($isbn)) {
                    $img = "https://covers.openlibrary.org/b/isbn/{$isbn}-M.jpg";
                } else {
                    $img = $info['imageLinks']['thumbnail'] ?? $info['imageLinks']['smallThumbnail'] ?? '';
                    if ($img) $img = str_replace('http://', 'https://', $img);
                }

                if (!empty($t) && !empty($img)) {
                    $libros[] = [
                        'id' => $item['id'],
                        'titulo' => $t,
                        'autor' => $a,
                        'portada' => $img
                    ];
                }
            }
        }
    }

    // Si no hay resultados o portadas devueltas, recurrir al catálogo garantizado por ISBN
    if (empty($libros)) {
        $libros = $respaldoGarantizado;
    }

    echo json_encode([
        'success' => true,
        'ultimoAutor' => $autor,
        'ultimoTitulo' => $titulo,
        'libros' => array_slice($libros, 0, 8)
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo json_encode([
        'success' => true,
        'ultimoAutor' => 'Carlos Ruiz Zafón',
        'ultimoTitulo' => '',
        'libros' => $respaldoGarantizado
    ], JSON_UNESCAPED_UNICODE);
}