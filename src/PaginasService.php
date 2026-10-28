<?php
class PaginasService {

    private const MAX_PAGINAS = 1200;

    // Palabras que indican que el resultado NO es el libro suelto 
    private const PALABRAS_PACK = [
        'estuche', 'box set', 'boxed set', 'boxset', 'pack', 'trilogia', 'trilogy', 'coleccion', 'collection',
        'omnibus', 'saga completa', 'complete series', 'bundle', 'diario oficial', 'cuaderno', 'notebook',
        'journal', 'guia', 'guide', 'resumen', 'summary', 'study guide', 'analisis', 'workbook', 'calendario',
        'agenda', 'edicion de lujo', 'books 1', 'libros 1'
    ];

    public static function buscar(array $libro, ?array &$log = null): array {
        $log = ['peticiones' => [], 'descartados' => [], 'candidatas' => [], 'isbn_extra' => [], 'resultado' => 0];

        $variantes = self::variantesTitulo((string)$libro['titulo']);
        $variantesNorm = array_map([self::class, 'normalizar'], $variantes);

        return self::buscarEnApis($libro, $variantes, $variantesNorm, $log);
    }

    private static function variantesTitulo(string $titulo): array {
        $limpio = trim(preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $titulo));
        $variantes = [];
        foreach (preg_split('/\s*\/\s*/u', $limpio) as $parte) {
            $parte = trim($parte);
            if ($parte !== '') $variantes[] = $parte;
            foreach ([' - ', '. ', ': '] as $sep) {
                $corte = trim(explode($sep, $parte)[0]);
                if ($corte !== '' && mb_strlen($corte) >= 3) $variantes[] = $corte;
            }
        }
        $variantes = array_slice(array_values(array_unique($variantes)), 0, 3);
        return $variantes ?: [$limpio !== '' ? $limpio : $titulo];
    }

    private static function buscarEnApis(array $libro, array $variantes, array $variantesNorm, array &$log): array {
        $ref = trim((string)($libro['libro_id'] ?? ''));

        // Autor principal y su apellido
        $autorPrincipal = trim(explode(',', (string)($libro['autores'] ?? ''))[0]);
        if ($autorPrincipal === 'Autor desconocido') $autorPrincipal = '';
        $partesAutor = array_values(array_filter(explode(' ', self::normalizar($autorPrincipal))));
        $apellido = $partesAutor ? end($partesAutor) : '';

        $clave = function_exists('config') ? trim((string)config('google_books_key', '')) : '';
        $k = $clave !== '' ? '&key=' . urlencode($clave) : '';

        $log['libro_id'] = $ref;
        $log['variantes_titulo'] = $variantes;
        $log['autor'] = $autorPrincipal . ' (apellido buscado: ' . $apellido . ')';
        $log['clave_google_configurada'] = $clave !== '';

        $esIsbn = (bool)preg_match('/^(\d{9}[\dXx]|\d{13})$/', $ref);
        $esIdGoogle = ($ref !== '' && !$esIsbn && !str_starts_with($ref, 'gr_') && preg_match('/^[A-Za-z0-9_-]{8,16}$/', $ref));

        // Registro en el log de un lote de peticiones
        $registrar = function (array $R) use (&$log) {
            foreach ($R as $kk => $x) {
                $n = count($x['json']['items'] ?? $x['json']['docs'] ?? $x['json']['entries'] ?? []);
                $detalle = $x['json'] !== null ? " · $n resultados" : ' · sin datos';
                $log['peticiones'][$kk] = 'HTTP ' . $x['http'] . ($x['error'] ? ' (' . $x['error'] . ')' : '') . $detalle;
                if ($x['http'] === 0 || $x['http'] >= 500) $log['fallo_temporal'] = true;
                if ($x['http'] === 429 && str_starts_with($kk, 'g_')) {
                    $log['aviso_google'] = 'Google respondió 429: se ha agotado la cuota (diaria o por minuto) de tu clave. Se renueva sola.';
                }
            }
        };

        // Open Library: el propio id identifica la obra 
        if (preg_match('/^OL\d+M$/i', $ref)) {
            $R0 = self::peticionesConReintento(['ol_edicion' => 'https://openlibrary.org/books/' . urlencode($ref) . '.json'], 6, 2);
            $registrar($R0);
            $ficha = is_array($R0['ol_edicion']['json'] ?? null) ? $R0['ol_edicion']['json'] : [];
            $n = self::paginasDeFichaOL($ficha);
            if ($n > 0) { $log['resultado'] = $n; $log['fuente'] = 'Open Library (edición ' . $ref . ')'; return ['paginas' => $n, 'fiabilidad' => 'exacta']; }
        } elseif (preg_match('/^OL\d+W$/i', $ref)) {
            $R0 = self::peticionesConReintento(['ol_obra' => 'https://openlibrary.org/works/' . urlencode($ref) . '/editions.json?limit=100'], 8, 2);
            $registrar($R0);
            $cands = [];
            $exs = [];
            foreach (($R0['ol_obra']['json']['entries'] ?? []) as $e) {
                if (!is_array($e)) continue;
                $tit = (string)($e['title'] ?? '');
                if ($tit !== '' && self::esPack($tit, $variantesNorm)) continue;
                $n = self::paginasDeFichaOL($e);
                if ($n <= 0) continue;
                $cands[] = $n;
                if (self::tituloExacto($tit, $variantesNorm)) $exs[] = $n;
            }
            $log['candidatas'] = $cands;
            $log['candidatas_titulo_exacto'] = $exs;
            if ($cands) {
                $n = self::consenso($exs ?: $cands);
                $log['resultado'] = $n;
                $log['fuente'] = 'Open Library: ediciones de la obra ' . $ref . ($exs ? ' (título idéntico)' : '');
                return ['paginas' => $n, 'fiabilidad' => 'media'];
            }
        }

        // Fase 1: datos exactos (mismo id o ISBN). Fase 2 (solo si no basta): búsquedas por título y autor.
        $urls1 = [];
        if ($esIdGoogle) $urls1['g_id'] = "https://www.googleapis.com/books/v1/volumes/" . urlencode($ref) . ($k ? '?' . ltrim($k, '&') : '');
        if ($esIsbn) {
            $urls1['g_isbn']  = "https://www.googleapis.com/books/v1/volumes?q=isbn:" . urlencode($ref) . "&maxResults=3" . $k;
            $urls1['ol_isbn'] = "https://openlibrary.org/api/books?bibkeys=ISBN:" . urlencode($ref) . "&format=json&jscmd=data";
        }

        $urls2 = [];
        foreach (array_slice($variantes, 0, 2) as $i => $v) {
            $q = 'intitle:"' . $v . '"' . ($autorPrincipal !== '' ? ' inauthor:"' . $autorPrincipal . '"' : '');
            $urls2["g_t$i"] = "https://www.googleapis.com/books/v1/volumes?q=" . urlencode($q) . "&printType=books&maxResults=15" . $k;
            if ($i === 0) $urls2["g_q$i"] = "https://www.googleapis.com/books/v1/volumes?q=" . urlencode(trim($v . ' ' . $autorPrincipal)) . "&printType=books&maxResults=15" . $k;

            $ol = ['title' => $v, 'limit' => 10, 'fields' => 'key,title,author_name,number_of_pages_median,isbn'];
            if ($autorPrincipal !== '') $ol['author'] = $autorPrincipal;
            $urls2["ol_t$i"] = 'https://openlibrary.org/search.json?' . http_build_query($ol);
        }

        // Google no atiende peticiones sin clave
        if ($clave === '') {
            $soloOL = fn($u, $kk) => !str_starts_with($kk, 'g_');
            $urls1 = array_filter($urls1, $soloOL, ARRAY_FILTER_USE_BOTH);
            $urls2 = array_filter($urls2, $soloOL, ARRAY_FILTER_USE_BOTH);
            $log['aviso_google'] = 'No hay google_books_key en config/config.local.php: Google Books se omite.';
        }

        $R = $urls1 ? self::peticionesConReintento($urls1, 5, 2) : [];
        $registrar($R);

        $isbnsExtra = [];

        // 1) Resultados exactos (mismo id o mismo ISBN)
        if (!empty($R['g_id']['json']['volumeInfo'])) {
            $vi = $R['g_id']['json']['volumeInfo'];
            $n = self::paginasDeVolumen($vi);
            $log['g_id_detalle'] = 'pageCount=' . ($vi['pageCount'] ?? 'no') . ', printedPageCount=' . ($vi['printedPageCount'] ?? 'no');
            if ($n > 0) { $log['resultado'] = $n; $log['fuente'] = 'Google (id del libro)'; return ['paginas' => $n, 'fiabilidad' => 'exacta']; }
            foreach (self::isbnsDeVolumen($vi) as $i) $isbnsExtra[$i] = true;
        }
        foreach (['g_isbn'] as $kg) {
            foreach (($R[$kg]['json']['items'] ?? []) as $it) {
                $vi = $it['volumeInfo'] ?? [];
                $n = self::paginasDeVolumen($vi);
                $log['google_isbn_detalle'][] = "$kg: «" . ($vi['title'] ?? '?') . "» pageCount=" . ($vi['pageCount'] ?? 'no') . ", printedPageCount=" . ($vi['printedPageCount'] ?? 'no');
                if ($n > 0) { $log['resultado'] = $n; $log['fuente'] = 'Google (ISBN)'; return ['paginas' => $n, 'fiabilidad' => 'exacta']; }
            }
        }
        if (!empty($R['ol_isbn']['json']) && is_array($R['ol_isbn']['json'])) {
            $ficha = reset($R['ol_isbn']['json']);
            $ficha = is_array($ficha) ? $ficha : [];
            $log['ol_isbn_detalle'] = 'title=' . ($ficha['title'] ?? '?') . ', number_of_pages=' . ($ficha['number_of_pages'] ?? 'no') . ', pagination=' . ($ficha['pagination'] ?? 'no');
            $n = self::paginasDeFichaOL($ficha);
            if ($n > 0) { $log['resultado'] = $n; $log['fuente'] = 'Open Library (ISBN)'; return ['paginas' => $n, 'fiabilidad' => 'exacta']; }
        }

        // Fase 2: búsquedas por título y autor
        $R2 = self::peticionesConReintento($urls2, 5, 2);
        $registrar($R2);
        $R = array_merge($R, $R2);

        // 2) Búsquedas por título y autor: solo valen libros que coinciden (y que no sean packs/estuches/diarios)
        $candidatas = [];
        $exactas = [];   
        foreach ($R as $kk => $x) {
            $resp = $x['json'];
            if (!$resp) continue;

            if (str_starts_with($kk, 'g_t') || str_starts_with($kk, 'g_q')) {
                foreach (($resp['items'] ?? []) as $it) {
                    $vi = $it['volumeInfo'] ?? [];
                    $tit = (string)($vi['title'] ?? '');
                    $okT = self::pareceMismoTitulo($tit, $variantesNorm);
                    $okA = self::pareceMismoAutor($vi['authors'] ?? [], $apellido);
                    if (!$okT || !$okA) {
                        if (count($log['descartados']) < 10) $log['descartados'][] = "Google: «{$tit}» de " . implode(', ', $vi['authors'] ?? ['?']) . ' → ' . (!$okT ? 'título distinto' : 'autor distinto');
                        continue;
                    }
                    if (self::esPack($tit, $variantesNorm)) {
                        if (count($log['descartados']) < 10) $log['descartados'][] = "Google: «{$tit}» → pack/estuche/diario";
                        continue;
                    }
                    $n = self::paginasDeVolumen($vi);
                    if ($n > 0) {
                        $candidatas[] = $n;
                        if (self::tituloExacto($tit, $variantesNorm)) $exactas[] = $n;
                    }
                    else foreach (self::isbnsDeVolumen($vi) as $i) $isbnsExtra[$i] = true;
                }
            } elseif (str_starts_with($kk, 'ol_t')) {
                foreach (($resp['docs'] ?? []) as $d) {
                    $tit = (string)($d['title'] ?? '');
                    $okT = self::pareceMismoTitulo($tit, $variantesNorm);
                    $okA = self::pareceMismoAutor($d['author_name'] ?? [], $apellido);
                    if (!$okT || !$okA) {
                        if (count($log['descartados']) < 10) $log['descartados'][] = "OpenLibrary: «{$tit}» de " . implode(', ', $d['author_name'] ?? ['?']) . ' → ' . (!$okT ? 'título distinto' : 'autor distinto');
                        continue;
                    }
                    if (self::esPack($tit, $variantesNorm)) {
                        if (count($log['descartados']) < 10) $log['descartados'][] = "OpenLibrary: «{$tit}» → pack/estuche/diario";
                        continue;
                    }
                    $n = self::paginasValidas($d['number_of_pages_median'] ?? 0);
                    if ($n > 0) {
                        $candidatas[] = $n;
                        if (self::tituloExacto($tit, $variantesNorm)) $exactas[] = $n;
                    }
                    foreach (array_slice($d['isbn'] ?? [], 0, 4) as $i) {
                        $i = strtoupper(preg_replace('/[^0-9Xx]/', '', (string)$i));
                        if (preg_match('/^(\d{9}[\dX]|\d{13})$/', $i)) $isbnsExtra[$i] = true;
                    }
                }
            }
        }

        // 3) Si no hay páginas, se prueban los ISBN de ese mismo libro en Open Library
        if (!$candidatas && $isbnsExtra) {
            $lista = array_slice(array_keys($isbnsExtra), 0, 10);
            $log['isbn_extra'] = $lista;
            $claves = implode(',', array_map(fn($i) => 'ISBN:' . $i, $lista));
            $R2 = self::peticionesConReintento([
                'ol_isbns' => 'https://openlibrary.org/api/books?bibkeys=' . $claves . '&format=json&jscmd=data',
            ], 6, 2);
            $log['peticiones']['ol_isbns'] = 'HTTP ' . $R2['ol_isbns']['http'] . ($R2['ol_isbns']['error'] ? ' (' . $R2['ol_isbns']['error'] . ')' : '');
            foreach (($R2['ol_isbns']['json'] ?? []) as $claveIsbn => $ficha) {
                if (!is_array($ficha)) continue;
                $log['ol_isbns_detalle'][] = $claveIsbn . ': number_of_pages=' . ($ficha['number_of_pages'] ?? 'no') . ', pagination=' . ($ficha['pagination'] ?? 'no');
                if (self::esPack((string)($ficha['title'] ?? ''), $variantesNorm)) continue;
                $n = self::paginasDeFichaOL($ficha);
                if ($n > 0) $candidatas[] = $n;
            }
        }

        $log['candidatas'] = $candidatas;
        $log['candidatas_titulo_exacto'] = $exactas;
        if ($candidatas) {
            $base = $exactas ?: $candidatas;
            $n = self::consenso($base);
            $log['resultado'] = $n;
            $log['fuente'] = 'consenso de ' . count($base) . ' ediciones' . ($exactas ? ' con título idéntico' : ' con título parecido');
            return ['paginas' => $n, 'fiabilidad' => 'media'];
        }
        return ['paginas' => 0, 'fiabilidad' => ''];
    }

    /*  utilidades  */
    private static function consenso(array $vals): int {
        $vals = array_values(array_map('intval', $vals));
        sort($vals);
        $mejor = [];
        foreach ($vals as $v) {
            $grupo = array_values(array_filter($vals, fn($w) => abs($w - $v) <= $v * 0.08));
            if (count($grupo) > count($mejor)) $mejor = $grupo;  
        }
        return $mejor ? (int)$mejor[intdiv(count($mejor) - 1, 2)] : 0;
    }

    private static function tituloExacto(string $candidato, array $variantesNorm): bool {
        $c = self::normalizar($candidato) . ' ';
        foreach ($variantesNorm as $v) {
            if ($v !== '' && str_starts_with($c, $v . ' ')) return true;
        }
        return false;
    }

    private static function esPack(string $candidato, array $variantesNorm): bool {
        if (str_contains($candidato, '+')) return true;
        $c = ' ' . self::normalizar($candidato) . ' ';
        $propios = ' ' . implode(' ', $variantesNorm) . ' ';
        foreach (self::PALABRAS_PACK as $p) {
            $pn = ' ' . self::normalizar($p) . ' ';
            if (str_contains($c, $pn) && !str_contains($propios, $pn)) return true;
        }
        return false;
    }

    private static function normalizar(string $t): string {
        $t = strtr(mb_strtolower($t, 'UTF-8'), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $t)));
    }

    private static function pareceMismoTitulo(string $candidato, array $variantesNorm): bool {
        $c = self::normalizar($candidato);
        if ($c === '') return false;
        foreach ($variantesNorm as $v) {
            if ($v === '') continue;
            similar_text($c, $v, $pct);
            if ($pct >= 70) return true;
            if (strlen($v) >= 5 && (str_contains($c, $v) || str_contains($v, $c))) return true;
        }
        return false;
    }

    private static function pareceMismoAutor(array $autoresCandidato, string $apellido): bool {
        if ($apellido === '') return true;  
        foreach ($autoresCandidato as $a) {
            if (str_contains(self::normalizar((string)$a), $apellido)) return true;
        }
        return false;
    }

    private static function paginasValidas($n): int {
        $n = (int)$n;
        return ($n >= 20 && $n <= self::MAX_PAGINAS) ? $n : 0;
    }

    private static function paginasDeVolumen(array $vi): int {
        $n = self::paginasValidas($vi['pageCount'] ?? 0);
        return $n > 0 ? $n : self::paginasValidas($vi['printedPageCount'] ?? 0);
    }

    private static function paginasDeFichaOL(array $f): int {
        $n = self::paginasValidas($f['number_of_pages'] ?? 0);
        if ($n > 0) return $n;
        if (preg_match('/(\d{2,4})\s*(p\b|pp\b|p\.|pág|pag)/iu', (string)($f['pagination'] ?? ''), $m)) {
            return self::paginasValidas($m[1]);
        }
        return 0;
    }

    private static function isbnsDeVolumen(array $vi): array {
        $salida = [];
        foreach (($vi['industryIdentifiers'] ?? []) as $x) {
            $v = preg_replace('/[^0-9Xx]/', '', (string)($x['identifier'] ?? ''));
            if (preg_match('/^(\d{9}[\dXx]|\d{13})$/', $v)) $salida[] = strtoupper($v);
        }
        return $salida;
    }

    private static function peticionesParalelas(array $urls, int $timeout = 5): array {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $k => $u) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_ENCODING       => '',
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$k] = $ch;
        }

        $running = null;
        do {
            $estado = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.5);
        } while ($running && $estado === CURLM_OK);

        $salida = [];
        foreach ($handles as $k => $ch) {
            $cuerpo = curl_multi_getcontent($ch);
            $http   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $json   = ($http >= 200 && $http < 300 && $cuerpo) ? (json_decode($cuerpo, true) ?: null) : null;
            $salida[$k] = ['http' => $http, 'json' => $json, 'error' => curl_error($ch)];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $salida;
    }

    private static function peticionesConReintento(array $urls, int $timeout = 5, int $reintentos = 2): array {
        $r = self::peticionesParalelas($urls, $timeout);
        for ($i = 1; $i <= $reintentos; $i++) {
            $pendientes = [];
            foreach ($r as $k => $x) {
                if ($x['http'] === 0 || $x['http'] >= 500) $pendientes[$k] = $urls[$k];
            }
            if (!$pendientes) break;
            usleep(700000 * $i);
            foreach (self::peticionesParalelas($pendientes, $timeout) as $k => $x) $r[$k] = $x;
        }
        return $r;
    }
}