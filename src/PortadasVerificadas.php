<?php
/**
 * Portadas verificadas
 * --------------------
 * Busca la portada de un libro COMPROBANDO que el resultado es ese libro
 * (título casi idéntico + mismo autor), en vez de fiarse del primer resultado.
 * También puede comprobar si la portada que ya tiene un libro es la correcta.
 */
require_once __DIR__ . '/../config/config.php';

class PortadasVerificadas {

    const UMBRAL_TITULO = 88;       // similitud mínima de título (0-100)

    public static int $limitado = 0;   // veces que Google respondió 429 (demasiadas consultas)

    /* ------------------------------------------------------------------
       Texto
    ------------------------------------------------------------------ */
    private static function sinAcentos(string $s): string {
        return strtr(mb_strtolower($s, 'UTF-8'), [
            'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c',
        ]);
    }

    private static function limpiar(string $s): string {
        $s = preg_replace('/[^a-z0-9]+/', ' ', self::sinAcentos($s));
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** Título comparable: sin (saga, #1), sin "(Spanish Edition)", sin subtítulo ni artículo inicial */
    public static function normalizar(string $titulo): string {
        $t = preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $titulo);
        $partes = preg_split('/\s*:\s*|\s+[-–—]\s+/u', $t);
        $t = self::limpiar($partes[0] ?? $t);
        return preg_replace('/^(el|la|los|las|un|una|the|a|an)\s+/', '', $t);
    }

    private static function tokens(string $s): array {
        return array_values(array_filter(explode(' ', self::limpiar($s)), fn($w) => strlen($w) >= 2));
    }

    public static function esIsbn(string $v): bool {
        return (bool)preg_match('/^(\d{9}[\dXx]|\d{13})$/', $v);
    }

    /* ------------------------------------------------------------------
       Comparación
    ------------------------------------------------------------------ */
    /** true = mismo autor · false = distinto · null = no hay autor que comparar */
    public static function autorCoincide(string $autoresLibro, array $autoresCandidato): ?bool {
        $nombres = array_values(array_filter(array_map('trim', preg_split('/[,;&]/u', $autoresLibro))));
        $candidato = [];
        foreach ($autoresCandidato as $a) $candidato = array_merge($candidato, self::tokens((string)$a));
        $candidato = array_unique($candidato);

        if ($nombres === [] || $candidato === [] || stripos($autoresLibro, 'desconocido') !== false) return null;

        $solapados = 0;
        foreach ($nombres as $nombre) {
            $t = self::tokens($nombre);
            if (!$t) continue;
            if (in_array(end($t), $candidato, true)) return true;          // coincide el apellido
            $solapados += count(array_intersect($t, $candidato));
        }
        return $solapados >= 2;
    }

    public static function puntosTitulo(string $tituloLibro, string $tituloCandidato): int {
        $a = self::normalizar($tituloLibro);
        $b = self::normalizar($tituloCandidato);
        if ($a === '' || $b === '') return 0;
        if ($a === $b) return 100;

        similar_text($a, $b, $pct);
        $p = (int)round($pct);

        // Los números (tomo 2, 3…) tienen que coincidir
        preg_match_all('/\d+/', $a, $na);
        preg_match_all('/\d+/', $b, $nb);
        if (array_diff($na[0], $nb[0]) || array_diff($nb[0], $na[0])) $p -= 30;

        return max(0, min(99, $p));
    }

    public static function coincide(string $tLibro, string $aLibro, string $tCand, array $aCand): array {
        $pt = self::puntosTitulo($tLibro, $tCand);
        $au = self::autorCoincide($aLibro, $aCand);
        $ok = $pt >= self::UMBRAL_TITULO && ($au === true || ($au === null && $pt === 100));
        return ['ok' => $ok, 'titulo' => $pt, 'autor' => $au];
    }

    /* ------------------------------------------------------------------
       Red
    ------------------------------------------------------------------ */
    private static function json(string $url): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 7,
            CURLOPT_SSL_VERIFYPEER => false,   // XAMPP en local suele no tener certificados
            CURLOPT_USERAGENT      => 'ReadsApp/1.0 (' . (defined('APP_CONTACTO') ? APP_CONTACTO : 'contacto') . ')',
        ]);
        $resp = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http === 429) self::$limitado++;
        if ($resp === false || $http === 0 || $http >= 400) return null;

        $d = json_decode($resp, true);
        return is_array($d) ? $d : null;
    }

    private static function claveGoogle(): string {
        return function_exists('config') ? (string)config('google_books_key', '') : '';
    }

    private static function urlGoogle(string $q, int $max = 10): string {
        $k = self::claveGoogle();
        return 'https://www.googleapis.com/books/v1/volumes?q=' . urlencode($q)
             . '&maxResults=' . $max . '&printType=books' . ($k !== '' ? '&key=' . urlencode($k) : '');
    }

    private static function portadaGoogle(array $vi): string {
        $img = $vi['imageLinks']['thumbnail'] ?? $vi['imageLinks']['smallThumbnail'] ?? '';
        if ($img === '') return '';
        return str_replace(['http://', '&edge=curl'], ['https://', ''], $img);
    }

    /* ------------------------------------------------------------------
       Buscar la portada correcta
       Devuelve ['url','fuente','titulo_enc','autores_enc','titulo','autor_ok','puntos'] o null
    ------------------------------------------------------------------ */
    public static function buscar(string $titulo, string $autores, string $isbn = ''): ?array {
        $mejor = null;

        $tituloBusqueda = trim(str_replace('"', '', preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $titulo)));
        $nombres = array_values(array_filter(array_map('trim', preg_split('/[,;&]/u', $autores))));
        $primerAutor = $nombres[0] ?? '';
        if (stripos($primerAutor, 'desconocido') !== false) $primerAutor = '';
        $primerAutor = str_replace('"', '', $primerAutor);

        $considerar = function (array $c) use (&$mejor) {
            if ($mejor === null || $c['puntos'] > $mejor['puntos']) $mejor = $c;
        };
        $suficiente = function () use (&$mejor) {
            return $mejor !== null && $mejor['titulo'] === 100 && $mejor['autor_ok'] === true;
        };
        $deGoogle = function (?array $json, bool $porIsbn) use ($titulo, $autores, $considerar) {
            foreach (($json['items'] ?? []) as $it) {
                $vi  = $it['volumeInfo'] ?? [];
                $url = self::portadaGoogle($vi);
                if ($url === '') continue;
                $m = self::coincide($titulo, $autores, (string)($vi['title'] ?? ''), (array)($vi['authors'] ?? []));
                if (!$m['ok']) continue;
                $considerar([
                    'url' => $url, 'fuente' => 'Google Books',
                    'titulo_enc' => (string)($vi['title'] ?? ''), 'autores_enc' => implode(', ', (array)($vi['authors'] ?? [])),
                    'titulo' => $m['titulo'], 'autor_ok' => $m['autor'],
                    'puntos' => $m['titulo'] + ($m['autor'] === true ? 40 : 0) + ($porIsbn ? 30 : 0),
                ]);
            }
        };

        // 1) Por ISBN (la edición exacta)
        if ($isbn !== '' && self::esIsbn($isbn)) {
            $deGoogle(self::json(self::urlGoogle('isbn:' . $isbn, 5)), true);
        }

        // 2) Google Books por título + autor
        if (!$suficiente()) {
            $q = 'intitle:"' . $tituloBusqueda . '"' . ($primerAutor !== '' ? ' inauthor:"' . $primerAutor . '"' : '');
            $deGoogle(self::json(self::urlGoogle($q)), false);
        }

        // 3) Open Library
        if (!$suficiente()) {
            $p = ['title' => $tituloBusqueda, 'limit' => 10, 'fields' => 'title,author_name,cover_i'];
            if ($primerAutor !== '') $p['author'] = $primerAutor;
            $json = self::json('https://openlibrary.org/search.json?' . http_build_query($p));
            foreach (($json['docs'] ?? []) as $d) {
                if (empty($d['cover_i'])) continue;
                $m = self::coincide($titulo, $autores, (string)($d['title'] ?? ''), (array)($d['author_name'] ?? []));
                if (!$m['ok']) continue;
                $considerar([
                    'url' => 'https://covers.openlibrary.org/b/id/' . (int)$d['cover_i'] . '-L.jpg', 'fuente' => 'Open Library',
                    'titulo_enc' => (string)($d['title'] ?? ''), 'autores_enc' => implode(', ', (array)($d['author_name'] ?? [])),
                    'titulo' => $m['titulo'], 'autor_ok' => $m['autor'],
                    'puntos' => $m['titulo'] + ($m['autor'] === true ? 40 : 0),
                ]);
            }
        }

        // 4) Último intento: búsqueda libre en Google
        if ($mejor === null) {
            $deGoogle(self::json(self::urlGoogle(trim($tituloBusqueda . ' ' . $primerAutor))), false);
        }

        return $mejor;
    }

    /* ------------------------------------------------------------------
       Comprobar la portada que YA tiene un libro
       estado: ok | incorrecta | sin_portada | propia | desconocida
    ------------------------------------------------------------------ */
    public static function verificarActual(string $portada, string $titulo, string $autores): array {
        $p = trim($portada);

        if ($p === '' || strtolower($p) === 'sin portada' || stripos($p, 'default') !== false || stripos($p, 'placehold') !== false) {
            return ['estado' => 'sin_portada', 'detalle' => 'No tiene portada'];
        }
        if (!preg_match('~^https?://~i', $p)) {
            return ['estado' => 'propia', 'detalle' => 'Imagen subida por ti'];
        }

        // Google Books: la URL lleva el id del volumen, así que se puede ver de qué libro es
        if (stripos($p, 'books.google') !== false || stripos($p, 'googleusercontent') !== false) {
            if (preg_match('/[?&]id=([A-Za-z0-9_-]{6,20})/', $p, $m)) {
                $k = self::claveGoogle();
                $json = self::json('https://www.googleapis.com/books/v1/volumes/' . $m[1] . ($k !== '' ? '?key=' . urlencode($k) : ''));
                $vi = $json['volumeInfo'] ?? null;
                if ($vi) {
                    $r = self::coincide($titulo, $autores, (string)($vi['title'] ?? ''), (array)($vi['authors'] ?? []));
                    $de = '«' . ($vi['title'] ?? '?') . '»' . (!empty($vi['authors']) ? ' de ' . implode(', ', $vi['authors']) : '');
                    return ['estado' => $r['ok'] ? 'ok' : 'incorrecta', 'detalle' => 'La portada es de ' . $de];
                }
            }
            return ['estado' => 'desconocida', 'detalle' => 'No se pudo comprobar (Google)'];
        }

        // Open Library
        if (stripos($p, 'covers.openlibrary.org') !== false) {
            $q = null;
            if (preg_match('~/b/id/(\d+)~', $p, $m)) $q = 'cover_i:' . $m[1];
            elseif (preg_match('~/b/isbn/(\d{9}[\dXx]|\d{13})~', $p, $m)) $q = 'isbn:' . $m[1];

            if ($q) {
                $json = self::json('https://openlibrary.org/search.json?' . http_build_query(['q' => $q, 'fields' => 'title,author_name', 'limit' => 3]));
                $docs = $json['docs'] ?? [];
                foreach ($docs as $d) {
                    if (self::coincide($titulo, $autores, (string)($d['title'] ?? ''), (array)($d['author_name'] ?? []))['ok']) {
                        return ['estado' => 'ok', 'detalle' => 'Coincide con «' . ($d['title'] ?? '?') . '»'];
                    }
                }
                if ($docs) return ['estado' => 'incorrecta', 'detalle' => 'La portada es de «' . ($docs[0]['title'] ?? '?') . '»'];
            }
            return ['estado' => 'desconocida', 'detalle' => 'No se pudo comprobar (Open Library)'];
        }

        return ['estado' => 'desconocida', 'detalle' => 'Origen de la imagen no verificable'];
    }

    /* ------------------------------------------------------------------
       Comparar imágenes (para portadas ya descargadas a tu servidor)
       Se reduce cada imagen a una "huella" de 64 bits; dos portadas iguales
       dan huellas casi idénticas aunque tengan distinto tamaño o calidad.
    ------------------------------------------------------------------ */
    public static function descargar(string $url): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ]);
        $r = curl_exec($ch);
        curl_close($ch);
        return ($r !== false && strlen($r) >= 1000) ? $r : null;
    }

    public static function huella(string $bytes): ?string {
        if (!function_exists('imagecreatefromstring')) return null;   // extensión GD desactivada
        $img = @imagecreatefromstring($bytes);
        if (!$img) return null;

        $min = imagecreatetruecolor(9, 8);
        imagecopyresampled($min, $img, 0, 0, 0, 0, 9, 8, imagesx($img), imagesy($img));

        $gris = function (int $c): int {
            return (int)(0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255));
        };
        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= ($gris(imagecolorat($min, $x, $y)) > $gris(imagecolorat($min, $x + 1, $y))) ? '1' : '0';
            }
        }
        imagedestroy($img);
        imagedestroy($min);
        return $bits;
    }

    /** 0 = idénticas · hasta ~12 = la misma portada · más de 20 = portadas distintas */
    public static function distancia(string $a, string $b): int {
        $d = 0;
        for ($i = 0; $i < 64; $i++) if ($a[$i] !== $b[$i]) $d++;
        return $d;
    }
}