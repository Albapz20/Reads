<?php
class AutorService
{
    const USER_AGENT = 'ReadsApp/1.0 (tu-email@ejemplo.com)';
    const SSL_VERIFY = false;  

    private const MAPA_IDIOMA = [
        'spa' => ['es', 'spa'], 'eng' => ['en', 'eng'], 'cat' => ['ca', 'cat'],
        'fra' => ['fr', 'fre'], 'ita' => ['it', 'ita'], 'por' => ['pt', 'por'],
        'deu' => ['de', 'ger'],
    ];

    private bool $errorRed = false;  

    public function __construct(
        private string $apiKey = '',
        private string $langGb = '',
        private string $langOl = ''
    ) {}

    /** @return array{0:string,1:string} [código 2 letras, código Open Library] */
    public static function idiomasUsuario(PDO $pdo, int $usuarioId): array
    {
        $pref = 'spa';
        try {
            $st = $pdo->prepare("SELECT idioma_lectura FROM ajustes_usuario WHERE usuario_id = ? LIMIT 1");
            $st->execute([$usuarioId]);
            $v = $st->fetchColumn();
            if ($v !== false && $v !== null) $pref = (string)$v;   // '' = cualquier idioma
        } catch (Throwable $e) {}
        return self::MAPA_IDIOMA[$pref] ?? ['', ''];
    }

    /** Lista de obras del autor */
    public function librosDelAutor(string $nombre): array
    {
        $clave = self::claveAutor($nombre);
        $k = 'autor2_' . md5($clave . '|' . $this->langOl);

        $c = $this->cacheGet($k);
        if ($c !== null) return $c['v'];

        $libros = $this->librosOpenLibrary($nombre, $clave);

        // Si Open Library aporta poco, completar con Google Books
        if (count($libros) < 3) {
            $vistos = [];
            foreach ($libros as $l) $vistos[self::tituloBase($l['titulo'])] = true;
            foreach ($this->librosGoogle($nombre, $clave) as $l) {
                $b = self::tituloBase($l['titulo']);
                if ($b === '' || isset($vistos[$b])) continue;
                $vistos[$b] = true;
                $libros[] = $l;
            }
        }

        if (!empty($libros)) $this->cachePut($k, $libros, 12 * 3600);
        return $libros;
    }

    /** Ficha del autor */
    public function fichaAutor(string $nombre): ?array
    {
        $clave = self::claveAutor($nombre);
        $k = 'ficha_' . md5($clave);

        $c = $this->cacheGet($k);
        if ($c !== null) return $c['v'];

        $json = $this->getJson('https://openlibrary.org/search/authors.json?q=' . urlencode($nombre) . '&limit=5');
        $ficha = null;
        foreach ($json['docs'] ?? [] as $d) {
            if (self::claveAutor((string)($d['name'] ?? '')) !== $clave) continue;
            $olid = (string)($d['key'] ?? '');
            $ficha = [
                'nombre'     => (string)$d['name'],
                'obras'      => isset($d['work_count']) ? (int)$d['work_count'] : null,
                'nacimiento' => (string)($d['birth_date'] ?? ''),
                'foto'       => $olid !== '' ? 'https://covers.openlibrary.org/a/olid/' . rawurlencode($olid) . '-M.jpg?default=false' : '',
            ];
            break;
        }
        if ($json !== null) $this->cachePut($k, $ficha, 7 * 86400);
        return $ficha;
    }

