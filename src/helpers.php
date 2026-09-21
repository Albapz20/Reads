<?php
function obtenerPortadaValida(?string $url, ?int $libroId = null): string {
    if (empty($url) || $url === 'sin portada' || str_contains($url, 'placehold')) {
        return '';
    }

    $urlLimpia = trim($url);

    //  Si ya es una ruta local, la servimos directamente
    if (str_starts_with($urlLimpia, 'uploads/') || str_starts_with($urlLimpia, 'img/')) {
        return $urlLimpia;
    }

    // Si tenemos ID y es una URL externa, forzamos la descarga local
    if ($libroId) {
        $directorioFisico = __DIR__ . '/../public/uploads/portadas/';
        
        if (!file_exists($directorioFisico)) {
            @mkdir($directorioFisico, 0777, true);
        }

        $nombreArchivo = 'portada_' . $libroId . '.jpg';
        $rutaFisica = $directorioFisico . $nombreArchivo;
        $rutaRelativa = 'uploads/portadas/' . $nombreArchivo;

        // Si ya se descargó previamente, devolver ruta local
        if (file_exists($rutaFisica) && filesize($rutaFisica) > 500) {
            return $rutaRelativa;
        }

        // Intento de descarga 1: cURL
        $contenidoImagen = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($urlLimpia);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
            $contenidoImagen = curl_exec($ch);
            curl_close($ch);
        }

        // Intento de descarga 2: file_get_contents (si cURL falla)
        if (!$contenidoImagen || strlen($contenidoImagen) < 500) {
            $contexto = stream_context_create([
                'http' => ['header' => "User-Agent: Mozilla/5.0\r\n"],
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
            ]);
            $contenidoImagen = @file_get_contents($urlLimpia, false, $contexto);
        }

        // Si se logró descargar la imagen físicamente
        if ($contenidoImagen && strlen($contenidoImagen) > 500) {
            file_put_contents($rutaFisica, $contenidoImagen);

            // Actualizar la base de datos para no volver a pedir la URL remota
            try {
                $db = new Database();
                $db->pdo->prepare("UPDATE listas_lectura SET portada = ? WHERE id = ?")->execute([$rutaRelativa, $libroId]);
                $db->pdo->prepare("UPDATE libros SET portada = ? WHERE id = ?")->execute([$rutaRelativa, $libroId]);
            } catch (Exception $e) {
                // Silencioso
            }

            return $rutaRelativa;
        }
    }

    return str_replace('http://', 'https://', $urlLimpia);
}