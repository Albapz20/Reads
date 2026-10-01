<?php
class PortadasService {

    private const DIR_UPLOADS = __DIR__ . '/../public/uploads/portadas/';

    public static function obtenerPortada($tituloOriginal, $autor = '', $libroId = null, $isbn = '') {
        $idUnico = $libroId ?: md5($tituloOriginal);

        // ISBN exacto
        if (!empty($isbn)) {
            $url = self::buscarPorIsbn(trim($isbn));
            if ($url && ($ruta = self::descargarImagenLocal($url, $idUnico))) {
                return $ruta;
            }
        }

        // Por título/autor, probando cada fuente hasta que una funcione de verdad
        $fuentes = ['buscarGoogleBooks', 'buscarAppleBooks', 'buscarOpenLibrary'];

        foreach (self::generarCandidatosTitulo($tituloOriginal) as $tituloLimpio) {
            if (empty($tituloLimpio) || strlen($tituloLimpio) < 3) continue;

            foreach ($fuentes as $fuente) {
                $url = self::$fuente($tituloLimpio, $autor);
                if ($url && ($ruta = self::descargarImagenLocal($url, $idUnico))) {
                    return $ruta;
                }
            }
        }

        return null;
    }

    // Descarga una URL, comprueba que sea una imagen real y la guarda en $rutaFisica.
    // Devuelve true si se guardó correctamente, false si no es una imagen válida o hubo error.
    public static function descargarImagenValidada(string $url, string $rutaFisica): bool {
        $contenido = self::curlGet($url);
        if (!$contenido || strlen($contenido) < 1000) return false;

        $info = @getimagesizefromstring($contenido);
        if (!$info || $info[0] < 80 || $info[1] < 120) return false;

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

    private static function generarCandidatosTitulo($titulo) {
        $candidatos = [];

        $t1 = preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $titulo);

        $coletillas = [
            'Edición con cantos tintados', 'Edición especial', 'Spanish Edition',
            'Tapa dura', 'Tapa blanda', 'Edición ilustrada', 'Colección Trivium',
            'Edición Limitada', 'Book 1', 'Book 2', 'un cuento de empoderhadas'
        ];
        foreach ($coletillas as $c) {
            $t1 = preg_replace('/\b' . preg_quote($c, '/') . '\b/ui', '', $t1);
        }

        $t1 = trim(preg_replace('/[\s\-:\,]+$/u', '', preg_replace('/\s+/', ' ', $t1)));
        if (!empty($t1)) $candidatos[] = $t1;

        // Si al limpiar se perdió todo, probar el título original
        $candidatos[] = trim($titulo);

        return array_unique(array_filter($candidatos));
    }

    private static function buscarPorIsbn($isbn) {
        $isbnLimpio = preg_replace('/[^0-9X]/i', '', $isbn);
        if (empty($isbnLimpio)) return null;

        $res = self::curlGet("https://www.googleapis.com/books/v1/volumes?q=isbn:" . $isbnLimpio);
        if ($res) {
            $data = json_decode($res, true);
            if (!empty($data['items'][0]['volumeInfo']['imageLinks'])) {
                $links = $data['items'][0]['volumeInfo']['imageLinks'];
                $img = $links['extraLarge'] ?? $links['large'] ?? $links['medium'] ?? $links['thumbnail'] ?? null;
                if ($img) {
                    return preg_replace('/&zoom=\d+/', '&zoom=2', str_replace('http://', 'https://', $img));
                }
            }
        }
        return null;
    }