    private function librosOpenLibrary(string $nombre, string $clave): array
    {
        $url = 'https://openlibrary.org/search.json?' . http_build_query(array_filter([
            'q'      => 'author:"' . $nombre . '"',
            'limit'  => 100,
            'fields' => 'key,title,author_name,cover_i,first_publish_year,language,editions,editions.title,editions.cover_i,editions.language',
            'lang'   => $this->langGb !== '' ? $this->langGb : null,
        ], fn($v) => $v !== null));

        $json = $this->getJson($url, [], 10);
        $ruido = '/\b(study guide|summary of|analysis of|sparknotes|cliffsnotes|trivia|workbook|boxed set|box set)\b/i';

        $salida = [];
        $vistos = [];
        foreach ($json['docs'] ?? [] as $d) {
            // el autor debe coincidir exactamente (evita homónimos)
            $autores = is_array($d['author_name'] ?? null) ? $d['author_name'] : [];
            if (!in_array($clave, array_map([self::class, 'claveAutor'], $autores), true)) continue;

            $titulo  = (string)($d['title'] ?? '');
            $coverId = $d['cover_i'] ?? null;
            $tituloOrig = $titulo;      // título y portada de la obra original
            $coverOrig  = $coverId;

            // Si hay idioma elegido, usar el título y la portada de la edición en ese idioma
            if ($this->langOl !== '') {
                $ed = $d['editions']['docs'][0] ?? null;
                if (is_array($ed) && in_array($this->langOl, (array)($ed['language'] ?? []), true)) {
                    if (!empty($ed['title']))   $titulo  = (string)$ed['title'];
                    if (!empty($ed['cover_i'])) $coverId = $ed['cover_i'];
                }
            }

            $base = self::tituloBase($titulo);
            if ($titulo === '' || $base === '' || isset($vistos[$base]) || preg_match($ruido, $titulo)) continue;
            $vistos[$base] = true;

            $salida[] = [
                'id'      => basename((string)($d['key'] ?? '')),   
                'titulo'  => $titulo,
                'anio'    => !empty($d['first_publish_year']) ? (int)$d['first_publish_year'] : null,
                'portada' => !empty($coverId) ? 'https://covers.openlibrary.org/b/id/' . (int)$coverId . '-M.jpg' : '',
                'titulo_original'  => $tituloOrig,
                'portada_original' => !empty($coverOrig) ? 'https://covers.openlibrary.org/b/id/' . (int)$coverOrig . '-M.jpg' : '',
                'idiomas' => array_values(array_map('strval', (array)($d['language'] ?? []))),   
            ];
        }
        return $salida;
    }

    private function codigoOl(string $gb): string
    {
        foreach (self::MAPA_IDIOMA as $par) {
            if ($par[0] === $gb) return $par[1];
        }
        return $gb;
    }

    private function librosGoogle(string $nombre, string $clave): array
    {
        if ($this->apiKey === '') return [];

        $json = $this->getJson('https://www.googleapis.com/books/v1/volumes?' . http_build_query([
            'q' => 'inauthor:"' . $nombre . '"', 'printType' => 'books', 'maxResults' => 40, 'key' => $this->apiKey,
        ]), [], 10);

        $salida = [];
        $vistos = [];
        foreach ($json['items'] ?? [] as $it) {
            $vi = $it['volumeInfo'] ?? [];
            if (!in_array($clave, array_map([self::class, 'claveAutor'], $vi['authors'] ?? []), true)) continue;

            $titulo = (string)($vi['title'] ?? '');
            $base = self::tituloBase($titulo);
            if ($titulo === '' || $base === '' || isset($vistos[$base])) continue;
            $vistos[$base] = true;

            $img = $vi['imageLinks']['thumbnail'] ?? $vi['imageLinks']['smallThumbnail'] ?? '';
            $img = $img !== '' ? str_replace('http://', 'https://', $img) : '';
            $salida[] = [
                'id'      => (string)($it['id'] ?? ''),
                'titulo'  => $titulo,
                'anio'    => !empty($vi['publishedDate']) ? (int)substr((string)$vi['publishedDate'], 0, 4) ?: null : null,
                'portada' => $img,
                'titulo_original'  => $titulo,
                'portada_original' => $img,
                'idiomas' => !empty($vi['language']) ? [$this->codigoOl((string)$vi['language'])] : [],
            ];
        }
        return $salida;
    }

    /* Saga */

    /**
     * @return array|null ['nombre' => string, 'numero' => ?string,
     *                     'libros' => [['titulo','numero','anio','qid','actual'], ...],
     *                     'fuente' => 'wikidata'|'titulo']
     */
    public function saga(string $titulo, string $autor): ?array
    {
        $clave = self::claveAutor(trim(explode(',', $autor)[0]));
        $k = 'saga_' . md5(self::tituloBase($titulo) . '|' . $clave . '|' . $this->langGb);

        $c = $this->cacheGet($k);
        if ($c !== null) return $c['v'];

        $this->errorRed = false;
        $res = $this->sagaWikidata($titulo, $clave) ?? $this->sagaPorTitulo($titulo);

        // No guardar un "no hay saga" si fue por un fallo de red (puede ser temporal)
        if ($res !== null || !$this->errorRed) {
            $this->cachePut($k, $res, $res !== null ? 30 * 86400 : 2 * 86400);
        }
        return $res;
    }

