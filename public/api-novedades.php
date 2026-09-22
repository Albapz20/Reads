<?php
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');

// Obtener automáticamente el mes y año actual
$meses = [
    '01' => 'septiembre', // Mapeo de meses en español
    '01' => 'enero', '02' => 'febrero', '03' => 'marzo', '04' => 'abril',
    '05' => 'mayo', '06' => 'junio', '07' => 'julio', '08' => 'agosto',
    '09' => 'septiembre', '10' => 'octubre', '11' => 'noviembre', '12' => 'diciembre'
];

$anioActual = date('Y'); // 2026
$mesNum = date('m');    // 09
$nombreMes = $meses[$mesNum] ?? 'este mes';
$periodoActual = "$anioActual-$mesNum"; // 2026-09

function consultarGoogleApi($url) {
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

//  Consultas enfáticas para publicaciones del año/mes en curso
$queries = [
    "q=publishedDate:{$periodoActual}&langRestrict=es&maxResults=10",
    "q=novela+{$anioActual}&langRestrict=es&orderBy=newest&maxResults=15",
    "q=editorial+{$anioActual}&langRestrict=es&maxResults=15"
];

$novedades = [];
$titulosProcesados = [];

foreach ($queries as $q) {
    if (count($novedades) >= 8) break;

    $url = "https://www.googleapis.com/books/v1/volumes?" . $q;
    $json = consultarGoogleApi($url);

    if ($json) {
        $data = json_decode($json, true);
        if (!empty($data['items'])) {
            foreach ($data['items'] as $item) {
                $info = $item['volumeInfo'] ?? [];
                $titulo = $info['title'] ?? '';
                $autor = !empty($info['authors']) ? $info['authors'][0] : 'Autor desconocido';
                $fechaPub = $info['publishedDate'] ?? '';
                $tLower = mb_strtolower(trim($titulo));

                // Filtrar estrictamente que la fecha pertenezca al año actual (2026)
                $esAnioActual = (strpos($fechaPub, (string)$anioActual) === 0);

                // Obtener ISBN para portada limpia de OpenLibrary
                $isbn = '';
                if (!empty($info['industryIdentifiers'])) {
                    foreach ($info['industryIdentifiers'] as $idnt) {
                        if (in_array($idnt['type'] ?? '', ['ISBN_13', 'ISBN_10'])) {
                            $isbn = $idnt['identifier'] ?? '';
                            break;
                        }
                    }
                }

                $portada = '';
                if (!empty($isbn)) {
                    $portada = "https://covers.openlibrary.org/b/isbn/{$isbn}-M.jpg";
                } else {
                    $portada = $info['imageLinks']['thumbnail'] ?? $info['imageLinks']['smallThumbnail'] ?? '';
                    if ($portada) $portada = str_replace('http://', 'https://', $portada);
                }

                if (!empty($titulo) && $esAnioActual && !in_array($tLower, $titulosProcesados)) {
                    $titulosProcesados[] = $tLower;
                    $novedades[] = [
                        'key' => $item['id'],
                        'id' => $item['id'],
                        'title' => $titulo,
                        'titulo' => $titulo,
                        'author_name' => [$autor],
                        'autor' => $autor,
                        'cover_url' => $portada,
                        'portada' => $portada,
                        'fecha' => $fechaPub
                    ];
                }
            }
        }
    }
}

// Salida JSON única, limpia y estructurada
echo json_encode([
    'success' => true,
    'mes' => ucfirst($nombreMes),
    'anio' => $anioActual,
    'tituloSeccion' => "Novedades de " . $nombreMes . " " . $anioActual,
    'docs' => $novedades,
    'libros' => $novedades
], JSON_UNESCAPED_UNICODE);