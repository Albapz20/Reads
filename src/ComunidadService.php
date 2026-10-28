<?php

require_once __DIR__ . '/AutorService.php';  

// Géneros 
$__generosHelper = __DIR__ . '/GenerosHelper.php';
if (is_file($__generosHelper)) require_once $__generosHelper;
unset($__generosHelper);


class ComunidadService
{
    private const VISIBLE = "NOT EXISTS (SELECT 1 FROM ajustes_usuario a WHERE a.usuario_id = u.id AND a.mostrar_listas = 0)";

    public function __construct(private PDO $pdo) {}

    /*  Seguridad  y avatares */

    public static function token(): string
    {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        if (empty($_SESSION['csrf_comunidad'])) {
            $_SESSION['csrf_comunidad'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf_comunidad'];
    }

    public static function verificarToken(string $t): bool
    {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        return !empty($_SESSION['csrf_comunidad']) && hash_equals($_SESSION['csrf_comunidad'], $t);
    }

    public static function iniciales(string $nombre): string
    {
        $partes = preg_split('/\s+/', trim($nombre)) ?: [];
        $ini = mb_strtoupper(mb_substr($partes[0] ?? '?', 0, 1));
        if (count($partes) > 1) $ini .= mb_strtoupper(mb_substr(end($partes), 0, 1));
        return $ini !== '' ? $ini : '?';
    }

    public static function hue(string $nombre): int
    {
        return crc32(mb_strtolower($nombre)) % 360;
    }

    public static function color(string $nombre): string
    {
        return 'hsl(' . self::hue($nombre) . ' 55% 45%)';
    }

    /* Seguir / dejar de seguir */
    public function seguir(int $yo, int $otro): bool
    {
        if ($yo <= 0 || $otro <= 0 || $yo === $otro) return false;

        $st = $this->pdo->prepare("SELECT u.id FROM usuarios u WHERE u.id = ? AND " . self::VISIBLE);
        $st->execute([$otro]);
        if (!$st->fetchColumn()) return false;

        $this->pdo->prepare("INSERT IGNORE INTO seguidores (seguidor_id, seguido_id) VALUES (?, ?)")
                  ->execute([$yo, $otro]);
        return true;
    }

    public function dejarDeSeguir(int $yo, int $otro): bool
    {
        $this->pdo->prepare("DELETE FROM seguidores WHERE seguidor_id = ? AND seguido_id = ?")
                  ->execute([$yo, $otro]);
        return true;
    }

    public function estaSiguiendo(int $yo, int $otro): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM seguidores WHERE seguidor_id = ? AND seguido_id = ?");
        $st->execute([$yo, $otro]);
        return (bool)$st->fetchColumn();
    }

    /** @return array{siguiendo:int, seguidores:int} */
    public function contadores(int $id): array
    {
        $st = $this->pdo->prepare(
            "SELECT (SELECT COUNT(*) FROM seguidores WHERE seguidor_id = ?) AS siguiendo,
                    (SELECT COUNT(*) FROM seguidores WHERE seguido_id = ?)  AS seguidores"
        );
        $st->execute([$id, $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['siguiendo' => (int)($r['siguiendo'] ?? 0), 'seguidores' => (int)($r['seguidores'] ?? 0)];
    }

    /** Buscar lectores por nombre (solo perfiles públicos, nunca por email) */
    public function buscarUsuarios(int $yo, string $q, int $limite = 30): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $pref = addcslashes($q, '%_\\') . '%';

        $st = $this->pdo->prepare(
            "SELECT u.id, u.nombre, u.avatar,
                    (SELECT COUNT(*) FROM listas_lectura l WHERE l.usuario_id = u.id AND l.estado = 'leido')   AS leidos,
                    (SELECT COUNT(*) FROM listas_lectura l WHERE l.usuario_id = u.id AND l.estado = 'leyendo') AS leyendo,
                    EXISTS(SELECT 1 FROM seguidores s WHERE s.seguidor_id = ? AND s.seguido_id = u.id)         AS sigo
             FROM usuarios u
             WHERE u.id <> ? AND u.nombre LIKE ? AND " . self::VISIBLE . "
             ORDER BY (u.nombre LIKE ?) DESC, u.nombre ASC
             LIMIT " . (int)$limite
        );
        $st->execute([$yo, $yo, $like, $pref]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Usuarios a los que sigue $id ('siguiendo') o que le siguen ('seguidores') */
    public function listaRelacion(int $id, string $tipo, int $yo, int $limite = 100): array
    {
        [$filtro, $columnaUsuario] = $tipo === 'seguidores'
            ? ['s.seguido_id', 's.seguidor_id']
            : ['s.seguidor_id', 's.seguido_id'];

        $st = $this->pdo->prepare(
            "SELECT u.id, u.nombre, u.avatar,
                    (SELECT COUNT(*) FROM listas_lectura l WHERE l.usuario_id = u.id AND l.estado = 'leido')   AS leidos,
                    (SELECT COUNT(*) FROM listas_lectura l WHERE l.usuario_id = u.id AND l.estado = 'leyendo') AS leyendo,
                    EXISTS(SELECT 1 FROM seguidores s2 WHERE s2.seguidor_id = ? AND s2.seguido_id = u.id)      AS sigo
             FROM seguidores s
             JOIN usuarios u ON u.id = $columnaUsuario
             WHERE $filtro = ? AND " . self::VISIBLE . "
             ORDER BY s.creado DESC
             LIMIT " . (int)$limite
        );
        $st->execute([$yo, $id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Lectores que quizá te interesen */
    public function sugeridos(int $yo, int $limite = 6): array
    {
        $st = $this->pdo->prepare(
            "SELECT u.id, u.nombre, u.avatar, COUNT(DISTINCT l2.libro_id) AS en_comun,
                    (SELECT COUNT(*) FROM listas_lectura x WHERE x.usuario_id = u.id AND x.estado = 'leido') AS leidos
             FROM listas_lectura l1
             JOIN listas_lectura l2 ON l2.libro_id = l1.libro_id AND l2.usuario_id <> l1.usuario_id
             JOIN usuarios u ON u.id = l2.usuario_id
             WHERE l1.usuario_id = ? AND " . self::VISIBLE . "
               AND u.id NOT IN (SELECT seguido_id FROM seguidores WHERE seguidor_id = ?)
             GROUP BY u.id, u.nombre, u.avatar
             ORDER BY en_comun DESC, leidos DESC
             LIMIT " . (int)$limite
        );
        $st->execute([$yo, $yo]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);

        if (count($filas) < $limite) {
            $ya = array_map(fn($f) => (int)$f['id'], $filas);
            $st = $this->pdo->prepare(
                "SELECT u.id, u.nombre, u.avatar, 0 AS en_comun, COUNT(l.id) AS leidos
                 FROM usuarios u
                 JOIN listas_lectura l ON l.usuario_id = u.id AND l.estado = 'leido'
                 WHERE u.id <> ? AND " . self::VISIBLE . "
                   AND u.id NOT IN (SELECT seguido_id FROM seguidores WHERE seguidor_id = ?)
                 GROUP BY u.id, u.nombre, u.avatar
                 ORDER BY leidos DESC
                 LIMIT " . (int)($limite + count($ya) + 5)
            );
            $st->execute([$yo, $yo]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
                if (in_array((int)$f['id'], $ya, true)) continue;
                $filas[] = $f;
                if (count($filas) >= $limite) break;
            }
        }

        foreach ($filas as &$f) {
            $f['sigo'] = 0;
            $f['leyendo'] = 0;
        }
        unset($f);
        return $filas;
    }

    /** Libros que están leyendo ahora mismo las personas a las que sigues  */
    public function amigosLeyendo(int $yo, int $limite = 12): array
    {
        $st = $this->pdo->prepare(
            "SELECT l.id, l.libro_id, l.titulo, l.autores, l.portada,
                    l.paginas_totales, l.paginas_leidas, l.progreso,
                    u.id AS uid, u.nombre, u.avatar
             FROM seguidores s
             JOIN usuarios u ON u.id = s.seguido_id
             JOIN listas_lectura l ON l.usuario_id = u.id AND l.estado = 'leyendo'
             WHERE s.seguidor_id = ? AND " . self::VISIBLE . "
             ORDER BY l.id DESC
             LIMIT " . ((int)$limite * 4)
        );
        $st->execute([$yo]);
        $filas = $this->sinDuplicados($st->fetchAll(PDO::FETCH_ASSOC), $limite);
        return array_map([$this, 'conPorcentaje'], $filas);
    }

    /** Últimos libros añadidos o movidos de estantería por tus amigos */
    public function actividad(int $yo, int $limite = 20, array $estados = []): array
    {
        $filtro = '';
        $params = [$yo];
        if (!empty($estados)) {
            $filtro = ' AND l.estado IN (' . implode(',', array_fill(0, count($estados), '?')) . ')';
            array_push($params, ...$estados);
        }

        $st = $this->pdo->prepare(
            "SELECT l.id, l.libro_id, l.titulo, l.autores, l.portada, l.estado, l.fecha_fin,
                    u.id AS uid, u.nombre, u.avatar
             FROM seguidores s
             JOIN usuarios u ON u.id = s.seguido_id
             JOIN listas_lectura l ON l.usuario_id = u.id
             WHERE s.seguidor_id = ? AND " . self::VISIBLE . $filtro . "
             ORDER BY l.id DESC
             LIMIT " . ((int)$limite * 3)
        );
        $st->execute($params);
        return $this->sinDuplicados($st->fetchAll(PDO::FETCH_ASSOC), $limite);
    }

    /**
     * Actividad agrupada por persona y tipo: en vez de 5 líneas "robe terminó X",
     * una sola: "robe terminó 5 libros" con sus portadas.
     *
     * @param string $filtro todo | terminados | leyendo | quieren
     */
    public function actividadAgrupada(int $yo, int $maxGrupos = 6, string $filtro = 'todo', int $maxFilas = 120): array
    {
        $estados = match ($filtro) {
            'terminados' => ['leido'],
            'leyendo'    => ['leyendo'],
            'quieren'    => ['tbr', 'guardado'],
            default      => [],
        };

        $grupos = [];
        foreach ($this->actividad($yo, $maxFilas, $estados) as $f) {
            $k = $f['uid'] . '|' . $f['estado'];
            if (!isset($grupos[$k])) {
                if (count($grupos) >= $maxGrupos) continue;
                $grupos[$k] = ['uid' => (int)$f['uid'], 'nombre' => $f['nombre'], 'avatar' => $f['avatar'] ?? null, 'estado' => $f['estado'], 'libros' => []];
            }
            $grupos[$k]['libros'][] = $f;
        }
        return array_values($grupos);
    }

    /** Amigos tuyos que tienen este libro (para la ficha del libro) */
    public function amigosConLibro(int $yo, string $libroId, string $titulo): array
    {
        $st = $this->pdo->prepare(
            "SELECT u.id, u.nombre, u.avatar, MAX(l.estado) AS estado
             FROM seguidores s
             JOIN usuarios u ON u.id = s.seguido_id
             JOIN listas_lectura l ON l.usuario_id = u.id AND (l.libro_id = ? OR l.titulo = ?)
             WHERE s.seguidor_id = ? AND " . self::VISIBLE . "
             GROUP BY u.id, u.nombre, u.avatar
             ORDER BY u.nombre"
        );
        $st->execute([$libroId, $titulo, $yo]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /*  Perfil público de otro lector */

    /** @return array|null null si el usuario no existe */
    public function perfil(int $id, int $yo): ?array
    {
        $st = $this->pdo->prepare("SELECT id, nombre, tema_visual, avatar FROM usuarios WHERE id = ?");
        $st->execute([$id]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) return null;

        $st = $this->pdo->prepare(
            "SELECT COUNT(*) AS n, MIN(mostrar_listas) AS ml, MAX(objetivo_anual) AS obj
             FROM ajustes_usuario WHERE usuario_id = ?"
        );
        $st->execute([$id]);
        $aj = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 'ml' => null, 'obj' => null];
        $publico = ((int)$aj['n'] === 0) || $aj['ml'] === null || (int)$aj['ml'] === 1;

        $perfil = [
            'usuario'  => $u,
            'tema'     => strtolower(trim((string)($u['tema_visual'] ?? ''))) ?: 'pastel',
            'privado'  => !$publico,
            'sigo'     => $this->estaSiguiendo($yo, $id),
            'te_sigue' => $this->estaSiguiendo($id, $yo),
        ] + $this->contadores($id);

        if (!$publico) return $perfil;

        $year = (int)date('Y');

        // Libros y páginas del año
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) AS libros,
                    COALESCE(SUM(CASE WHEN paginas_leidas > 0 THEN paginas_leidas ELSE paginas_totales END), 0) AS paginas
             FROM listas_lectura
             WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?"
        );
        $st->execute([$id, $year]);
        $anio = $st->fetch(PDO::FETCH_ASSOC) ?: ['libros' => 0, 'paginas' => 0];

        // Recuento por estantería
        $st = $this->pdo->prepare("SELECT estado, COUNT(*) AS c FROM listas_lectura WHERE usuario_id = ? GROUP BY estado");
        $st->execute([$id]);
        $porEstado = array_fill_keys(['leido', 'leyendo', 'tbr', 'guardado', 'abandonado'], 0);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $porEstado[$r['estado']] = (int)$r['c'];
        }

        // Libros terminados por mes (este año)
        $st = $this->pdo->prepare(
            "SELECT MONTH(fecha_fin) AS m, COUNT(*) AS c
             FROM listas_lectura
             WHERE usuario_id = ? AND estado = 'leido' AND YEAR(fecha_fin) = ?
             GROUP BY MONTH(fecha_fin)"
        );
        $st->execute([$id, $year]);
        $meses = array_fill(1, 12, 0);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int)$r['m'] >= 1 && (int)$r['m'] <= 12) $meses[(int)$r['m']] = (int)$r['c'];
        }

        // Autor favorito (el más leído)
        $st = $this->pdo->prepare("SELECT autores FROM listas_lectura WHERE usuario_id = ? AND estado = 'leido'");
        $st->execute([$id]);
        $autores = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $a) {
            $n = trim(explode(',', (string)$a)[0]);
            if ($n !== '') $autores[$n] = ($autores[$n] ?? 0) + 1;
        }
        arsort($autores);
        $autorTop = $autores ? array_key_first($autores) : null;

        $perfil += [
            'anio'         => $year,
            'leidos_anio'  => (int)$anio['libros'],
            'paginas_anio' => (int)$anio['paginas'],
            'por_estado'   => $porEstado,
            'leidos_total' => $porEstado['leido'],
            'objetivo'     => !empty($aj['obj']) ? (int)$aj['obj'] : 20,
            'meses'        => $meses,
            'autor_top'    => $autorTop,
            'autor_top_n'  => $autorTop !== null ? $autores[$autorTop] : 0,
            'generos'      => $this->generosTop($id, 5),
            'leyendo'      => $this->librosDe($id, ['leyendo'], 6),
            'recientes'    => $this->librosDe($id, ['leido'], 12, true),
            'quiere'       => $this->librosDe($id, ['tbr', 'guardado'], 8),
            'afinidad'     => $id !== $yo ? $this->afinidad($yo, $id) : null,
        ];
        return $perfil;
    }

    private function librosDe(int $uid, array $estados, int $limite, bool $porFechaFin = false): array
    {
        $orden = $porFechaFin ? 'fecha_fin DESC, id DESC' : 'id DESC';
        $marcas = implode(',', array_fill(0, count($estados), '?'));
        $st = $this->pdo->prepare(
            "SELECT id, libro_id, titulo, autores, portada, paginas_totales, paginas_leidas, progreso
             FROM listas_lectura
             WHERE usuario_id = ? AND estado IN ($marcas)
             ORDER BY $orden
             LIMIT " . ((int)$limite * 3)
        );
        $st->execute(array_merge([$uid], $estados));

        // Sin repetidos por título
        $vistos = [];
        $salida = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $k = AutorService::tituloBase((string)$l['titulo']) ?: (string)$l['libro_id'];
            if (isset($vistos[$k])) continue;
            $vistos[$k] = true;
            $salida[] = $this->conPorcentaje($l);
            if (count($salida) >= $limite) break;
        }
        return $salida;
    }

