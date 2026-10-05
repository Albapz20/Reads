<?php
require_once __DIR__ . '/PortadasService.php';

// Devuelve la ruta con ?v=<fecha de modificación>, para que el navegador recargue el archivo solo cuando cambia de verdad.
function asset(string $ruta): string {
    $archivo = $_SERVER['DOCUMENT_ROOT'] . $ruta;
    $version = is_file($archivo) ? filemtime($archivo) : time();
    return $ruta . '?v=' . $version;
}
function menu_flotante(?string $activa = null): string {
    $items = [
        'inicio'       => ['index.php',        '🏠', 'Inicio'],
        'perfil'       => ['perfil.php',       '👤', 'Mi perfil'],
        'biblioteca'   => ['biblioteca.php',   '📚', 'Mi estantería'],
        'estadisticas' => ['estadisticas.php', '📊', 'Estadísticas'],
        'calendario'   => ['calendario.php',   '📅', 'Calendario'],
        'buscar'       => ['buscar.php',       '🔍', 'Buscar'],
        'ajustes'      => ['ajustes.php',      '⚙️', 'Ajustes'],
    ];
 
    // Archivos que cuentan como cada sección
    $porArchivo = [
        'index.php'                 => 'inicio',
        'perfil.php'                => 'perfil',
        'biblioteca.php'            => 'biblioteca',
        'estadisticas.php'          => 'estadisticas',
        'estadisticas_estrellas.php'=> 'estadisticas',
        'calendario.php'            => 'calendario',
        'buscar.php'                => 'buscar',
        'ajustes.php'               => 'ajustes',
        'editar_perfil.php'         => 'ajustes',
    ];
 
    if ($activa === null) {
        $activa = $porArchivo[basename($_SERVER['SCRIPT_NAME'] ?? '')] ?? '';
    }
 
    $html = '<div class="floating-nav-container"><nav class="quick-nav-floating" aria-label="Navegación principal">';
    foreach ($items as $clave => [$url, $icono, $texto]) {
        $esActivo = ($clave === $activa);
        $html .= '<a href="' . $url . '" class="nav-card-float' . ($esActivo ? ' active' : '') . '"'
               . ($esActivo ? ' aria-current="page"' : '') . '>'
               . '<span class="nav-icon" aria-hidden="true">' . $icono . '</span>'
               . '<span>' . $texto . '</span></a>';
    }
    return $html . '</nav></div>';
}
 
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