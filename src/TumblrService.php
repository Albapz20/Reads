<?php
require_once __DIR__ . '/DeviantArtService.php';   

class TumblrService
{
    public string $ultimoError = '';
    public array $log = [];
    private const ETIQUETAS_ARTE = [
        'fanart', 'fan art', 'fanarts', 'art', 'artwork', 'illustration', 'ilustracion', 'digital art',
        'digital painting', 'drawing', 'sketch', 'my art', 'painting', 'concept art', 'character design', 'artists on tumblr',
    ];
    private const ETIQUETAS_PROHIBIDAS = [
        'nsfw', 'porn', 'nude', 'naked', 'explicit', 'xxx', 'hentai', 'r18', '18', 'sex', 'gore', 'erotica', 'not safe for work',
    ];

    public static function disponible(): bool
    {
        return (string)config('tumblr_key', '') !== '';
    }

    /**
     * @return array<int, array{ref:string, imagen:string, artista:string, enlace:string, nota:string}>|null
     *         null si la API falla del todo; [] si no hay publicaciones fiables.
     */
    public function buscarFanarts(string $titulo, string $autor = '', string $isbn = '', string $descripcion = '', int $max = 8): ?array
    {
        $inicio = microtime(true);
        $N = 'DeviantArtService';   // funciones de texto compartidas
        $autor = trim(preg_replace('/\s+/u', ' ', $autor));   // quitar espacios repetidos

        //  Lo que sabemos del libro 
        $original = $isbn !== '' ? $this->tituloOriginal($isbn) : '';
        $tituloEn = $N::tituloBase($original);
        $tituloEs = $N::tituloBase($titulo);

        $frasesTitulo = array_values(array_unique(array_filter([$N::normalizar($tituloEn), $N::normalizar($tituloEs)])));
        $frasesAutor  = $autor !== '' ? $N::normalizar($autor) : '';
        $serie        = $N::serieDeTitulo($titulo);
        $fraseSerie   = $serie !== '' ? $N::normalizar($serie) : '';
        foreach ($frasesTitulo as $f) {   // si la saga se llama igual que el libro, es la misma prueba
            if ($fraseSerie !== '' && ($fraseSerie === $f || str_contains($f, $fraseSerie) || str_contains($fraseSerie, $f))) {
                $fraseSerie = '';
                break;
            }
        }
        $personajes   = $N::nombresDe($descripcion);

        $consultas = [];
        if ($tituloEn !== '') {
            $consultas[mb_strtolower($tituloEn)]              = 'título original';
            $consultas[mb_strtolower($tituloEn) . ' fanart']  = 'título original + fanart';
        }
        if ($tituloEs !== '') {
            $consultas[mb_strtolower($tituloEs) . ' fanart']  = 'título + fanart';
            $consultas[mb_strtolower($tituloEs)]              = 'título';
        }
        if ($autor !== '') {
            $consultas[mb_strtolower($autor)] = 'autor';
        }

        $aceptados = [];
        $vistos = [];
        $huboRespuesta = false;

        foreach (array_slice($consultas, 0, 5, true) as $tag => $origen) {
            if (count($aceptados) >= $max || (microtime(true) - $inicio) > 12) break;
            if (mb_strlen($tag) < 5) continue;

            $posts = $this->tagged($tag);
            if ($posts === null) {
                $this->log[] = "«{$tag}» ({$origen}): error";
                continue;
            }
            $huboRespuesta = true;

            $buenas = 0;
            $porque = [];
            $desc = ['sin imagen' => 0, 'contenido adulto' => 0, 'no es arte' => 0, 'pruebas insuficientes' => 0];
            foreach ($posts as $p) {
                $id = (string)($p['id_string'] ?? $p['id'] ?? '');
                if ($id === '' || isset($vistos[$id])) continue;
                $vistos[$id] = true;

                $imagen = $this->imagenDe($p);
                $enlace = (string)($p['post_url'] ?? '');
                if (!str_starts_with($imagen, 'https://') || !str_starts_with($enlace, 'https://') || strlen($imagen) > 8000) { $desc['sin imagen']++; continue; }
                if (!empty($p['is_nsfw'])) { $desc['contenido adulto']++; continue; }

                $tags = [];
                foreach ($p['tags'] ?? [] as $t) $tags[] = $N::normalizar((string)$t);

                if (array_intersect($tags, self::ETIQUETAS_PROHIBIDAS)) { $desc['contenido adulto']++; continue; }
                if (!array_intersect($tags, self::ETIQUETAS_ARTE)) { $desc['no es arte']++; continue; }

                $texto = ' ' . $N::normalizar(
                    strip_tags((string)($p['summary'] ?? '') . ' ' . (string)($p['caption'] ?? '')) . ' ' . implode(' ', $p['tags'] ?? [])
                ) . ' ';
                $dentro = fn(string $f) => $f !== '' && str_contains($texto, ' ' . $f . ' ');

                $tipos = [];
                foreach ($frasesTitulo as $f) {
                    if (in_array($f, $tags, true) || (strlen($f) >= 8 && $dentro($f))) $tipos['titulo'] = true;
                }
                if ($frasesAutor !== '' && (in_array($frasesAutor, $tags, true) || $dentro($frasesAutor))) $tipos['autor'] = true;
                if (strlen($fraseSerie) >= 5 && (in_array($fraseSerie, $tags, true) || $dentro($fraseSerie))) $tipos['serie'] = true;
                $nombres = [];
                foreach ($personajes as $pj) {
                    if ($dentro($pj)) $nombres[] = $pj;
                }
                if ($nombres) $tipos['personaje'] = $nombres;

                if ($N::puntuar($tipos) < 3) { $desc['pruebas insuficientes']++; continue; }

                $etq = [];
                foreach ($tipos as $k => $val) $etq[] = $k . ($k === 'personaje' ? '=' . implode(',', (array)$val) : '');
                $clave = implode('+', $etq);
                $porque[$clave] = ($porque[$clave] ?? 0) + 1;

                $notas = (int)($p['note_count'] ?? 0);
                $aceptados[$id] = [
                    'ref'     => 'tm' . $id,
                    'imagen'  => $imagen,
                    'artista' => (string)($p['blog_name'] ?? ''),
                    'enlace'  => mb_substr($enlace, 0, 600),
                    'nota'    => '',
                    'puntos'  => $N::puntuar($tipos) * 2 + (int)floor(log10($notas + 1)),
                ];
                $buenas++;
            }
            $detalle = [];
            foreach ($porque as $k => $n) $detalle[] = "$n por $k";
            $motivos = [];
            foreach ($desc as $motivo => $n) { if ($n > 0) $motivos[] = "$n $motivo"; }
            $this->log[] = "«{$tag}» ({$origen}): " . count($posts) . " publicaciones, $buenas son de este libro" . ($detalle ? ' [' . implode('; ', $detalle) . ']' : '')
                         . ($motivos ? ' [descartadas: ' . implode(', ', $motivos) . ']' : '');
        }

        if (!$huboRespuesta) return null;

        uasort($aceptados, fn($a, $b) => $b['puntos'] <=> $a['puntos']);
        return array_slice(array_values($aceptados), 0, $max);
    }

