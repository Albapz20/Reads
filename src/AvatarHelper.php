<?php
class AvatarHelper
{
    const EMOJIS = ['📚', '🦉', '🐉', '🦊', '🐱', '🐼', '🦄', '🌸', '🌿', '☕', '🔮', '🌙', '⭐', '🔥', '🎧', '🧙', '🧛', '🚀'];
    const MAX_BYTES = 2097152;   
    const TAM = 256;            

    public static function token(): string
    {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        if (empty($_SESSION['csrf_avatar'])) {
            $_SESSION['csrf_avatar'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf_avatar'];
    }

    public static function verificarToken(string $t): bool
    {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        return !empty($_SESSION['csrf_avatar']) && hash_equals($_SESSION['csrf_avatar'], $t);
    }

    /*  Utilidades de texto/color  */

    private static function h($t): string
    {
        return htmlspecialchars((string)($t ?? ''), ENT_QUOTES, 'UTF-8');
    }

    public static function iniciales(string $nombre): string
    {
        $partes = preg_split('/\s+/', trim($nombre)) ?: [];
        $ini = mb_strtoupper(mb_substr($partes[0] ?? '?', 0, 1));
        if (count($partes) > 1) $ini .= mb_strtoupper(mb_substr(end($partes), 0, 1));
        return $ini !== '' ? $ini : '?';
    }

    private static function hue(string $nombre): int
    {
        return crc32(mb_strtolower($nombre)) % 360;
    }

    /** Degradado del banner según el tema visual  */
    public static function gradienteTema(string $tema): string
    {
        $mapa = [
            'pastel'      => ['#ff9ec4', '#ffd3e4'],
            'naturalista' => ['#6a9a55', '#b9d4a8'],
            'azul'        => ['#4f93d8', '#a8cff5'],
            'sunset'      => ['#ff7e5f', '#feb47b'],
            'lavanda'     => ['#8b67d6', '#cbb8f3'],
            'coffee'      => ['#6f4e37', '#b8906b'],
            'dracula'     => ['#6272a4', '#bd93f9'],
        ];
        [$a, $b] = $mapa[strtolower($tema)] ?? $mapa['pastel'];
        return "linear-gradient(120deg, $a, $b)";
    }

    /*  Lectura del valor guardado  */
    public static function esEmojiValido(string $e): bool
    {
        return in_array($e, self::EMOJIS, true);
    }

    public static function interpretar(?string $avatar): array
    {
        $avatar = (string)$avatar;
        if (str_starts_with($avatar, 'img:') && preg_match('#^avatares/u\d+_\d+\.jpg$#', substr($avatar, 4))) {
            return ['tipo' => 'img', 'valor' => 'uploads/' . substr($avatar, 4)];
        }
        if (str_starts_with($avatar, 'emoji:') && self::esEmojiValido(substr($avatar, 6))) {
            return ['tipo' => 'emoji', 'valor' => substr($avatar, 6)];
        }
        return ['tipo' => 'letra', 'valor' => ''];
    }

    /** Avatar redondo para listas y tarjetas */
    public static function render(string $nombre, ?string $avatar, string $clase = ''): string
    {
        $a = self::interpretar($avatar);
        $color = 'hsl(' . self::hue($nombre) . ' 55% 45%)';

        if ($a['tipo'] === 'emoji') {
            $fondo = 'hsl(' . self::hue($nombre) . ' 70% 90%)';
            return '<span class="cm-avatar emoji ' . self::h($clase) . '" style="background:' . $fondo . '">' . self::h($a['valor']) . '</span>';
        }

        $img = $a['tipo'] === 'img'
            ? '<img src="' . self::h($a['valor']) . '" alt="" loading="lazy" onerror="this.remove()">'
            : '';
        return '<span class="cm-avatar ' . self::h($clase) . '" style="background:' . $color . '">'
             . self::h(self::iniciales($nombre)) . $img . '</span>';
    }

    /** Contenido del círculo grande de mi perfil */
    public static function contenidoCirculo(string $nombre, ?string $avatar): string
    {
        $a = self::interpretar($avatar);
        if ($a['tipo'] === 'img') {
            return '<img class="avatar-img" src="' . self::h($a['valor']) . '" alt="Imagen de perfil">';
        }
        if ($a['tipo'] === 'emoji') {
            return '<span class="avatar-emoji">' . self::h($a['valor']) . '</span>';
        }
        return self::h(mb_strtoupper(mb_substr(trim($nombre) !== '' ? trim($nombre) : 'A', 0, 1)));
    }

    /*  Subida de foto  */

    private static function dir(): string
    {
        return __DIR__ . '/../public/uploads/avatares';
    }

    /**
     * Valida, recorta y guarda la foto subida.
     * @return array{ok:bool, valor?:string, error?:string}
     */
    public static function guardarImagen(array $f, int $uid): array
    {
        $codigo = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($codigo === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'error' => 'Selecciona una imagen primero.'];
        if ($codigo !== UPLOAD_ERR_OK)      return ['ok' => false, 'error' => 'No se pudo subir la imagen. Inténtalo de nuevo.'];
        if (($f['size'] ?? 0) > self::MAX_BYTES) return ['ok' => false, 'error' => 'La imagen supera los 2 MB.'];
        if (!function_exists('imagecreatefromstring')) {
            return ['ok' => false, 'error' => 'El servidor no tiene la extensión GD activada (php.ini: extension=gd).'];
        }
        if (!is_uploaded_file($f['tmp_name'])) return ['ok' => false, 'error' => 'Archivo no válido.'];

        $info = @getimagesize($f['tmp_name']);
        $permitidos = [IMAGETYPE_JPEG, IMAGETYPE_PNG];
        if (defined('IMAGETYPE_WEBP')) $permitidos[] = IMAGETYPE_WEBP;
        if (!$info || !in_array($info[2], $permitidos, true)) {
            return ['ok' => false, 'error' => 'Formato no válido. Usa una imagen JPG, PNG o WebP.'];
        }
        if ($info[0] * $info[1] > 25000000) {
            return ['ok' => false, 'error' => 'La imagen es demasiado grande en píxeles.'];
        }

        $origen = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
        if (!$origen) return ['ok' => false, 'error' => 'No se pudo leer la imagen.'];

        // Respetar la orientación de las fotos del móvil
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($f['tmp_name']);
            $giro = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
            if ($giro !== 0) {
                $rotada = imagerotate($origen, $giro, 0);
                if ($rotada) { imagedestroy($origen); $origen = $rotada; }
            }
        }

        // Recorte cuadrado centrado y reducción
        $w = imagesx($origen);
        $h = imagesy($origen);
        $lado = min($w, $h);
        $destino = imagecreatetruecolor(self::TAM, self::TAM);
        imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255));   // fondo blanco para PNG con transparencia
        imagecopyresampled($destino, $origen, 0, 0, intdiv($w - $lado, 2), intdiv($h - $lado, 2), self::TAM, self::TAM, $lado, $lado);

        $dir = self::dir();
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true)) {
                return ['ok' => false, 'error' => 'No se pudo crear la carpeta de imágenes.'];
            }
            @file_put_contents($dir . '/.htaccess', "<FilesMatch \"\\.(php|phtml|phar)$\">\n    Require all denied\n</FilesMatch>\n");
        }

        $nombre = 'u' . $uid . '_' . time() . '.jpg';
        $ok = @imagejpeg($destino, $dir . '/' . $nombre, 86);
        imagedestroy($origen);
        imagedestroy($destino);

        return $ok
            ? ['ok' => true, 'valor' => 'img:avatares/' . $nombre]
            : ['ok' => false, 'error' => 'No se pudo guardar la imagen en el servidor.'];
    }

    public static function borrarImagen(?string $avatar): void
    {
        $a = self::interpretar($avatar);
        if ($a['tipo'] !== 'img') return;
        $archivo = self::dir() . '/' . basename($a['valor']);
        if (is_file($archivo)) @unlink($archivo);
    }
}