    private function sagaPorTitulo(string $titulo): ?array
    {
        $patron = '/[\(\[]\s*([^()\[\]#]+?)\s*[,:]?\s*(?:#|n[.º°]\s*|no\.?\s*|book\s+|libro\s+|vol\.?\s*|volumen\s+|tome?\s+)(\d+(?:\.\d+)?)\s*[\)\]]/iu';
        if (!preg_match($patron, $titulo, $m)) return null;
        return ['nombre' => trim($m[1]), 'numero' => $m[2], 'libros' => [], 'fuente' => 'titulo'];
    }

    private function sagaWikidata(string $titulo, string $claveAutor): ?array
    {
        $sin = trim(preg_replace('/\s+/', ' ', preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $titulo)));

        // Variantes: título completo, y partes antes/después de ":"
        $variantes = [$sin];
        if (str_contains($sin, ':')) {
            [$a, $b] = array_map('trim', explode(':', $sin, 2));
            $variantes[] = $b;
            $variantes[] = $a;
        }
        $variantes = array_values(array_unique(array_filter($variantes, fn($v) => mb_strlen($v) >= 3)));
        $idiomas   = array_values(array_unique(array_filter([$this->langGb !== '' ? $this->langGb : null, 'en'])));

        foreach ($variantes as $v) {
            $ids = [];
            foreach ($idiomas as $l) {
                $r = $this->getJson('https://www.wikidata.org/w/api.php?' . http_build_query([
                    'action' => 'wbsearchentities', 'search' => $v, 'language' => $l, 'uselang' => $l,
                    'type' => 'item', 'limit' => 8, 'format' => 'json',
                ]));
                foreach ($r['search'] ?? [] as $s) {
                    if (!empty($s['id'])) $ids[$s['id']] = true;
                }
            }
            if (!$ids) continue;

            $fila = $this->wdVerificar(array_keys($ids), $claveAutor);
            if ($fila !== null) return $this->wdMiembros($fila);
        }
        return null;
    }

    private function etiquetasWd(): string
    {
        return ($this->langGb !== '' ? $this->langGb . ',' : '') . 'en';
    }

    /** Entre los candidatos, busca el que sea de este autor y pertenezca a una serie */
    private function wdVerificar(array $ids, string $claveAutor): ?array
    {
        $values = implode(' ', array_map(
            fn($i) => 'wd:' . preg_replace('/[^Q0-9]/', '', (string)$i),
            array_slice($ids, 0, 16)
        ));

        $q = 'SELECT ?book ?series ?seriesLabel ?ord ?authorLabel WHERE {
                VALUES ?book { ' . $values . ' }
                ?book wdt:P50 ?author .
                ?book p:P179 ?st . ?st ps:P179 ?series .
                OPTIONAL { ?st pq:P1545 ?ord . }
                SERVICE wikibase:label { bd:serviceParam wikibase:language "' . $this->etiquetasWd() . '". }
              }';

        foreach ($this->sparql($q) as $r) {
            if (self::claveAutor((string)($r['authorLabel']['value'] ?? '')) !== $claveAutor) continue;
            $nombre = (string)($r['seriesLabel']['value'] ?? '');
            if ($nombre === '' || preg_match('/^Q\d+$/', $nombre)) continue;
            return [
                'book'  => basename((string)$r['book']['value']),
                'serie' => basename((string)$r['series']['value']),
                'nombre' => $nombre,
                'ord'   => isset($r['ord']['value']) ? $this->numeroLimpio((string)$r['ord']['value']) : null,
            ];
        }
        return null;
    }

    /** Todos los libros de la serie, ordenados */
    private function wdMiembros(array $fila): array
    {
        $serie = preg_replace('/[^Q0-9]/', '', $fila['serie']);
        $q = 'SELECT ?book ?bookLabel ?ord ?pub WHERE {
                ?book p:P179 ?st . ?st ps:P179 wd:' . $serie . ' .
                ?book wdt:P50 ?autor .
                OPTIONAL { ?st pq:P1545 ?ord . }
                OPTIONAL { ?book wdt:P577 ?pub . }
                SERVICE wikibase:label { bd:serviceParam wikibase:language "' . $this->etiquetasWd() . '". }
              } LIMIT 120';

        $porQid = [];
        foreach ($this->sparql($q) as $r) {
            $qid = basename((string)($r['book']['value'] ?? ''));
            $lab = (string)($r['bookLabel']['value'] ?? '');
            if ($qid === '' || $lab === '' || preg_match('/^Q\d+$/', $lab)) continue;

            $anio = isset($r['pub']['value']) ? ((int)substr((string)$r['pub']['value'], 0, 4) ?: null) : null;
            if (!isset($porQid[$qid])) {
                $porQid[$qid] = [
                    'titulo' => $lab,
                    'numero' => isset($r['ord']['value']) ? $this->numeroLimpio((string)$r['ord']['value']) : null,
                    'anio'   => $anio,
                    'qid'    => $qid,
                    'actual' => $qid === $fila['book'],
                ];
            } elseif ($anio !== null && ($porQid[$qid]['anio'] === null || $anio < $porQid[$qid]['anio'])) {
                $porQid[$qid]['anio'] = $anio;
            }
        }

        // Quitar duplicados por título (distintas ediciones de la misma obra)
        $libros = [];
        $vistos = [];
        foreach ($porQid as $l) {
            $b = self::tituloBase($l['titulo']);
            if (isset($vistos[$b])) {
                if ($l['actual']) $libros[$vistos[$b]]['actual'] = true;
                continue;
            }
            $vistos[$b] = count($libros);
            $libros[] = $l;
        }

        usort($libros, function ($a, $b) {
            $na = is_numeric($a['numero']) ? (float)$a['numero'] : null;
            $nb = is_numeric($b['numero']) ? (float)$b['numero'] : null;
            if ($na !== null && $nb !== null && $na !== $nb) return $na <=> $nb;
            if ($na !== null && $nb === null) return -1;
            if ($na === null && $nb !== null) return 1;
            return ($a['anio'] ?? 9999) <=> ($b['anio'] ?? 9999);
        });

        return ['nombre' => $fila['nombre'], 'numero' => $fila['ord'], 'libros' => $libros, 'fuente' => 'wikidata'];
    }

    private function numeroLimpio(string $n): string
    {
        $n = trim($n);
        return str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n;
    }

    private function sparql(string $query): array
    {
        $r = $this->getJson(
            'https://query.wikidata.org/sparql?format=json&query=' . rawurlencode($query),
            ['Accept: application/sparql-results+json'],
            10
        );
        return $r['results']['bindings'] ?? [];
    }

    /* HTTP y caché */

    /** @return array|null null = fallo de red/servidor; [] = respuesta vacía */
    private function getJson(string $url, array $headers = [], int $timeout = 6): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => self::SSL_VERIFY,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false || $code >= 500 || $code === 429) {
            $this->errorRed = true;
            return null;
        }
        $json = json_decode((string)$resp, true);
        return is_array($json) ? $json : [];
    }

    private function cacheDir(): string
    {
        $d = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'reads_cache';
        if (!is_dir($d)) @mkdir($d, 0777, true);
        return $d;
    }

    /** @return array{v:mixed}|null  null = no hay caché válida */
    private function cacheGet(string $k): ?array
    {
        $f = $this->cacheDir() . DIRECTORY_SEPARATOR . $k . '.json';
        if (!is_file($f)) return null;
        $j = json_decode((string)@file_get_contents($f), true);
        if (!is_array($j) || !array_key_exists('v', $j) || ($j['exp'] ?? 0) < time()) return null;
        return ['v' => $j['v']];
    }

    private function cachePut(string $k, mixed $v, int $ttl): void
    {
        $f = $this->cacheDir() . DIRECTORY_SEPARATOR . $k . '.json';
        @file_put_contents($f, json_encode(['exp' => time() + $ttl, 'v' => $v], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /* Helpers de texto */

    public static function limpiarTexto(string $s): string
    {
        $s = strtr(mb_strtolower($s, 'UTF-8'), [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
            'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
        ]);
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/', ' ', $s)));
    }

    public static function sinParentesis(string $t): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $t)));
    }

    public static function tituloBase(string $t): string
    {
        $t = self::sinParentesis($t);
        return self::limpiarTexto(preg_split('/\s*[:\x{2013}\x{2014}]\s*|\s+-\s+/u', $t)[0] ?? $t);
    }

    public static function claveAutor(string $n): string
    {
        $t = array_values(array_filter(explode(' ', self::limpiarTexto($n)), fn($w) => strlen($w) > 1));
        return count($t) >= 2 ? $t[0] . ' ' . end($t) : implode(' ', $t);
    }
}