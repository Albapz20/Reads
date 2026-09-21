<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status' => 'ok']);

// Buscamos novedades en español publicadas recientemente en Google Books
$url = "https://www.googleapis.com/books/v1/volumes?q=subject:fiction&langRestrict=es&orderBy=newest&maxResults=10";

$contexto = stream_context_create([
    "ssl" => ["verify_peer" => false, "verify_peer_name" => false],
    "http" => ["header" => "User-Agent: Mozilla/5.0\r\n"]
]);

$json = @file_get_contents($url, false, $contexto);

if (!$json) {
    echo json_encode(['docs' => []]);
    exit;
}

$data = json_decode($json, true);
$items = $data['items'] ?? [];
$resultado = [];

foreach ($items as $item) {
    $info = $item['volumeInfo'] ?? [];
    $titulo = $info['title'] ?? 'Sin título';
    $autor = isset($info['authors'][0]) ? $info['authors'][0] : 'Autor desconocido';
    $idLibro = $item['id'] ?? '';
    
    // Obtenemos la portada de Google Books y nos aseguramos de que sea HTTPS
    $portada = $info['imageLinks']['thumbnail'] ?? $info['imageLinks']['smallThumbnail'] ?? '';
    if (!empty($portada)) {
        $portada = str_replace('http://', 'https://', $portada);
    }

    $resultado[] = [
        'title' => $titulo,
        'author_name' => [$autor],
        'key' => $idLibro,
        'cover_url' => $portada
    ];
}

echo json_encode(['docs' => $resultado]);