<?php
require_once __DIR__ . '/PortadasVerificadas.php';

class PortadasService {

    private const DIR_UPLOADS = __DIR__ . '/../public/uploads/portadas/';

    public static function obtenerPortada($tituloOriginal, $autor = '', $libroId = null, $isbn = '') {
        $idUnico = $libroId ?: md5($tituloOriginal);

        // Búsqueda verificada: prueba ISBN, Open Library, Apple y Google
        // y elige la mejor coincidencia comprobando título + autor.
        $resultado = PortadasVerificadas::buscar($tituloOriginal, $autor, $isbn);
        if ($resultado && !empty($resultado['url'])) {
            if ($ruta = self::descargarImagenLocal($resultado['url'], $idUnico)) {
                return $ruta;
            }
        }

        // Si la búsqueda estricta no encontró nada, probar candidatos flexibles
        foreach (PortadasVerificadas::candidatos($tituloOriginal, $autor, 12) as $c) {
            if (!empty($c['url']) && $ruta = self::descargarImagenLocal($c['url'], $idUnico)) {
                return $ruta;
            }
        }

        return null;
    }

    public static function descargarImagenValidada(string $url, string $rutaFisica): bool {
        $contenido = self::curlGet($url);
        if (!$contenido || strlen($contenido) < 1000) return false;

        $info = @getimagesizefromstring($contenido);
        if (!$info || $info[0] < 60 || $info[1] < 90) return false;

        $dir = dirname($rutaFisica);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);

        return file_put_contents($rutaFisica, $contenido) !== false;
    }

    private static function descargarImagenLocal($urlImagen, $idUnico) {
        $nombre = ctype_digit((string)$idUnico)
            ? 'portada_' . $idUnico . '.jpg'
            : 'portada_' . substr(md5((string)$idUnico), 0, 10) . '.jpg';

        if (self::descargarImagenValidada($urlImagen, self::DIR_UPLOADS . $nombre)) {
            return 'uploads/portadas/' . $nombre;
        }
        return null;
    }

    private static function curlGet($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }
}