    private static function buscarGoogleBooks($titulo, $autor) {
        $query = 'intitle:"' . $titulo . '"';
        if (!empty($autor) && strtolower(trim($autor)) !== 'unknown') {
            $query .= ' inauthor:"' . self::obtenerApellido($autor) . '"';
        }

        $res = self::curlGet("https://www.googleapis.com/books/v1/volumes?q=" . urlencode($query) . "&maxResults=5");

        if (!$res || empty(json_decode($res, true)['items'])) {
            $qGeneral = trim($titulo . ' ' . $autor);
            $res = self::curlGet("https://www.googleapis.com/books/v1/volumes?q=" . urlencode($qGeneral) . "&maxResults=5");
        }

        if ($res) {
            $data = json_decode($res, true);
            foreach ($data['items'] ?? [] as $item) {
                $tituloEncontrado   = $item['volumeInfo']['title'] ?? '';
                $autoresEncontrados = $item['volumeInfo']['authors'] ?? [];

                if (self::esCoincidenciaValida($titulo, $autor, $tituloEncontrado, $autoresEncontrados)) {
                    $imageLinks = $item['volumeInfo']['imageLinks'] ?? null;
                    if ($imageLinks) {
                        $img = $imageLinks['extraLarge'] ?? $imageLinks['large'] ?? $imageLinks['medium'] ?? $imageLinks['thumbnail'] ?? null;
                        if ($img) {
                            return preg_replace('/&zoom=\d+/', '&zoom=2', str_replace('http://', 'https://', $img));
                        }
                    }
                }
            }
        }
        return null;
    }

    private static function buscarAppleBooks($titulo, $autor) {
        $q = trim($titulo . ' ' . $autor);

        foreach (['es', 'us', 'mx'] as $country) {
            $res = self::curlGet("https://itunes.apple.com/search?term=" . urlencode($q) . "&entity=ebook&country={$country}&limit=5");
            if ($res) {
                $data = json_decode($res, true);
                foreach ($data['results'] ?? [] as $item) {
                    $tituloEncontrado = $item['trackName'] ?? '';
                    $autorEncontrado  = $item['artistName'] ?? '';

                    if (self::esCoincidenciaValida($titulo, $autor, $tituloEncontrado, [$autorEncontrado])
                        && !empty($item['artworkUrl100'])) {
                        return str_replace('100x100bb', '600x600bb', $item['artworkUrl100']);
                    }
                }
            }
        }
        return null;
    }

    private static function buscarOpenLibrary($titulo, $autor) {
        $q = trim($titulo . ' ' . $autor);
        $res = self::curlGet("https://openlibrary.org/search.json?q=" . urlencode($q) . "&limit=5");
        if ($res) {
            $data = json_decode($res, true);
            foreach ($data['docs'] ?? [] as $doc) {
                $tituloEncontrado   = $doc['title'] ?? '';
                $autoresEncontrados = $doc['author_name'] ?? [];

                if (self::esCoincidenciaValida($titulo, $autor, $tituloEncontrado, $autoresEncontrados) && !empty($doc['cover_i'])) {
                    return "https://covers.openlibrary.org/b/id/" . $doc['cover_i'] . "-L.jpg?default=false";
                }
            }
        }
        return null;
    }

    private static function esCoincidenciaValida($tituloBuscado, $autorBuscado, $tituloDevuelto, $autoresDevueltos) {
        $b = self::normalizarTexto($tituloBuscado);
        $e = self::normalizarTexto($tituloDevuelto);

        if (empty($b) || empty($e)) return false;

        if (!empty($autorBuscado) && strtolower(trim($autorBuscado)) !== 'unknown') {
            $apellidoBuscado = self::normalizarTexto(self::obtenerApellido($autorBuscado));
            $coincideAutor = false;

            foreach ($autoresDevueltos as $a) {
                if (mb_strpos(self::normalizarTexto($a), $apellidoBuscado) !== false) {
                    $coincideAutor = true;
                    break;
                }
            }
            if (!$coincideAutor) return false;
        }

        similar_text($b, $e, $percent);
        return $percent >= 65 || mb_strpos($e, $b) !== false || mb_strpos($b, $e) !== false;
    }

    private static function normalizarTexto($str) {
        $str = mb_strtolower($str, 'UTF-8');
        $str = strtr($str, [
            'á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ü'=>'u', 'ñ'=>'n',
            'à'=>'a', 'è'=>'e', 'ì'=>'i', 'ò'=>'o', 'ù'=>'u', 'ä'=>'a', 'ö'=>'o'
        ]);
        $str = preg_replace('/[^a-z0-9\s]/u', ' ', $str);
        return trim(preg_replace('/\s+/', ' ', $str));
    }

    private static function obtenerApellido($autor) {
        // Si vienen varios autores separados por coma, usar solo el primero
        $autor = trim(explode(',', $autor)[0]);
        $partes = array_filter(explode(' ', $autor));
        return count($partes) > 0 ? end($partes) : $autor;
    }

    private static function curlGet($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR    => true,  
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }
}