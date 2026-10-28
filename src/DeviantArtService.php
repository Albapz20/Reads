<?php
class DeviantArtService
{
    public string $ultimoError = '';

    public array $log = [];

    public static function disponible(): bool
    {
        return (string)config('deviantart_id', '') !== '' && (string)config('deviantart_secret', '') !== '';
    }

    /**
     * @return array<int, array{ref:string, imagen:string, artista:string, enlace:string, nota:string}>|null
     *         null si la API falla del todo; [] si no hay obras fiables.
     */
    public function buscarFanarts(string $titulo, string $autor = '', string $isbn = '', string $descripcion = '', int $max = 8): ?array
    {
        $inicio = microtime(true);

        $token = $this->token();
        if ($token === null) return null;

        $original = $isbn !== '' ? $this->tituloOriginal($isbn) : '';
        $frasesTitulo = array_values(array_unique(array_filter([
            self::normalizar(self::tituloBase($original)),
            self::normalizar(self::tituloBase($titulo)),
        ])));
        $etiquetasTitulo = array_values(array_unique(array_filter(array_map(fn($f) => str_replace(' ', '', $f), $frasesTitulo))));

        $frasesAutor    = $autor !== '' ? self::normalizar($autor) : '';
        $etiquetaAutor  = str_replace(' ', '', $frasesAutor);

        $serie          = self::serieDeTitulo($titulo);
        $fraseSerie     = $serie !== '' ? self::normalizar($serie) : '';
        $etiquetaSerie  = str_replace(' ', '', $fraseSerie);
        foreach ($frasesTitulo as $f) {   // la saga "The Wolf King" en el libro "The Wolf King" es la misma prueba, no dos
            if ($fraseSerie !== '' && ($fraseSerie === $f || str_contains($f, $fraseSerie) || str_contains($fraseSerie, $f))) {
                $fraseSerie = '';
                $etiquetaSerie = '';
                break;
            }
        }

        $personajes     = self::nombresDe($descripcion);
        $exigir         = [];

        $ctx = compact('frasesTitulo', 'etiquetasTitulo', 'frasesAutor', 'etiquetaAutor', 'fraseSerie', 'etiquetaSerie', 'personajes', 'exigir');

        $aceptados = [];
        $vistos = [];
        $huboRespuesta = false;

        $consultas = [];
        foreach ($etiquetasTitulo as $i => $et) {
            $consultas[] = [$et, $i === 0 && $original !== '' ? 'título original' : 'título'];
        }
        if ($etiquetaAutor !== '') $consultas[] = [$etiquetaAutor, 'autor'];

        foreach ($consultas as [$tag, $origen]) {
            if (count($aceptados) >= $max || (microtime(true) - $inicio) > 14) break;
            $this->probarEtiqueta($tag, $origen, $token, $ctx, $aceptados, $vistos, $huboRespuesta);
        }


        if (count($aceptados) < 4 && $original === '' && $autor !== '' && $personajes) {
            foreach ($this->titulosDelAutor($autor) as $tituloAutor) {
                if (count($aceptados) >= $max || (microtime(true) - $inicio) > 14) break;

                $et = self::etiqueta($tituloAutor);
                if (in_array($et, $ctx['etiquetasTitulo'], true)) continue;

                $ctx2 = $ctx;
                $ctx2['etiquetasTitulo'][] = $et;
                $ctx2['frasesTitulo'][]    = self::normalizar($tituloAutor);
                $ctx2['exigir']            = ['personaje'];

                $this->probarEtiqueta($et, 'otra obra del autor: ' . $tituloAutor, $token, $ctx2, $aceptados, $vistos, $huboRespuesta);
            }
        }

        if (!$huboRespuesta) return null; 

        uasort($aceptados, fn($a, $b) => $b['puntos'] <=> $a['puntos']);
        return array_slice(array_values($aceptados), 0, $max);
    }