    /** Géneros más leídos*/
    private function generosTop(int $uid, int $max = 5): array
    {
        if (!function_exists('columnaGeneros') || !function_exists('separarGeneros') || !function_exists('normalizarGeneros')) {
            return [];
        }
        try {
            $col = columnaGeneros($this->pdo);
            if ($col === null || !preg_match('/^[A-Za-z0-9_]+$/', (string)$col)) return [];

            $st = $this->pdo->prepare("SELECT `$col` FROM listas_lectura WHERE usuario_id = ? AND estado = 'leido'");
            $st->execute([$uid]);

            $cuenta = [];
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) {
                foreach (normalizarGeneros(separarGeneros((string)$v)) as $g) {
                    $cuenta[$g] = ($cuenta[$g] ?? 0) + 1;
                }
            }
            arsort($cuenta);
            return array_slice($cuenta, 0, $max, true);
        } catch (Throwable $e) {
            return [];
        }
    }

    /* Afinidad lectora entre dos usuarios: libros y autores en común */
    public function afinidad(int $yo, int $otro): ?array
    {
        $cargar = function (int $uid): array {
            $st = $this->pdo->prepare("SELECT id, libro_id, titulo, autores, portada FROM listas_lectura WHERE usuario_id = ?");
            $st->execute([$uid]);
            $libros = [];
            $autores = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $b = AutorService::tituloBase((string)$r['titulo']);
                if ($b !== '') $libros[$b] = $r;
                $a = AutorService::claveAutor(trim(explode(',', (string)($r['autores'] ?? ''))[0]));
                if ($a !== '') $autores[$a] = true;
            }
            return [$libros, $autores];
        };

        [$librosA, $autoresA] = $cargar($yo);
        [$librosB, $autoresB] = $cargar($otro);
        if (!$librosA || !$librosB) return null;

        $comunes  = array_intersect_key($librosB, $librosA);
        $autComun = array_intersect_key($autoresA, $autoresB);

        $den = min(count($librosA), count($librosB)) + max(1, min(count($autoresA), count($autoresB)));
        $indice = (int)min(100, round(100 * (count($comunes) + count($autComun)) / $den));

        return [
            'indice'  => $indice,
            'libros'  => count($comunes),
            'autores' => count($autComun),
            'lista'   => array_slice(array_values($comunes), 0, 8),   // filas con id, libro_id, titulo, portada
        ];
    }


    private function sinDuplicados(array $filas, int $limite): array
    {
        $vistos = [];
        $salida = [];
        foreach ($filas as $f) {
            $base = AutorService::tituloBase((string)$f['titulo']) ?: (string)$f['libro_id'];
            $k = $f['uid'] . '|' . $base;
            if (isset($vistos[$k])) continue;
            $vistos[$k] = true;
            $salida[] = $f;
            if (count($salida) >= $limite) break;
        }
        return $salida;
    }

    private function conPorcentaje(array $l): array
    {
        $tot = (int)($l['paginas_totales'] ?? 0);
        $l['pct'] = $tot > 0
            ? (int)min(100, round(((int)$l['paginas_leidas'] / $tot) * 100))
            : (int)($l['progreso'] ?? 0);
        return $l;
    }
}