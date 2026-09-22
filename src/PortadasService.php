<?php

class PortadasService {

    public static function obtenerPortada($tituloOriginal, $autor = '', $libroId = null, $isbn = '') {
        // Si hay ISBN, buscar por ISBN exacto
        if (!empty($isbn)) {
            $urlIsbn = self::buscarPorIsbn(trim($isbn));
            if ($urlIsbn) {
                return self::descargarImagenLocal($urlIsbn, $libroId ?: md5($tituloOriginal));
            }
        }

        // Generar candidatos de título limpios
        $candidatos = self::generarCandidatosTitulo($tituloOriginal);

        foreach ($candidatos as $tituloLimpio) {
            if (empty($tituloLimpio) || strlen($tituloLimpio) < 3) continue;

            // Google Books
            $url = self::buscarGoogleBooks($tituloLimpio, $autor);

            // Apple Books
            if (!$url) $url = self::buscarAppleBooks($tituloLimpio, $autor);

            // OpenLibrary
            if (!$url) $url = self::buscarOpenLibrary($tituloLimpio, $autor);

            if ($url) {
                return self::descargarImagenLocal($url, $libroId ?: md5($tituloOriginal));
            }
        }

        return null;
    }

    private static function generarCandidatosTitulo($titulo) {
        $candidatos = [];

        // Extraer el título principal eliminando los paréntesis de saga/edición
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

        return array_unique(array_filter($candidatos));
    }

    private static function buscarPorIsbn($isbn) {
        $isbnLimpio = preg_replace('/[^0-9X]/i', '', $isbn);
        if (empty($isbnLimpio)) return null;

        $url = "https://www.googleapis.com/books/v1/volumes?q=isbn:" . $isbnLimpio;
        $res = self::curlGet($url);
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

        $url = "https://www.googleapis.com/books/v1/volumes?q=" . urlencode($query) . "&maxResults=5";
        $res = self::curlGet($url);
        
        // Si no devuelve resultados con operadores estrictos, reintentar con búsqueda general
        if (!$res || empty(json_decode($res, true)['items'])) {
            $qGeneral = trim($titulo . ' ' . $autor);
            $url = "https://www.googleapis.com/books/v1/volumes?q=" . urlencode($qGeneral) . "&maxResults=5";
            $res = self::curlGet($url);
        }

        if ($res) {
            $data = json_decode($res, true);
            if (!empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    $tituloEncontrado = $item['volumeInfo']['title'] ?? '';
                    $autoresEncontrados = $item['volumeInfo']['authors'] ?? [];
                    
                    if (self::esCoincidenciaValida($titulo, $autor, $tituloEncontrado, $autoresEncontrados)) {
                        $imageLinks = $item['volumeInfo']['imageLinks'] ?? null;
                        if ($imageLinks) {
                            $img = $imageLinks['extraLarge'] 
                                ?? $imageLinks['large'] 
                                ?? $imageLinks['medium'] 
                                ?? $imageLinks['thumbnail'] 
                                ?? null;
                            if ($img) {
                                return preg_replace('/&zoom=\d+/', '&zoom=2', str_replace('http://', 'https://', $img));
                            }
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
            $url = "https://itunes.apple.com/search?term=" . urlencode($q) . "&entity=ebook&country={$country}&limit=5";
            $res = self::curlGet($url);
            if ($res) {
                $data = json_decode($res, true);
                if (!empty($data['results'])) {
                    foreach ($data['results'] as $item) {
                        $tituloEncontrado = $item['trackName'] ?? '';
                        $autorEncontrado = $item['artistName'] ?? '';
                        
                        if (self::esCoincidenciaValida($titulo, $autor, $tituloEncontrado, [$autorEncontrado])) {
                            if (!empty($item['artworkUrl100'])) {
                                return str_replace('100x100bb', '600x600bb', $item['artworkUrl100']);
                            }
                        }
                    }
                }
            }
        }
        return null;
    }

    private static function buscarOpenLibrary($titulo, $autor) {
        $q = trim($titulo . ' ' . $autor);
        $url = "https://openlibrary.org/search.json?q=" . urlencode($q) . "&limit=5";
        $res = self::curlGet($url);
        if ($res) {
            $data = json_decode($res, true);
            if (!empty($data['docs'])) {
                foreach ($data['docs'] as $doc) {
                    $tituloEncontrado = $doc['title'] ?? '';
                    $autoresEncontrados = $doc['author_name'] ?? [];
                    
                    if (self::esCoincidenciaValida($titulo, $autor, $tituloEncontrado, $autoresEncontrados) && !empty($doc['cover_i'])) {
                        return "https://covers.openlibrary.org/b/id/" . $doc['cover_i'] . "-L.jpg";
                    }
                }
            }
        }
        return null;
    }

    private static function esCoincidenciaValida($tituloBuscado, $autorBuscado, $tituloDevuelto, $autoresDevueltos) {
        $b = self::normalizarTexto($tituloBuscado);
        $e = self::normalizarTexto($tituloDevuelto);

        if (empty($b) || empty($e)) return false;

        // Validación estricta de autor si se proporciona
        if (!empty($autorBuscado) && strtolower(trim($autorBuscado)) !== 'unknown') {
            $apellidoBuscado = self::normalizarTexto(self::obtenerApellido($autorBuscado));
            $coincideAutor = false;

            if (!empty($autoresDevueltos)) {
                foreach ($autoresDevueltos as $a) {
                    $aNorm = self::normalizarTexto($a);
                    if (mb_strpos($aNorm, $apellidoBuscado) !== false) {
                        $coincideAutor = true;
                        break;
                    }
                }
            }

            // Si el autor devuelto no coincide con el buscado, DESCARTAR inmediatamente
            if (!$coincideAutor) {
                return false;
            }
        }

        // Similitud estricta de título (mínimo 65%)
        similar_text($b, $e, $percent);
        if ($percent >= 65 || mb_strpos($e, $b) !== false || mb_strpos($b, $e) !== false) {
            return true;
        }

        return false;
    }

    private static function normalizarTexto($str) {
        $str = mb_strtolower($str, 'UTF-8');
        $unwanted = [
            'á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ü'=>'u', 'ñ'=>'n',
            'à'=>'a', 'è'=>'e', 'ì'=>'i', 'ò'=>'o', 'ù'=>'u', 'ä'=>'a', 'ö'=>'o'
        ];
        $str = strtr($str, $unwanted);
        $str = preg_replace('/[^a-z0-9\s]/u', ' ', $str);
        return trim(preg_replace('/\s+/', ' ', $str));
    }

    private static function obtenerApellido($autor) {
        $partes = array_filter(explode(' ', trim($autor)));
        return count($partes) > 0 ? end($partes) : $autor;
    }

    private static function descargarImagenLocal($urlImagen, $idUnico) {
        $dirUploads = __DIR__ . '/../public/uploads/portadas/';
        if (!is_dir($dirUploads)) {
            @mkdir($dirUploads, 0777, true);
        }

        $contenido = self::curlGet($urlImagen);
        if ($contenido && strlen($contenido) > 1000) {
            $nombreArchivo = 'portada_' . substr(md5($idUnico), 0, 10) . '_' . time() . '.jpg';
            $rutaAbsoluta = $dirUploads . $nombreArchivo;
            if (file_put_contents($rutaAbsoluta, $contenido)) {
                return 'uploads/portadas/' . $nombreArchivo;
            }
        }
        return null;
    }

    private static function curlGet($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }
}