    private function probarEtiqueta(string $tag, string $origen, string $token, array $ctx, array &$aceptados, array &$vistos, bool &$huboRespuesta): void
    {
        if (mb_strlen($tag) < 6 || mb_strlen($tag) > 40) return;

        $json = $this->buscarEtiqueta($tag, $token);
        if ($json === null) {
            $this->log[] = "«{$tag}» ({$origen}): error";
            return;
        }
        $huboRespuesta = true;

        $candidatas = [];
        foreach ($json['results'] ?? [] as $r) {
            if (!empty($r['is_deleted']) || !empty($r['is_mature'])) continue;

            $imagen = (string)($r['preview']['src'] ?? ($r['content']['src'] ?? ''));
            $enlace = (string)($r['url'] ?? '');
            $id     = (string)($r['deviationid'] ?? '');
            if ($id === '' || isset($vistos[$id])) continue;
            if (!str_starts_with($imagen, 'https://') || !str_starts_with($enlace, 'https://') || strlen($imagen) > 8000) continue;

            $vistos[$id] = true;
            $candidatas[$id] = [
                'ref'     => $id,
                'imagen'  => $imagen,
                'artista' => (string)($r['author']['username'] ?? ''),
                'enlace'  => mb_substr($enlace, 0, 600),
                'nota'    => mb_substr(trim((string)($r['title'] ?? '')), 0, 160),
                'fanart'  => str_starts_with((string)($r['category_path'] ?? ''), 'fanart'),
            ];
        }
        if (!$candidatas) {
            $this->log[] = "«{$tag}» ({$origen}): 0 obras";
            return;
        }

        $meta = $this->metadatos(array_keys($candidatas), $token);
        if ($meta === null) {
            $this->log[] = "«{$tag}» ({$origen}): " . count($candidatas) . " obras, pero NO se pudieron comprobar (" . $this->ultimoError . ") → descartadas";
            return;
        }

        $buenas = 0;
        $porque = [];
        foreach ($candidatas as $id => $item) {
            if (!isset($meta[$id])) continue;
            $tipos = $this->coincidencias($meta[$id], $ctx);
            $faltan = array_diff($ctx['exigir'] ?? [], array_keys($tipos));
            if (self::puntuar($tipos) >= 3 && !$faltan) {
                $item['puntos'] = self::puntuar($tipos) * 2 + ($item['fanart'] ? 1 : 0);
                $aceptados[$id] = $item;
                $buenas++;

                $etq = [];
                foreach ($tipos as $k => $val) $etq[] = $k . ($k === 'personaje' ? '=' . implode(',', (array)$val) : '');
                $clave = implode('+', $etq);
                $porque[$clave] = ($porque[$clave] ?? 0) + 1;
            }
        }
        $detalle = [];
        foreach ($porque as $k => $n) $detalle[] = "$n por $k";
        $this->log[] = "«{$tag}» ({$origen}): " . count($candidatas) . " obras, $buenas son de este libro" . ($detalle ? ' [' . implode('; ', $detalle) . ']' : '');
    }

    private function titulosDelAutor(string $autor, int $max = 3): array
    {
        $j = $this->get('https://openlibrary.org/search.json?' . http_build_query([
            'author' => $autor, 'limit' => 20, 'sort' => 'editions', 'fields' => 'title,edition_count,language',
        ]), null, false);

        $titulos = [];
        foreach ($j['docs'] ?? [] as $d) {
            $idiomas = $d['language'] ?? [];
            if ($idiomas && !in_array('eng', $idiomas, true)) continue;

            $t = self::tituloBase((string)($d['title'] ?? ''));
            if ($t === '' || mb_strlen(self::etiqueta($t)) < 6) continue;

            $titulos[mb_strtolower($t)] = $t;
            if (count($titulos) >= $max) break;
        }
        return array_values($titulos);
    }

    private function coincidencias(array $m, array $c): array
    {
        $tags = [];
        foreach ($m['tags'] ?? [] as $t) {
            $tags[] = strtolower((string)($t['tag_name'] ?? ''));
        }
        $texto = ' ' . self::normalizar((string)($m['title'] ?? '') . ' ' . strip_tags((string)($m['description'] ?? ''))) . ' ';
        $dentro = fn(string $frase) => $frase !== '' && str_contains($texto, ' ' . $frase . ' ');

        $tipos = [];

        foreach ($c['etiquetasTitulo'] as $et) {
            if (in_array($et, $tags, true)) $tipos['titulo'] = true;
        }
        foreach ($c['frasesTitulo'] as $fr) {
            if (strlen($fr) >= 8 && $dentro($fr)) $tipos['titulo'] = true;
        }

        if ($c['etiquetaAutor'] !== '' && (in_array($c['etiquetaAutor'], $tags, true) || $dentro($c['frasesAutor']))) {
            $tipos['autor'] = true;
        }

        if (strlen($c['etiquetaSerie']) >= 5 && (in_array($c['etiquetaSerie'], $tags, true) || $dentro($c['fraseSerie']))) {
            $tipos['serie'] = true;
        }

        $nombres = [];
        foreach ($c['personajes'] as $p) {
            $enEtiquetas = false;
            foreach ($tags as $t) {
                if (str_contains($t, $p)) { $enEtiquetas = true; break; }
            }
            if ($dentro($p) || $enEtiquetas) $nombres[] = $p;
        }
        if ($nombres) $tipos['personaje'] = $nombres;

        return $tipos;
    }

    public static function puntuar(array $tipos): int
    {
        return (isset($tipos['titulo']) ? 1 : 0)
             + (isset($tipos['autor']) ? 2 : 0)
             + (isset($tipos['serie']) ? 2 : 0)
             + min(3, count((array)($tipos['personaje'] ?? [])));
    }

