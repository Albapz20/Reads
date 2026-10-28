<?php

class RecomendacionesService
{
    const NUM_BASE       = 2;   // últimos libros leídos del año usados como base
    const MAX_POR_AUTOR  = 3;   // máx. libros del mismo autor por libro base
    const CUPO_POR_LIBRO = 6;   // objetivo de recomendaciones por libro base
    const TOTAL_MIN      = 6;
    const TOTAL_MAX      = 12;
    const MAX_INTENTOS   = 2;   // reintentos ante fallos temporales de una fuente
    const ORDEN_FUENTES  = ['google', 'openlibrary', 'local'];
    const USER_AGENT = 'ReadsApp/1.0 (albaperezcc64.com)';

    // código de 3 letras de Ajustes => [Google Books, Open Library]
    private const MAPA_IDIOMA = [
        'spa' => ['es', 'spa'], 'eng' => ['en', 'eng'], 'cat' => ['ca', 'cat'],
        'fra' => ['fr', 'fre'], 'ita' => ['it', 'ita'], 'por' => ['pt', 'por'],
        'deu' => ['de', 'ger'],
    ];

    private PDO $pdo;
    private string $apiKey;
    private int $usuarioId = 0;
    private string $langGb = '';     
    private string $langOl = '';
    private array $vistos = [];
    private array $autoresGlobal = [];
    private array $yaTiene = ['ids' => [], 'bases' => []];
    private array $caida = [];       
    private array $fuentes = [];
    public array $log = [];

    public function __construct(PDO $pdo, string $apiKey = '')
    {
        $this->pdo    = $pdo;
        $this->apiKey = $apiKey;
    }

    /*  API pública  */
    public function recomendar(int $usuarioId, int $year): array
    {
        $base = $this->getLibrosBase($usuarioId, $year);
        if (empty($base)) {
            return ['basadoEn' => [], 'libros' => []];
        }

        $this->usuarioId = $usuarioId;
        [$this->langGb, $this->langOl] = $this->getIdioma($usuarioId);
        $this->cargarYaTiene($usuarioId);

        $autores   = [];
        $generosDB = [];
        foreach ($base as $i => $libro) {
            $autores[$i]   = trim(explode(',', $libro['autores'] ?? '')[0]);
            $generosDB[$i] = $this->generosDe([(string)($libro['categorias'] ?? '')], 3);
        }

        //  Recorrer las fuentes hasta tener suficientes libros 
        $porLibro     = array_fill_keys(array_keys($base), []);
        $relleno      = [];
        $rellenoOtros = [];

        foreach (self::ORDEN_FUENTES as $fuente) {
            if ($this->total($porLibro) >= self::TOTAL_MIN) break;
            $antes = $this->total($porLibro);

            $cand = match ($fuente) {
                'google'      => $this->candidatosGoogle($base, $autores, $generosDB),
                'openlibrary' => $this->candidatosOpenLibrary($base, $autores, $generosDB),
                default       => $this->candidatosLocal($base, $autores, $generosDB),
            };

            $this->seleccionar($base, $autores, $cand, $porLibro);
            $relleno      = array_merge($relleno, $cand['relleno']);
            $rellenoOtros = array_merge($rellenoOtros, $cand['rellenoOtros']);
            $this->fuentes[] = $fuente . ' (+' . ($this->total($porLibro) - $antes) . ')';
        }

        //  Mezclar alternando entre los libros base 
        $resultado = [];
        $max = empty($porLibro) ? 0 : max(array_map('count', $porLibro));
        for ($k = 0; $k < $max; $k++) {
            foreach ($porLibro as $lista) {
                if (isset($lista[$k])) $resultado[] = $lista[$k];
            }
        }

        //  Rellenos si quedó corto 
        if (count($resultado) < self::TOTAL_MIN) {
            foreach ($relleno as $l) {
                $this->aceptar($resultado, $l);
                if (count($resultado) >= self::TOTAL_MAX) break;
            }
        }
        if (count($resultado) < 4) {
            foreach ($rellenoOtros as $l) {
                $this->aceptar($resultado, $l, null, false);
                if (count($resultado) >= self::TOTAL_MIN) break;
            }
        }

        $this->log['_fuentes'] = implode(' → ', $this->fuentes);

        return [
            'basadoEn' => array_map(fn($u) => $this->sinParentesis($u['titulo']), $base),
            'libros'   => array_slice($resultado, 0, self::TOTAL_MAX),
        ];
    }

