<?php
@set_time_limit(0);
@ini_set('memory_limit', '512M');

require_once "../src/Auth.php";
require_once "../src/Database.php";

$db = new Database();

// Obtener todos los libros de la lista
$sql = "SELECT id, titulo, autores, libro_id, portada FROM listas_lectura WHERE usuario_id = ?";
$usuario = Auth::usuario();
if (!$usuario) {
    die("Debes iniciar sesión para ejecutar la reparación.");
}

$stmt = $db->pdo->prepare("SELECT id, titulo, autores, libro_id, portada FROM listas_lectura WHERE usuario_id = ?");
$stmt->execute([$usuario['id']]);
$libros = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dirUploads = __DIR__ . '/uploads/portadas/';
if (!is_dir($dirUploads)) {
    @mkdir($dirUploads, 0777, true);
}

echo "<h1>Reparación con Validación Estricta de Autor</h1>";
echo "<p>Analizando <strong>" . count($libros) . "</strong> libros...</p><hr>";

$reparados = 0;
$corregidos = 0;

foreach ($libros as $libro) {
    $tituloOriginal = trim($libro['titulo']);
    $autorOriginal  = trim($libro['autores'] ?? '');

    // Limpieza de títulos (quita paréntesis de sagas y subtítulos largos)
    $tituloLimpio = preg_replace('/\s*\(.*?\)/', '', $tituloOriginal);
    $tituloLimpio = preg_replace('/[:\-–—].*$/', '', $tituloLimpio);
    $tituloLimpio = trim($tituloLimpio);

    // Extraer apellido o primer autor principal para validar
    $autorLimpio = extraerPrimerAutor($autorOriginal);

    // Obtener ISBN real si existe
    $rawId = preg_replace('/[^0-9X]/i', '', $libro['libro_id'] ?? '');
    $isbn  = (strlen($rawId) === 10 || strlen($rawId) === 13) ? $rawId : '';

    $nombreArchivo = (!empty($isbn) ? $isbn : 'gr_' . substr(md5($tituloOriginal), 0, 12)) . '.jpg';
    $rutaAbsoluta  = $dirUploads . $nombreArchivo;
    $rutaBD        = 'uploads/portadas/' . $nombreArchivo;

    $urlImagen = null;

    // Intento 1: Buscar por ISBN real
    if (!empty($isbn)) {
        $urlImagen = buscarGoogleStrict("https://www.googleapis.com/books/v1/volumes?q=isbn:" . urlencode($isbn), $autorLimpio, false);
    }

    // Intento 2: Buscar por Título + Autor (Validando que el autor coincida)
    if (!$urlImagen && !empty($tituloLimpio) && !empty($autorLimpio)) {
        $q = "intitle:\"" . urlencode($tituloLimpio) . "\"+inauthor:\"" . urlencode($autorLimpio) . "\"";
        $urlImagen = buscarGoogleStrict("https://www.googleapis.com/books/v1/volumes?q=" . $q, $autorLimpio, true);
    }

    // Intento 3: Búsqueda en Open Library por Título y Autor
    if (!$urlImagen && !empty($tituloLimpio)) {
        $urlImagen = buscarOpenLibraryStrict($tituloLimpio, $autorLimpio);
    }

    // Si encontramos una portada VALIDAD, la guardamos
    if ($urlImagen) {
        $imgData = descargarUrl($urlImagen);
        if ($imgData && strlen($imgData) > 1000) {
            file_put_contents($rutaAbsoluta, $imgData);
            
            $sqlUpdate = "UPDATE listas_lectura SET portada = ? WHERE id = ?";
            $stmtUpdate = $db->pdo->prepare($sqlUpdate);
            $stmtUpdate->execute([$rutaBD, $libro['id']]);
            
            echo "<p style='color:green;'>✓ Portada correcta asignada: <strong>" . htmlspecialchars($tituloOriginal) . "</strong> (" . htmlspecialchars($autorLimpio) . ")</p>";
            $reparados++;
            continue;
        }
    }

    // Si no se encuentra una coincidencia exacta de AUTOR, mantener o asignar default para evitar la portada equivocada
    if (strpos($libro['portada'], 'default') === false) {
        // Si tenía una portada potencialmente mala descargada, la reseteamos a default
        $sqlUpdate = "UPDATE listas_lectura SET portada = 'uploads/portadas/default.jpg' WHERE id = ?";
        $stmtUpdate = $db->pdo->prepare($sqlUpdate);
        $stmtUpdate->execute([$libro['id']]);
        if (file_exists($rutaAbsoluta)) {
            @unlink($rutaAbsoluta);
        }
        echo "<p style='color:orange;'>↺ Portada errónea eliminada (se asigna ficha con título): <strong>" . htmlspecialchars($tituloOriginal) . "</strong></p>";
        $corregidos++;
    } else {
        echo "<p style='color:gray;'>- Sin portada verificada para: <strong>" . htmlspecialchars($tituloOriginal) . "</strong> (Autor: " . htmlspecialchars($autorLimpio) . ")</p>";
    }
}

echo "<hr><h2>Proceso finalizado. Portadas correctas: $reparados. Portadas erróneas corregidas: $corregidos.</h2>";
echo "<a href='index.php'>Volver a la estantería</a>";

function extraerPrimerAutor($autor) {
    if (empty($autor)) return '';
    $partes = explode(',', $autor);
    $primerAutor = trim($partes[0]);
    // Eliminar conectores tipo "y", "and", "&"
    $palabras = explode(' ', $primerAutor);
    return end($palabras); // Retorna el apellido principal
}

function buscarGoogleStrict($apiUrl, $autorEsperado, $validarAutor = true) {
    $json = descargarUrl($apiUrl);
    if (!$json) return null;

    $data = json_decode($json, true);
    if (empty($data['items'])) return null;

    foreach ($data['items'] as $item) {
        $volume = $item['volumeInfo'] ?? [];
        $autoresAPI = implode(' ', $volume['authors'] ?? []);

        // Si se requiere validar autor y tenemos un apellido, comprobar coincidencia
        if ($validarAutor && !empty($autorEsperado)) {
            if (stripos($autoresAPI, $autorEsperado) === false) {
                continue; // Saltar si el autor devuelto por Google no coincide
            }
        }

        $imgs = $volume['imageLinks'] ?? [];
        if (!empty($imgs['thumbnail'])) {
            return str_replace('http://', 'https://', $imgs['thumbnail']);
        } elseif (!empty($imgs['smallThumbnail'])) {
            return str_replace('http://', 'https://', $imgs['smallThumbnail']);
        }
    }

    return null;
}

function buscarOpenLibraryStrict($titulo, $autorEsperado) {
    $apiUrl = "https://openlibrary.org/search.json?title=" . urlencode($titulo);
    $json = descargarUrl($apiUrl);
    if (!$json) return null;

    $data = json_decode($json, true);
    if (empty($data['docs'])) return null;

    foreach ($data['docs'] as $doc) {
        $autoresAPI = implode(' ', $doc['author_name'] ?? []);
        
        if (!empty($autorEsperado) && stripos($autoresAPI, $autorEsperado) === false) {
            continue; // Saltar si no coincide el autor
        }

        if (!empty($doc['cover_i'])) {
            return "https://covers.openlibrary.org/b/id/" . $doc['cover_i'] . "-L.jpg";
        }
    }

    return null;
}

function descargarUrl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $res !== false) {
        return $res;
    }
    return null;
}