<?php
require_once __DIR__ . '/DeviantArtService.php';
require_once __DIR__ . '/TumblrService.php';

class VibesService
{
    public const MAX_POR_USUARIO   = 10;   // imágenes por usuario y libro
    public const REPORTES_OCULTAR  = 3;    // con 3 reportes la imagen deja de mostrarse

    private PDO $pdo;

    public string $estado = '';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->asegurarTablas();
    }

    private function asegurarTablas(): void
    {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        if (!empty($_SESSION['tablas_vibes_v6'])) return;

        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS libro_vibes (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    libro_id VARCHAR(100) NOT NULL,
                    usuario_id INT NOT NULL,
                    imagen_url TEXT NOT NULL,
                    credito VARCHAR(120) NULL,
                    enlace_origen VARCHAR(600) NULL,
                    nota VARCHAR(160) NULL,
                    origen VARCHAR(12) NOT NULL DEFAULT 'usuario',
                    ref_externa VARCHAR(80) NULL,
                    tipo VARCHAR(10) NOT NULL DEFAULT 'imagen',
                    creado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_libro (libro_id)
                ) CHARACTER SET utf8mb4"
            );

            $cols = $this->pdo->query("SHOW COLUMNS FROM libro_vibes")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('origen', $cols, true)) {
                $this->pdo->exec("ALTER TABLE libro_vibes ADD COLUMN origen VARCHAR(12) NOT NULL DEFAULT 'usuario'");
            }
            if (!in_array('ref_externa', $cols, true)) {
                $this->pdo->exec("ALTER TABLE libro_vibes ADD COLUMN ref_externa VARCHAR(80) NULL");
            }
            if (!in_array('tipo', $cols, true)) {
                $this->pdo->exec("ALTER TABLE libro_vibes ADD COLUMN tipo VARCHAR(10) NOT NULL DEFAULT 'imagen'");
            }

            $tipo = $this->pdo->query("SHOW COLUMNS FROM libro_vibes LIKE 'imagen_url'")->fetch(PDO::FETCH_ASSOC);
            if ($tipo && stripos((string)$tipo['Type'], 'varchar') !== false) {
                $this->pdo->exec("ALTER TABLE libro_vibes MODIFY imagen_url TEXT NOT NULL");
            }

            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS libro_vibes_reportes (
                    vibe_id INT NOT NULL,
                    usuario_id INT NOT NULL,
                    creado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (vibe_id, usuario_id)
                ) CHARACTER SET utf8mb4"
            );
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS libro_vibes_busquedas (
                    libro_id VARCHAR(100) NOT NULL PRIMARY KEY,
                    ultima DATETIME NOT NULL
                ) CHARACTER SET utf8mb4"
            );
            // Una sola vez: las imágenes automáticas antiguas se buscaron sin comprobar que fueran del libro
            $hecho = (int)$this->pdo->query("SELECT COUNT(*) FROM libro_vibes_busquedas WHERE libro_id = '__filtro_v3__'")->fetchColumn();
            if ($hecho === 0) {
                $this->pdo->exec("DELETE FROM libro_vibes WHERE origen = 'auto'");
                $this->pdo->exec("DELETE FROM libro_vibes_busquedas");
                $this->pdo->exec("INSERT INTO libro_vibes_busquedas (libro_id, ultima) VALUES ('__filtro_v3__', NOW())");
            }
            $_SESSION['tablas_vibes_v6'] = true;
        } catch (Throwable $e) {}
    }

    /**
     * Rellena el tablero con fanarts de DeviantArt y de Tumblr la primera vez que se abre un libro
     * y los renueva una vez por semana (algunos enlaces de imagen caducan).
     * Si fallan las dos APIs, no se reintenta hasta pasado un día.
     */
    public function autocompletar(string $libroId, string $titulo, string $autor = '', string $isbn = '', string $descripcion = ''): void
    {
        $usaDA = DeviantArtService::disponible();
        $usaTM = TumblrService::disponible();
        if (!$usaDA && !$usaTM) {
            $this->estado = 'sin claves de DeviantArt (deviantart_id / deviantart_secret) ni de Tumblr (tumblr_key) en la configuración';
            return;
        }
        $depurar = isset($_GET['debug']);   // con ?debug se salta la espera entre búsquedas

        // Si acaba de fallar todo, no insistir con otros libros durante 30 minutos (evita páginas lentas)
        if (!$depurar && isset($_SESSION['fanart_fallo']) && (time() - $_SESSION['fanart_fallo']) < 1800) {
            $this->estado = 'las fuentes de fanarts fallaron hace poco; se reintentará en unos minutos';
            return;
        }

        try {
            $st = $this->pdo->prepare("SELECT ultima FROM libro_vibes_busquedas WHERE libro_id = ?");
            $st->execute([$libroId]);
            $ultima = $st->fetchColumn();
            if (!$depurar && $ultima && (time() - strtotime((string)$ultima)) < 7 * 86400) {
                $this->estado = 'ya buscado el ' . $ultima . ' (se renueva cada 7 días)';
                return;
            }
        } catch (Throwable $e) {
            return;
        }

        $items = [];
        $partes = [];
        $algunaRespondio = false;
        $todasRespondieron = true;

        if ($usaDA) {
            $da = new DeviantArtService();
            $r = $da->buscarFanarts($titulo, $autor, $isbn, $descripcion);
            if ($r === null) {
                $todasRespondieron = false;
                $partes[] = 'DeviantArt: ERROR ' . $da->ultimoError . ($da->log ? ' (' . implode(' | ', $da->log) . ')' : '');
            } else {
                $algunaRespondio = true;
                foreach ($r as $x) { $x['fuente'] = 'DeviantArt'; $items[] = $x; }
                $partes[] = 'DeviantArt: ' . count($r) . ' fanarts (' . implode(' | ', $da->log) . ')';
            }
        }
        if ($usaTM) {
            $tm = new TumblrService();
            $r = $tm->buscarFanarts($titulo, $autor, $isbn, $descripcion);
            if ($r === null) {
                $todasRespondieron = false;
                $partes[] = 'Tumblr: ERROR ' . $tm->ultimoError . ($tm->log ? ' (' . implode(' | ', $tm->log) . ')' : '');
            } else {
                $algunaRespondio = true;
                foreach ($r as $x) { $x['fuente'] = 'Tumblr'; $items[] = $x; }
                $partes[] = 'Tumblr: ' . count($r) . ' fanarts (' . implode(' | ', $tm->log) . ')';
            }
        }

        if (!$algunaRespondio) $_SESSION['fanart_fallo'] = time();
        $items = array_slice($items, 0, 14);
        $this->estado = implode(' || ', $partes);

        $marca = date('Y-m-d H:i:s', $algunaRespondio ? time() : time() - 6 * 86400);
        try {
            $this->pdo->prepare(
                "INSERT INTO libro_vibes_busquedas (libro_id, ultima) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE ultima = VALUES(ultima)"
            )->execute([$libroId, $marca]);
        } catch (Throwable $e) {}

        if ($todasRespondieron) {
            try {
                $refs = array_column($items, 'ref');
                if ($refs) {
                    $marcas = implode(',', array_fill(0, count($refs), '?'));
                    $this->pdo->prepare("DELETE FROM libro_vibes WHERE libro_id = ? AND origen = 'auto' AND ref_externa NOT IN ($marcas)")
                              ->execute(array_merge([$libroId], $refs));
                } else {
                    $this->pdo->prepare("DELETE FROM libro_vibes WHERE libro_id = ? AND origen = 'auto'")->execute([$libroId]);
                }
            } catch (Throwable $e) {}
        }

        foreach ($items as $it) {
            $credito = mb_substr(($it['artista'] !== '' ? $it['artista'] : 'Artista') . ' · ' . ($it['fuente'] ?? 'DeviantArt'), 0, 120);
            try {
                $st = $this->pdo->prepare(
                    "SELECT id FROM libro_vibes WHERE libro_id = ? AND origen = 'auto' AND ref_externa = ? LIMIT 1"
                );
                $st->execute([$libroId, $it['ref']]);
                $existente = $st->fetchColumn();

                if ($existente) {
                    $this->pdo->prepare("UPDATE libro_vibes SET imagen_url = ? WHERE id = ?")
                              ->execute([$it['imagen'], $existente]);
                } else {
                    $this->pdo->prepare(
                        "INSERT INTO libro_vibes (libro_id, usuario_id, imagen_url, credito, enlace_origen, nota, origen, ref_externa)
                         VALUES (?, 0, ?, ?, ?, ?, 'auto', ?)"
                    )->execute([
                        $libroId, $it['imagen'], $credito, $it['enlace'],
                        $it['nota'] !== '' ? $it['nota'] : null, $it['ref'],
                    ]);
                }
            } catch (Throwable $e) {}
        }
    }

    /** Imágenes visibles de un libro (las más recientes primero). */
    public function listar(string $libroId): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT v.*,
                        (SELECT COUNT(*) FROM libro_vibes_reportes r WHERE r.vibe_id = v.id) AS reportes
                 FROM libro_vibes v
                 WHERE v.libro_id = ?
                 ORDER BY (v.origen = 'usuario') DESC, v.id DESC
                 LIMIT 60"
            );
            $st->execute([$libroId]);
            $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        return array_values(array_filter($filas, fn($f) => (int)$f['reportes'] < self::REPORTES_OCULTAR));
    }

    /** @return array{0: bool, 1: string} [correcto, mensaje] */
    public function agregar(string $libroId, int $usuarioId, string $imagen, string $credito, string $enlace, string $nota): array
    {
        $imagen = $this->urlHttps($imagen, 2000);
        if ($imagen === null) {
            return [false, 'La dirección de la imagen no es válida. Tiene que empezar por https://'];
        }

        // Enlace de un pin de Pinterest: se guarda tal cual y se muestra con el widget oficial de Pinterest
        $tipo = 'imagen';
        $host = strtolower((string)parse_url($imagen, PHP_URL_HOST));
        $ruta = (string)parse_url($imagen, PHP_URL_PATH);
        if (preg_match('/(^|\.)pinterest\.[a-z.]+$/', $host) || $host === 'pin.it') {
            if (!preg_match('#^/pin/(?:[^/]*-)?(\d{5,})#', $ruta, $mp)) {
                return [false, 'Ese enlace de Pinterest no es de un pin. Abre el pin en el navegador y copia la dirección de la barra (empieza por pinterest.com/pin/...).'];
            }
            $imagen = 'https://www.pinterest.com/pin/' . $mp[1] . '/';
            $tipo = 'pin';
        }

        $enlaceLimpio = null;
        if (trim($enlace) !== '') {
            $enlaceLimpio = $this->urlHttps($enlace);
            if ($enlaceLimpio === null) {
                return [false, 'El enlace a la fuente original no es válido. Tiene que empezar por https://'];
            }
        }

        if ($tipo === 'pin') $enlaceLimpio = $imagen;

        $credito = mb_substr(trim(strip_tags($credito)), 0, 120);
        $nota    = mb_substr(trim(strip_tags($nota)), 0, 160);

        try {
            $st = $this->pdo->prepare("SELECT COUNT(*) FROM libro_vibes WHERE libro_id = ? AND usuario_id = ?");
            $st->execute([$libroId, $usuarioId]);
            if ((int)$st->fetchColumn() >= self::MAX_POR_USUARIO) {
                return [false, 'Has llegado al máximo de ' . self::MAX_POR_USUARIO . ' imágenes para este libro.'];
            }

            $st = $this->pdo->prepare("SELECT COUNT(*) FROM libro_vibes WHERE libro_id = ? AND imagen_url = ?");
            $st->execute([$libroId, $imagen]);
            if ((int)$st->fetchColumn() > 0) {
                return [false, 'Esa imagen ya está en el tablero.'];
            }

            $this->pdo->prepare(
                "INSERT INTO libro_vibes (libro_id, usuario_id, imagen_url, credito, enlace_origen, nota, tipo)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $libroId, $usuarioId, $imagen,
                $credito !== '' ? $credito : null,
                $enlaceLimpio,
                $nota !== '' ? $nota : null,
                $tipo,
            ]);
        } catch (Throwable $e) {
            return [false, 'No se pudo guardar la imagen. Inténtalo de nuevo.'];
        }

        return [true, '¡Imagen añadida al tablero!'];
    }

    /** Solo el autor de la imagen puede borrarla. */
    public function eliminar(int $vibeId, int $usuarioId): bool
    {
        try {
            $st = $this->pdo->prepare("DELETE FROM libro_vibes WHERE id = ? AND usuario_id = ?");
            $st->execute([$vibeId, $usuarioId]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Cada usuario puede reportar una imagen una sola vez. */
    public function reportar(int $vibeId, int $usuarioId): bool
    {
        try {
            $this->pdo->prepare("INSERT IGNORE INTO libro_vibes_reportes (vibe_id, usuario_id) VALUES (?, ?)")
                      ->execute([$vibeId, $usuarioId]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function urlHttps(string $url, int $max = 600): ?string
    {
        $url = trim($url);
        if ($url === '' || mb_strlen($url) > $max || !filter_var($url, FILTER_VALIDATE_URL)) return null;

        $p = parse_url($url);
        if (strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return null;

        return $url;
    }
}