    /*  Datos del usuario  */
    private function getLibrosBase(int $usuarioId, int $year): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT libro_id, titulo, autores, categorias
             FROM listas_lectura
             WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?
             ORDER BY fecha_fin DESC, id DESC
             LIMIT " . (int)self::NUM_BASE
        );
        $stmt->execute([$usuarioId, $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0:string,1:string} [código Google, código Open Library] */
    private function getIdioma(int $usuarioId): array
    {
        $pref = 'spa';
        try {
            $stmt = $this->pdo->prepare("SELECT idioma_lectura FROM ajustes_usuario WHERE usuario_id = ? LIMIT 1");
            $stmt->execute([$usuarioId]);
            $v = $stmt->fetchColumn();
            if ($v !== false && $v !== null) $pref = (string)$v;   // '' = cualquier idioma
        } catch (Exception $e) {}
        return self::MAPA_IDIOMA[$pref] ?? ['', ''];
    }

    /** Todo lo que el usuario ya tiene en cualquier lista (para no recomendarlo) */
    private function cargarYaTiene(int $usuarioId): void
    {
        $stmt = $this->pdo->prepare("SELECT libro_id, titulo FROM listas_lectura WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $this->yaTiene['ids'][] = (string)$r['libro_id'];
            $b = $this->tituloBase((string)$r['titulo']);
            if ($b !== '') $this->yaTiene['bases'][$b] = true;
        }
    }

    /*  Selección común a todas las fuentes  */

    private function total(array $porLibro): int
    {
        return array_sum(array_map('count', $porLibro));
    }

    /** Reparte los candidatos de una fuente entre los libros base */
    private function seleccionar(array $base, array $autores, array $cand, array &$porLibro): void
    {
        foreach ($base as $i => $libro) {
            // Obras del mismo autor
            $clave = $autores[$i] !== '' ? $this->claveAutor($autores[$i]) : null;
            if ($clave !== null) {
                $delAutor = 0;
                foreach ($cand['autor'][$i] ?? [] as $l) {
                    if ($this->aceptar($porLibro[$i], $l, $clave)) $delAutor++;
                    if ($delAutor >= self::MAX_POR_AUTOR) break;
                }
            }
            // Libros de los mismos géneros
            foreach ($cand['generos'][$i] ?? [] as $g) {
                if (count($porLibro[$i]) >= self::CUPO_POR_LIBRO) break;
                foreach ($cand['porGenero'][$g] ?? [] as $l) {
                    $this->aceptar($porLibro[$i], $l);
                    if (count($porLibro[$i]) >= self::CUPO_POR_LIBRO) break;
                }
            }
        }
    }

    private function aceptar(array &$destino, array $l, ?string $exigirAutor = null, bool $filtrarIdioma = true): bool
    {
        if ($l['id'] === '' || $l['titulo'] === '' || $l['portada'] === '' || !$l['conDesc']) return false;
        if ($l['paginas'] > 0 && $l['paginas'] < 60) return false;
        if ($filtrarIdioma && $this->langGb !== '' && $l['idioma'] !== '' && $l['idioma'] !== $this->langGb) return false;
        if ($exigirAutor !== null && !in_array($exigirAutor, array_map([$this, 'claveAutor'], $l['autores']), true)) return false;

        $base = $this->tituloBase($l['titulo']);
        if ($base === '' || isset($this->vistos[$base]) || isset($this->yaTiene['bases'][$base])) return false;
        if (in_array($l['id'], $this->yaTiene['ids'], true)) return false;

        $claveA = $this->claveAutor($l['autor']);
        if ($exigirAutor === null && ($this->autoresGlobal[$claveA] ?? 0) >= 2) return false;

        $this->autoresGlobal[$claveA] = ($this->autoresGlobal[$claveA] ?? 0) + 1;
        $this->vistos[$base] = true;
        $destino[] = [
            'id'      => $l['id'],
            'titulo'  => $l['titulo'],
            'autor'   => $l['autor'],
            'portada' => $l['portada'],
            'url'     => $l['url'] ?? null,     
            'fuente'  => $l['fuente'],
        ];
        return true;
    }

    private function candidatoVacio(): array
    {
        return ['autor' => [], 'generos' => [], 'porGenero' => [], 'relleno' => [], 'rellenoOtros' => []];
    }

    private function generosUnicos(array $generosPorLibro, int $max): array
    {
        $conteo = [];
        foreach ($generosPorLibro as $gs) {
            foreach ($gs as $g) $conteo[$g] = ($conteo[$g] ?? 0) + 1;
        }
        arsort($conteo);
        return array_slice(array_keys($conteo), 0, $max);
    }

    private function qSubject(string $g): string
    {
        return 'subject:' . (preg_match('/\s/', $g) ? '"' . $g . '"' : $g);
    }

    /*  FUENTE 1: Google Books  */
    private function candidatosGoogle(array $base, array $autores, array $generosDB): array
    {
        $cand   = $this->candidatoVacio();
        $ronda1 = [];
        foreach ($base as $i => $libro) {
            if ($autores[$i] !== '') {
                $ronda1["a$i"] = ['url' => $this->urlGoogle('inauthor:"' . $autores[$i] . '"', true, 40)];
            }
            $ronda1["t$i"] = ['url' => $this->urlGoogle(
                'intitle:"' . $this->sinParentesis($libro['titulo']) . '"'
                . ($autores[$i] !== '' ? ' inauthor:"' . $autores[$i] . '"' : ''),
                false, 5
            )];
        }
        $res1 = $this->pedir('google', $ronda1, 'items');

        foreach ($base as $i => $libro) {
            $cand['autor'][$i] = $this->normGoogle($res1["a$i"] ?? []);

            // Géneros: los de la BD y los que informa Google
            $cadenas = [(string)($libro['categorias'] ?? '')];
            foreach ($res1["t$i"] ?? [] as $it) {
                foreach ($it['volumeInfo']['categories'] ?? [] as $c) $cadenas[] = $c;
            }
            $cand['generos'][$i] = $this->generosDe(array_filter($cadenas), 2);
        }

        $generos = $this->generosUnicos($cand['generos'], 6);
        $ronda2  = [];
        foreach ($generos as $g) {
            $ronda2["g:$g"] = ['url' => $this->urlGoogle($this->qSubject($g), true, 40), 'reintentarVacio' => true];
        }
        $ronda2['ficcion']       = ['url' => $this->urlGoogle('subject:fiction', true, 40),  'reintentarVacio' => true];
        $ronda2['ficcion_otros'] = ['url' => $this->urlGoogle('subject:fiction', false, 40), 'reintentarVacio' => true];
        $res2 = $this->pedir('google', $ronda2, 'items');

        foreach ($generos as $g) {
            $cand['porGenero'][$g] = $this->normGoogle($res2["g:$g"] ?? []);
        }
        $cand['relleno']      = array_merge(...array_values($cand['porGenero']), ...[$this->normGoogle($res2['ficcion'] ?? [])]);
        $cand['rellenoOtros'] = $this->normGoogle($res2['ficcion_otros'] ?? []);
        return $cand;
    }

    private function urlGoogle(string $q, bool $restringirIdioma, int $max): string
    {
        return 'https://www.googleapis.com/books/v1/volumes?' . http_build_query(array_filter([
            'q'            => $q,
            'printType'    => 'books',
            'maxResults'   => $max,
            'langRestrict' => ($restringirIdioma && $this->langGb !== '') ? $this->langGb : null,
            'key'          => $this->apiKey !== '' ? $this->apiKey : null,
        ], fn($v) => $v !== null));
    }

    private function normGoogle(array $items): array
    {
        return array_values(array_map(fn($it) => $this->normalizarGoogle($it), $items));
    }

    private function normalizarGoogle(array $item): array
    {
        $vi  = $item['volumeInfo'] ?? [];
        $img = $vi['imageLinks']['thumbnail'] ?? $vi['imageLinks']['smallThumbnail'] ?? '';
        if ($img !== '') $img = str_replace(['http://', '&edge=curl'], ['https://', ''], $img);

        return [
            'id'      => (string)($item['id'] ?? ''),
            'titulo'  => (string)($vi['title'] ?? ''),
            'autor'   => (string)($vi['authors'][0] ?? 'Autor desconocido'),
            'autores' => $vi['authors'] ?? [],
            'portada' => $img,
            'paginas' => (int)($vi['pageCount'] ?? 0),
            'idioma'  => (string)($vi['language'] ?? ''),
            'conDesc' => !empty($vi['description']),
            'url'     => null,
            'fuente'  => 'google',
        ];
    }

    /*  FUENTE 2: Open Library  */
    private function candidatosOpenLibrary(array $base, array $autores, array $generosDB): array
    {
        $cand   = $this->candidatoVacio();
        $ronda1 = [];
        foreach ($base as $i => $libro) {
            if ($autores[$i] !== '') {
                $ronda1["a$i"] = ['url' => $this->urlOL('author:"' . $autores[$i] . '"', 40)];
            }
            // Solo hace falta consultar el libro si no conocemos su género
            if (empty($generosDB[$i])) {
                $ronda1["t$i"] = ['url' => $this->urlOL(
                    'title:"' . $this->sinParentesis($libro['titulo']) . '"'
                    . ($autores[$i] !== '' ? ' author:"' . $autores[$i] . '"' : ''),
                    3, false
                )];
            }
        }
        $res1 = $this->pedir('openlibrary', $ronda1, 'docs');

        foreach ($base as $i => $libro) {
            $cand['autor'][$i] = $this->normOL($res1["a$i"] ?? []);

            if (!empty($generosDB[$i])) {
                $cand['generos'][$i] = array_slice($generosDB[$i], 0, 2);
            } else {
                $subs = [];
                foreach ($res1["t$i"] ?? [] as $d) {
                    foreach ($d['subject'] ?? [] as $s) $subs[] = $s;
                }
                $cand['generos'][$i] = $this->generosOL($subs, 2);
            }
        }

        $generos = $this->generosUnicos($cand['generos'], 4);
        $ronda2  = [];
        foreach ($generos as $g) {
            $ronda2["g:$g"] = ['url' => $this->urlOL($this->qSubject($g), 40, true, 'rating')];
        }
        $ronda2['ficcion']       = ['url' => $this->urlOL('subject:fiction', 40, true,  'rating')];
        $ronda2['ficcion_otros'] = ['url' => $this->urlOL('subject:fiction', 40, false, 'rating')];
        $res2 = $this->pedir('openlibrary', $ronda2, 'docs');

        foreach ($generos as $g) {
            $cand['porGenero'][$g] = $this->normOL($res2["g:$g"] ?? []);
        }
        $cand['relleno']      = array_merge(...array_values($cand['porGenero']), ...[$this->normOL($res2['ficcion'] ?? [])]);
        $cand['rellenoOtros'] = $this->normOL($res2['ficcion_otros'] ?? []);
        return $cand;
    }

    private function urlOL(string $q, int $limit = 40, bool $idioma = true, ?string $sort = null): string
    {
        if ($idioma && $this->langOl !== '') $q .= ' language:' . $this->langOl;
        return 'https://openlibrary.org/search.json?' . http_build_query(array_filter([
            'q'      => $q,
            'limit'  => $limit,
            'fields' => 'key,title,author_name,cover_i,subject,number_of_pages_median,editions,editions.title,editions.cover_i',
            'lang'   => $idioma && $this->langGb !== '' ? $this->langGb : null,   
            'sort'   => $sort,
        ], fn($v) => $v !== null));
    }

    private function normOL(array $docs): array
    {
        return array_values(array_map(fn($d) => $this->normalizarOL($d), $docs));
    }

    private function normalizarOL(array $d): array
    {
        $titulo  = (string)($d['title'] ?? '');
        $autores = is_array($d['author_name'] ?? null) ? $d['author_name'] : [];
        $autor   = (string)($autores[0] ?? 'Autor desconocido');
        $coverId = $d['cover_i'] ?? null;

        // Si hay idioma elegido, usar el título y la portada de la edición que coincide
        if ($this->langOl !== '') {
            $ed = $d['editions']['docs'][0] ?? null;
            if (is_array($ed)) {
                if (!empty($ed['title'])) $titulo = (string)$ed['title'];
                if (!empty($ed['cover_i'])) $coverId = $ed['cover_i'];
            }
        }

        return [
            'id'      => basename((string)($d['key'] ?? '')),   
            'titulo'  => $titulo,
            'autor'   => $autor,
            'autores' => $autores,
            'portada' => !empty($coverId) ? 'https://covers.openlibrary.org/b/id/' . (int)$coverId . '-M.jpg' : '',
            'paginas' => (int)($d['number_of_pages_median'] ?? 0),
            'idioma'  => '',        
            'conDesc' => true,      
            'url'     => 'buscar.php?q=' . urlencode(trim($titulo . ' ' . $autor)),
            'fuente'  => 'openlibrary',
        ];
    }

    /** Géneros de una lista de "subjects" de Open Library, descartando etiquetas de ruido */
    private function generosOL(array $subjects, int $max = 2): array
    {
        $ruido = ['accessible', 'protected', 'daisy', 'large type', 'lending', 'overdrive', 'in library',
                  'reading level', 'open library', 'nyt:', 'new york times', 'long now'];
        $cuenta = [];
        foreach ($subjects as $s) {
            $s = trim((string)$s);
            $l = mb_strtolower($s);
            if ($s === '' || mb_strlen($s) > 30) continue;
            foreach ($ruido as $r) {
                if (str_contains($l, $r)) continue 2;
            }
            $cuenta[$s] = ($cuenta[$s] ?? 0) + 1;
        }
        arsort($cuenta);
        return array_slice(array_keys($cuenta), 0, $max);
    }

    /*  FUENTE 3: libros de otros usuarios de la app  */
    private function candidatosLocal(array $base, array $autores, array $generosDB): array
    {
        $cand = $this->candidatoVacio();

        foreach ($base as $i => $libro) {
            $cand['autor'][$i]   = $autores[$i] !== '' ? $this->localBuscar('autores', $autores[$i]) : [];
            $cand['generos'][$i] = array_slice($generosDB[$i], 0, 2);
            foreach ($cand['generos'][$i] as $g) {
                if (!isset($cand['porGenero'][$g])) {
                    $cand['porGenero'][$g] = $this->localBuscar('categorias', $g);
                }
            }
        }
        // Los más populares entre los usuarios de la app
        $cand['relleno'] = $this->localBuscar(null, '');
        $this->log['local'] = 'candidatos de otros usuarios: ' . count($cand['relleno']) . ' (populares)';
        return $cand;
    }

    private function localBuscar(?string $columna, string $valor, int $limite = 30): array
    {
        try {
            $sql = "SELECT libro_id, MAX(titulo) AS titulo, MAX(autores) AS autores,
                           MAX(portada) AS portada, COUNT(*) AS veces
                    FROM listas_lectura
                    WHERE usuario_id <> ?
                      AND libro_id IS NOT NULL AND libro_id <> ''
                      AND portada IS NOT NULL AND portada <> ''";
            $params = [$this->usuarioId];
            if ($columna !== null) {
                $sql .= " AND $columna LIKE ?";
                $params[] = '%' . addcslashes($valor, '%_\\') . '%';
            }
            $sql .= " GROUP BY libro_id ORDER BY veces DESC, MAX(id) DESC LIMIT " . (int)$limite;

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $salida = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $autores = array_values(array_filter(array_map('trim', explode(',', (string)$r['autores']))));
                $salida[] = [
                    'id'      => (string)$r['libro_id'],
                    'titulo'  => (string)$r['titulo'],
                    'autor'   => $autores[0] ?? 'Autor desconocido',
                    'autores' => $autores,
                    'portada' => (string)$r['portada'],
                    'paginas' => 0,
                    'idioma'  => '',
                    'conDesc' => true,
                    'url'     => null,
                    'fuente'  => 'local',
                ];
            }
            return $salida;
        } catch (Exception $e) {
            return [];
        }
    }

    /*  Peticiones HTTP con reintentos  */
    /**
     * Lanza peticiones en paralelo a una fuente y reintenta las que fallen
     * por causas temporales. Si todas fallan con error, marca la fuente como
     * caída y las siguientes llamadas se omiten (así no se pierde tiempo).
     *
     * @param array  $reqs         [clave => ['url' => ..., 'reintentarVacio' => bool]]
     * @param string $claveItems   'items' (Google) o 'docs' (Open Library)
     * @return array               [clave => lista de resultados]
     */
    private function pedir(string $fuente, array $reqs, string $claveItems): array
    {
        $salida = [];
        if (!empty($this->caida[$fuente])) {
            foreach ($reqs as $k => $_) {
                $salida[$k] = [];
                $this->log["$fuente:$k"] = 'omitida (fuente caída)';
            }
            return $salida;
        }

        $pendientes = $reqs;
        $conError   = [];

        for ($intento = 1; $intento <= self::MAX_INTENTOS && !empty($pendientes); $intento++) {
            if ($intento > 1) usleep(700000);   // pausa antes de reintentar
            $fallidas = [];

            foreach ($this->lote($pendientes) as $k => $r) {
                $items = $r['json'][$claveItems] ?? [];
                if (!is_array($items)) $items = [];

                $codigo   = $r['code'];
                $error    = $codigo >= 400 || $r['errno'] !== 0 || $codigo === 0;
                $reintent = $codigo >= 500 || $codigo === 429 || $r['errno'] !== 0 || $codigo === 0
                         || (empty($items) && !empty($reqs[$k]['reintentarVacio']));

                $conError[$k] = $error;
                $salida[$k]   = $items;
                $this->log["$fuente:$k"] = "intento $intento · HTTP $codigo · " . count($items) . ' resultados'
                    . (isset($r['json']['error']['message']) ? ' · ' . $r['json']['error']['message'] : '')
                    . ($r['err'] !== '' ? ' · curl: ' . $r['err'] : '');

                if ($reintent) $fallidas[$k] = $pendientes[$k];
            }
            $pendientes = $fallidas;
        }

        if (!empty($reqs) && count(array_filter($conError)) === count($reqs)) {
            $this->caida[$fuente] = true;
        }
        return $salida;
    }

    /** Una tanda de peticiones en paralelo, sin reintentos */
    private function lote(array $reqs): array
    {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($reqs as $k => $r) {
            $ch = curl_init($r['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_SSL_VERIFYPEER => false,  
                CURLOPT_USERAGENT      => self::USER_AGENT,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$k] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $status === CURLM_OK);

        $salida = [];
        foreach ($handles as $k => $ch) {
            $json = json_decode((string)curl_multi_getcontent($ch), true);
            $salida[$k] = [
                'code'  => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'json'  => is_array($json) ? $json : [],
                'errno' => curl_errno($ch),
                'err'   => curl_error($ch),
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $salida;
    }

    /*  Helpers de texto  */
    private function limpiarTexto(string $s): string
    {
        $s = strtr(mb_strtolower($s, 'UTF-8'), [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
            'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
        ]);
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/', ' ', $s)));
    }

    private function sinParentesis(string $t): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $t)));
    }

    private function tituloBase(string $t): string
    {
        $t = $this->sinParentesis($t);
        return $this->limpiarTexto(preg_split('/\s*[:\x{2013}\x{2014}]\s*|\s+-\s+/u', $t)[0] ?? $t);
    }

    private function claveAutor(string $n): string
    {
        $t = array_values(array_filter(explode(' ', $this->limpiarTexto($n)), fn($w) => strlen($w) > 1));
        return count($t) >= 2 ? $t[0] . ' ' . end($t) : implode(' ', $t);
    }

    private function generosDe(array $cadenas, int $max = 2): array
    {
        $cuenta = [];
        foreach ($cadenas as $c) {
            foreach (preg_split('/[\/,]/', (string)$c) as $g) {
                $g = trim($g);
                if ($g === '' || strcasecmp($g, 'General') === 0) continue;
                $cuenta[$g] = ($cuenta[$g] ?? 0) + 1;
            }
        }
        arsort($cuenta);
        $genericos = ['fiction', 'juvenile fiction', 'literary collections', 'young adult fiction'];
        $buenos = array_filter(array_keys($cuenta), fn($g) => !in_array(mb_strtolower($g), $genericos, true));
        $lista  = array_values($buenos) ?: array_keys($cuenta);
        return array_slice($lista, 0, $max);
    }
}