    public static function tituloBase(string $titulo): string
    {
        $t = preg_replace('/\s*[\(\[\{].*?[\)\]\}]\s*/u', ' ', $titulo);
        $t = trim(explode(' - ', explode(':', $t)[0])[0]);
        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    public static function serieDeTitulo(string $titulo): string
    {
        if (!preg_match_all('/\(([^()]+)\)/u', $titulo, $m)) return '';

        foreach ($m[1] as $par) {
            if (preg_match('/edition|edici[oó]n|spanish|english/iu', $par)) continue;

            $s = trim(preg_split('/[,#]/u', $par)[0]);
            $s = trim(preg_replace('/\s*\d+$/u', '', $s));   // quitar el número del tomo
            if (mb_strlen($s) >= 4 && !preg_match('/^\d+$/', $s)) return $s;
        }
        return '';
    }

    public static function normalizar(string $texto): string
    {
        $t = mb_strtolower($texto, 'UTF-8');
        $t = strtr($t, ['á'=>'a','à'=>'a','é'=>'e','è'=>'e','í'=>'i','ì'=>'i','ó'=>'o','ò'=>'o','ú'=>'u','ù'=>'u','ü'=>'u','ñ'=>'n','ç'=>'c']);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $t));
    }

    public static function nombresDe(string $descripcion, int $max = 12): array
    {
        $texto = html_entity_decode(strip_tags($descripcion), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cuenta = [];

        foreach (preg_split('/(?<=[.!?…])\s+|\n+/u', $texto) as $frase) {
            $palabras = preg_split('/\s+/u', trim($frase));
            array_shift($palabras);   // la primera palabra de una frase siempre va en mayúscula

            foreach ($palabras as $p) {
                $p = trim($p, " \t\n\r.,;:!?¡¿\"'«»“”()[]—–-");
                if (mb_strlen($p) < 6 || !preg_match('/^\p{Lu}\p{L}+$/u', $p)) continue;
                $k = self::normalizar($p);
                if (strlen($k) >= 6) $cuenta[$k] = ($cuenta[$k] ?? 0) + 1;
            }
        }
        arsort($cuenta);
        return array_slice(array_keys($cuenta), 0, $max);
    }

    private function tituloOriginal(string $isbn): string
    {
        $j = $this->get('https://openlibrary.org/isbn/' . urlencode($isbn) . '.json', null, false);
        $orig = $j['translation_of'] ?? '';
        return is_string($orig) ? trim($orig) : '';
    }

    private function metadatos(array $ids, string $token): ?array
    {
        $ids = array_slice($ids, 0, 24);
        $url = 'https://www.deviantart.com/api/v1/oauth2/deviation/metadata?'
             . implode('&', array_map(fn($i) => 'deviationids[]=' . rawurlencode((string)$i), $ids))
             . '&ext_submission=false&ext_camera=false&ext_stats=false&ext_collection=false&with_session=false';

        $j = $this->get($url, $token);
        if ($j === null) return null;

        $salida = [];
        foreach ($j['metadata'] ?? [] as $m) {
            if (!empty($m['deviationid'])) $salida[(string)$m['deviationid']] = $m;
        }
        return $salida;
    }


    public static function etiqueta(string $texto): string
    {
        return str_replace(' ', '', self::normalizar($texto));
    }

    private function buscarEtiqueta(string $tag, string $token): ?array
    {
        $base = [
            'tag'            => $tag,
            'limit'          => 24,
            'mature_content' => 'false',
        ];
  
        $json = $this->get('https://www.deviantart.com/api/v1/oauth2/browse/tags?' . http_build_query($base + ['mode' => 'popular']), $token);
        if ($json === null) {
            $json = $this->get('https://www.deviantart.com/api/v1/oauth2/browse/tags?' . http_build_query($base), $token);
        }
        return $json;
    }

    private function token(): ?string
    {
     
        foreach (['browse', ''] as $scope) {
            $campos = [
                'grant_type'    => 'client_credentials',
                'client_id'     => (string)config('deviantart_id', ''),
                'client_secret' => (string)config('deviantart_secret', ''),
            ];
            if ($scope !== '') $campos['scope'] = $scope;

            $ch = curl_init('https://www.deviantart.com/oauth2/token');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_SSL_VERIFYPEER => !(bool)config('curl_sin_verificar', false),
                CURLOPT_POSTFIELDS     => http_build_query($campos),
            ]);
            $resp = curl_exec($ch);
            $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errorCurl = curl_error($ch);
            curl_close($ch);

            $j = $resp ? json_decode($resp, true) : null;
            if ($codigo === 200 && !empty($j['access_token'])) {
                return (string)$j['access_token'];
            }
            $this->ultimoError = "token (scope '" . $scope . "'): HTTP $codigo " . ($errorCurl !== '' ? $errorCurl : mb_substr((string)$resp, 0, 200));
        }
        return null;
    }

    private function get(string $url, ?string $token, bool $registrarError = true): ?array
    {
        $cabeceras = $token !== null ? ['Authorization: Bearer ' . $token] : [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_USERAGENT      => 'ReadsApp/1.0 (' . (defined('APP_CONTACTO') ? APP_CONTACTO : 'contacto') . ')',
            CURLOPT_SSL_VERIFYPEER => !(bool)config('curl_sin_verificar', false),
            CURLOPT_HTTPHEADER     => $cabeceras,
        ]);
        $resp = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($ch);
        curl_close($ch);

        $j = $resp ? json_decode($resp, true) : null;
        if ($codigo !== 200 || !is_array($j)) {
            if ($registrarError) {
                $this->ultimoError = "HTTP $codigo " . ($errorCurl !== '' ? $errorCurl : mb_substr((string)$resp, 0, 200));
            }
            return null;
        }
        return $j;
    }
}