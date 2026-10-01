<?php
require_once __DIR__ . '/PortadasService.php';

function obtenerPortadaValida(?string $url, ?int $libroId = null): string {
    static $descargasEnEstaCarga = 0;
    $maxDescargasPorCarga = 3;

    $url = trim((string)$url);
    if ($url === '' || $url === 'sin portada' || str_contains($url, 'placehold')) {
        return '';
    }

    $dirPublic = __DIR__ . '/../public/';

    // Url local ya descargada
    if (str_starts_with($url, 'uploads/') || str_starts_with($url, 'img/')) {
        return (is_file($dirPublic . $url) && filesize($dirPublic . $url) > 500) ? $url : '';
    }

    // Url externa: intentar descargar y validar
    $urlHttps = str_replace('http://', 'https://', $url);

    if ($libroId) {
        $nombre       = 'portada_' . $libroId . '.jpg';
        $rutaFisica   = $dirPublic . 'uploads/portadas/' . $nombre;
        $rutaRelativa = 'uploads/portadas/' . $nombre;

        // Ya descargada antes
        if (is_file($rutaFisica) && filesize($rutaFisica) > 500) {
            return $rutaRelativa;
        }

        // Limitar descargas para no bloquear la página
        if ($descargasEnEstaCarga >= $maxDescargasPorCarga) {
            return $urlHttps;
        }
        $descargasEnEstaCarga++;

        if (PortadasService::descargarImagenValidada($urlHttps, $rutaFisica)) {
            try {
                $db = new Database();
                $db->pdo->prepare("UPDATE listas_lectura SET portada = ? WHERE id = ?")
                        ->execute([$rutaRelativa, $libroId]);
            } catch (Exception $e) {
                // Silencioso
            }
            return $rutaRelativa;
        }

        // La URL remota no devuelve una imagen válida.
        return '';
    }

    return $urlHttps;
}