    /** Publicaciones recientes con una etiqueta */
    private function tagged(string $tag): ?array
    {
        $url = 'https://api.tumblr.com/v2/tagged?' . http_build_query([
            'tag'     => $tag,
            'limit'   => 20,
            'filter'  => 'text',
            'api_key' => (string)config('tumblr_key', ''),
        ]);

        [$codigo, $resp, $errorCurl] = $this->http($url);
        $j = $resp ? json_decode($resp, true) : null;

        if ($codigo !== 200 || !is_array($j)) {
            $this->ultimoError = "HTTP $codigo " . ($errorCurl !== '' ? $errorCurl : mb_substr((string)$resp, 0, 200));
            return null;
        }

        $lista = $j['response'] ?? [];
        if (isset($lista['posts'])) $lista = $lista['posts'];
        return is_array($lista) ? $lista : [];
    }

    private function imagenDe(array $p): string
    {
        $foto = $p['photos'][0] ?? null;
        if (is_array($foto)) {
            $tamanos = $foto['alt_sizes'] ?? [];
            usort($tamanos, fn($a, $b) => ($a['width'] ?? 0) <=> ($b['width'] ?? 0));
            foreach ($tamanos as $t) {
                if (($t['width'] ?? 0) >= 400 && !empty($t['url'])) return (string)$t['url'];
            }
            return (string)($foto['original_size']['url'] ?? '');
        }

        foreach ($p['content'] ?? [] as $bloque) {   // formato de bloques (NPF)
            if (($bloque['type'] ?? '') !== 'image') continue;
            $medios = $bloque['media'] ?? [];
            usort($medios, fn($a, $b) => ($a['width'] ?? 0) <=> ($b['width'] ?? 0));
            foreach ($medios as $m) {
                if (($m['width'] ?? 0) >= 400 && !empty($m['url'])) return (string)$m['url'];
            }
            return (string)($medios[0]['url'] ?? '');
        }
        return '';
    }

    private function tituloOriginal(string $isbn): string
    {
        [$codigo, $resp] = $this->http('https://openlibrary.org/isbn/' . urlencode($isbn) . '.json');
        $j = ($codigo === 200 && $resp) ? json_decode($resp, true) : null;
        $orig = is_array($j) ? ($j['translation_of'] ?? '') : '';
        return is_string($orig) ? trim($orig) : '';
    }

    /** @return array{0:int, 1:string|false, 2:string} [código HTTP, cuerpo, error de curl] */
    private function http(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_USERAGENT      => 'ReadsApp/1.0 (' . (defined('APP_CONTACTO') ? APP_CONTACTO : 'contacto') . ')',
            CURLOPT_SSL_VERIFYPEER => !(bool)config('curl_sin_verificar', false),
        ]);
        $resp = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return [$codigo, $resp, $error];
    }
}