<?php
class Traductor
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->asegurarTabla();
    }

    private function asegurarTabla(): void
    {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        if (!empty($_SESSION['tabla_traducciones_ok'])) return;

        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS traducciones_desc (
                    hash CHAR(32) NOT NULL PRIMARY KEY,
                    texto_es MEDIUMTEXT NOT NULL,
                    creado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) CHARACTER SET utf8mb4"
            );
            $_SESSION['tabla_traducciones_ok'] = true;
        } catch (Throwable $e) {
  
        }
    }

    public static function esIngles(string $texto): bool
    {
        $t = mb_strtolower(strip_tags($texto), 'UTF-8');
        preg_match_all('/\p{L}+/u', $t, $m);
        $palabras = $m[0];
        if (count($palabras) < 8) return false;

        static $en = null, $es = null;
        $en ??= array_flip(['the', 'and', 'of', 'to', 'in', 'is', 'was', 'with', 'that', 'for', 'his', 'her', 'she',
            'they', 'their', 'as', 'on', 'at', 'by', 'from', 'but', 'not', 'are', 'be', 'this', 'who', 'has', 'have',
            'had', 'it', 'an', 'when', 'will', 'one', 'into', 'after', 'all', 'been', 'what', 'must']);
        $es ??= array_flip(['el', 'la', 'los', 'las', 'de', 'del', 'y', 'en', 'que', 'un', 'una', 'con', 'por', 'para',
            'su', 'sus', 'se', 'al', 'lo', 'como', 'más', 'pero', 'sin', 'sobre', 'entre', 'cuando', 'también', 'le',
            'les', 'ha', 'han', 'muy', 'este', 'esta']);

        $nEn = 0;
        $nEs = 0;
        foreach ($palabras as $p) {
            if (isset($en[$p])) $nEn++;
            if (isset($es[$p])) $nEs++;
        }
        return $nEn >= 3 && $nEn > $nEs * 1.5;
    }

    /* Devuelve el texto en español, o null si no se ha podido traducir. */
    public function aEspanol(string $texto): ?string
    {
        $limpio = $this->limpiar($texto);
        if ($limpio === '') return null;

        $hash = md5($limpio);

        try {
            $st = $this->pdo->prepare("SELECT texto_es FROM traducciones_desc WHERE hash = ?");
            $st->execute([$hash]);
            $guardada = $st->fetchColumn();
            if ($guardada) return (string)$guardada;
        } catch (Throwable $e) {}

        $trad = $this->deepl($limpio) ?? $this->myMemory($limpio);
        if ($trad === null || trim($trad) === '') return null;

        try {
            $this->pdo->prepare("INSERT IGNORE INTO traducciones_desc (hash, texto_es) VALUES (?, ?)")
                      ->execute([$hash, $trad]);
        } catch (Throwable $e) {}

        return $trad;
    }

    /* Quita etiquetas HTML conservando los saltos de párrafo y recorta textos larguísimos. */
    private function limpiar(string $texto): string
    {
        $texto = preg_replace('/<\s*(br|\/p)\s*\/?>/i', "\n", $texto);
        $texto = html_entity_decode(strip_tags($texto), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = trim(preg_replace("/[ \t]+/", ' ', $texto));
        return $this->cortarEnFrase($texto, 4000);
    }

    private function cortarEnFrase(string $texto, int $max): string
    {
        if (mb_strlen($texto) <= $max) return $texto;
        $corte = mb_substr($texto, 0, $max);
        $punto = max(mb_strrpos($corte, '. '), mb_strrpos($corte, "\n"));
        return $punto !== false && $punto > $max * 0.5 ? mb_substr($corte, 0, $punto + 1) : $corte . '…';
    }

    private function deepl(string $texto): ?string
    {
        $key = (string)config('deepl_key', '');
        if ($key === '') return null;

        $host = str_ends_with($key, ':fx') ? 'api-free.deepl.com' : 'api.deepl.com';
        $ch = curl_init("https://$host/v2/translate");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => ['Authorization: DeepL-Auth-Key ' . $key],
            CURLOPT_POSTFIELDS     => http_build_query([
                'text'        => $texto,
                'source_lang' => 'EN',
                'target_lang' => 'ES',
            ]),
        ]);
        $resp = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($codigo !== 200 || !$resp) return null;
        $j = json_decode($resp, true);
        return $j['translations'][0]['text'] ?? null;
    }

    /* Servicio gratuito de reserva. Admite 500 caracteres por petición y tiene un límite diario. */
    private function myMemory(string $texto): ?string
    {
        $email = (string)config('mymemory_email', '');
        $texto = $this->cortarEnFrase($texto, 2000);

        $partes = [];
        $actual = '';
        foreach (preg_split('/(?<=[.!?])\s+/u', $texto) as $frase) {
            if ($actual !== '' && mb_strlen($actual . ' ' . $frase) > 450) {
                $partes[] = $actual;
                $actual = $frase;
            } else {
                $actual = ($actual === '') ? $frase : $actual . ' ' . $frase;
            }
        }
        if ($actual !== '') $partes[] = $actual;

        $salida = [];
        foreach ($partes as $parte) {
            $params = ['q' => mb_substr($parte, 0, 480), 'langpair' => 'en|es'];
            if ($email !== '') $params['de'] = $email;

            $ch = curl_init('https://api.mymemory.translated.net/get?' . http_build_query($params));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 6,
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);

            $j = $resp ? json_decode($resp, true) : null;
            $t = (string)($j['responseData']['translatedText'] ?? '');
            if (($j['responseStatus'] ?? 0) != 200 || $t === '' || stripos($t, 'MYMEMORY WARNING') !== false) {
                return null;   // límite diario alcanzado u otro fallo: mejor no mezclar idiomas
            }
            $salida[] = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $salida ? implode(' ', $salida) : null;
    }
}