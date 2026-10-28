<?php

require_once "Database.php";

class ListaService {

    private $db;

    public function __construct() {
        $this->db = new Database();
    }

    // Obtener libros por estado
    public function obtenerLista($usuario_id, $estado) {
        $sql = "SELECT * FROM listas_lectura 
                WHERE usuario_id = ? AND estado = ?
                ORDER BY fecha DESC";

        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$usuario_id, $estado]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function consultarGoogleBooks(string $apiUrl): ?array {
        $keyGB = function_exists('config') ? trim((string)config('google_books_key', '')) : '';
        if ($keyGB !== '') {
            $apiUrl .= (strpos($apiUrl, '?') !== false ? '&' : '?') . 'key=' . urlencode($keyGB);
        }

        $verificar = function_exists('config') ? (bool)config('curl_verify_ssl', true) : true;

        for ($intento = 0; $intento < 2; $intento++) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => $verificar,
                CURLOPT_SSL_VERIFYHOST => $verificar ? 2 : 0,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_USERAGENT      => 'Mozilla/5.0'
            ]);
            $json = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($json && $http === 200) {
                $data = json_decode($json, true);
                if (is_array($data)) return $data;
            }
            if ($http === 404 || $http === 403) break;   // reintentar no sirve
        }
        return null;
    }

    // Nombres de autor de un id de Open Library 
    private function autoresOpenLibrary(string $id): string {
        $verificar = function_exists('config') ? (bool)config('curl_verify_ssl', true) : true;
        $pedir = function (string $url) use ($verificar): ?array {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => $verificar,
                CURLOPT_SSL_VERIFYHOST => $verificar ? 2 : 0,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_USERAGENT      => 'Mozilla/5.0',
            ]);
            $json = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $d = ($json && $http === 200) ? json_decode($json, true) : null;
            return is_array($d) ? $d : null;
        };

        $tipo = strtoupper(substr($id, -1)) === 'W' ? 'works' : 'books';
        $obra = $pedir('https://openlibrary.org/' . $tipo . '/' . urlencode($id) . '.json');
        if (!$obra) return '';

        $nombres = [];
        foreach (array_slice($obra['authors'] ?? [], 0, 3) as $a) {
            $clave = $a['author']['key'] ?? ($a['key'] ?? '');   // obras: {author:{key}}, ediciones: {key}
            if ($clave === '' || !preg_match('#^/authors/OL\d+A$#i', $clave)) continue;
            $ficha = $pedir('https://openlibrary.org' . $clave . '.json');
            if (!empty($ficha['name'])) $nombres[] = $ficha['name'];
        }
        return implode(', ', $nombres);
    }

    // Añadir libro a una lista
    public function agregarLibro($usuario_id, $libro_id, $titulo, $portada, $estado, $autorExterno = '', $fechaFin = null) {

        // Si ya existe, actualizamos el estado (con las mismas reglas de fechas y páginas que cambiarEstado)
        $sqlCheck = "SELECT id FROM listas_lectura WHERE usuario_id = ? AND (libro_id = ? OR titulo = ?)";
        $stmtCheck = $this->db->pdo->prepare($sqlCheck);
        $stmtCheck->execute([$usuario_id, $libro_id, $titulo]);
        $existente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($existente) {
            $ok = $this->cambiarEstado($usuario_id, $existente['id'], $estado);

            // Si viene una fecha de fin concreta (p. ej. de una importación) y el libro es leído, se respeta
            if ($estado === 'leido' && !empty($fechaFin)) {
                $stmtF = $this->db->pdo->prepare("UPDATE listas_lectura SET fecha_fin = ? WHERE usuario_id = ? AND id = ?");
                $stmtF->execute([$fechaFin, $usuario_id, $existente['id']]);
            }
            $this->db->pdo->prepare("UPDATE listas_lectura SET fecha = NOW() WHERE usuario_id = ? AND id = ?")
                          ->execute([$usuario_id, $existente['id']]);
            return $ok;
        }

        // Datos desde Google Books
        $paginas_totales = 0;
        $autores = "";
        $descripcion = "";
        $categorias = "";
        $publicado = "";

        if (!empty($libro_id)) {
            $esBusqueda = false;
            if (preg_match('/^[0-9]{9}[0-9X]$|^[0-9]{13}$/i', (string)$libro_id)) {
                $apiUrl = "https://www.googleapis.com/books/v1/volumes?q=isbn:" . urlencode($libro_id);
                $esBusqueda = true;
            } elseif (str_starts_with((string)$libro_id, 'gr_')) {
                $apiUrl = null;   // id inventado en la importación: no existe en Google Books
            } elseif (preg_match('/^OL\d+[WM]$/i', (string)$libro_id)) {
                $apiUrl = null;   // id de Open Library: no existe en Google Books (las páginas se buscan después en Open Library)
                $autores = $this->autoresOpenLibrary((string)$libro_id);
            } else {
                $apiUrl = "https://www.googleapis.com/books/v1/volumes/" . urlencode($libro_id);
            }

            if ($apiUrl) {
                $data = $this->consultarGoogleBooks($apiUrl);

                if ($data !== null) {
                    $info = null;
                    if ($esBusqueda) {
                        // Una búsqueda por ISBN puede devolver varias ediciones: se coge la primera que tenga páginas,
                        // no simplemente la primera (que a veces viene con pageCount 0 o de otra edición)
                        $items = $data['items'] ?? [];
                        foreach ($items as $it) {
                            if (!empty($it['volumeInfo']['pageCount'])) { $info = $it['volumeInfo']; break; }
                        }
                        if ($info === null && !empty($items[0]['volumeInfo'])) $info = $items[0]['volumeInfo'];
                    } elseif (isset($data['volumeInfo'])) {
                        $info = $data['volumeInfo'];
                    }

                    if ($info !== null) {
                        // Google a veces trae "pageCount" y a veces solo "printedPageCount"
                        $paginas_totales = max(0, (int)($info["pageCount"] ?? 0));
                        if ($paginas_totales <= 0) $paginas_totales = max(0, (int)($info["printedPageCount"] ?? 0));
                        $autores = isset($info["authors"]) ? implode(", ", $info["authors"]) : "";
                        $descripcion = $info["description"] ?? "";
                        $categorias = isset($info["categories"]) ? implode(", ", $info["categories"]) : "";
                        $publicado = $info["publishedDate"] ?? "";
                    }
                }
            }
        }

        // Si la API no dio autor, usar el que venga del CSV
        if ($autores === "" && $autorExterno !== "" && $autorExterno !== "Autor desconocido") {
            $autores = $autorExterno;
        }

        // Si se añade ya como leído: fecha de fin (la indicada o hoy) y páginas leídas = totales
        $fechaFinInsert = $fechaFin;
        $paginasLeidas = 0;
        $progreso = 0;
        if ($estado === 'leido') {
            if (empty($fechaFinInsert)) $fechaFinInsert = date("Y-m-d");
            $paginasLeidas = $paginas_totales;
            $progreso = 100;
        }

        $sqlInsert = "INSERT INTO listas_lectura 
              (usuario_id, libro_id, titulo, portada, estado, fecha, fecha_fin,
               paginas_totales, paginas_leidas, progreso, autores, descripcion, categorias, publicado)
              VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmtInsert = $this->db->pdo->prepare($sqlInsert);
        return $stmtInsert->execute([
            $usuario_id,
            $libro_id,
            $titulo,
            $portada,
            $estado,
            $fechaFinInsert,
            $paginas_totales,
            $paginasLeidas,
            $progreso,
            $autores,
            $descripcion,
            $categorias,
            $publicado
        ]);
    }

    // Cambiar estado
    public function cambiarEstado($usuario_id, $id, $nuevo_estado) {

        $sqlGet = "SELECT fecha_inicio, fecha_fin, progreso FROM listas_lectura 
                   WHERE usuario_id = ? AND id = ?";
        $stmtGet = $this->db->pdo->prepare($sqlGet);
        $stmtGet->execute([$usuario_id, $id]);
        $libro = $stmtGet->fetch(PDO::FETCH_ASSOC);

        if (!$libro) return false;

        $hoy = date("Y-m-d");

        if ($nuevo_estado === "leyendo") {
            if (empty($libro["fecha_inicio"])) {
                $sql = "UPDATE listas_lectura 
                        SET estado = ?, fecha_inicio = ?, fecha_fin = NULL 
                        WHERE usuario_id = ? AND id = ?";
                $stmt = $this->db->pdo->prepare($sql);
                return $stmt->execute([$nuevo_estado, $hoy, $usuario_id, $id]);
            }

            $sql = "UPDATE listas_lectura 
                    SET estado = ?
                    WHERE usuario_id = ? AND id = ?";
            $stmt = $this->db->pdo->prepare($sql);
            return $stmt->execute([$nuevo_estado, $usuario_id, $id]);
        }

        if ($nuevo_estado === "leido") {
            $fechaFin = !empty($libro["fecha_fin"]) ? $libro["fecha_fin"] : $hoy;
            $sql = "UPDATE listas_lectura 
                    SET estado = ?, fecha_fin = ?, progreso = 100,
                        paginas_leidas = CASE WHEN paginas_totales > 0 THEN paginas_totales ELSE paginas_leidas END
                    WHERE usuario_id = ? AND id = ?";
            $stmt = $this->db->pdo->prepare($sql);
            return $stmt->execute([$nuevo_estado, $fechaFin, $usuario_id, $id]);
        }

        if ($nuevo_estado === "guardado" || $nuevo_estado === "tbr") {
            $sql = "UPDATE listas_lectura 
                    SET estado = ?, fecha_inicio = NULL, fecha_fin = NULL, progreso = 0, paginas_leidas = 0 
                    WHERE usuario_id = ? AND id = ?";
            $stmt = $this->db->pdo->prepare($sql);
            return $stmt->execute([$nuevo_estado, $usuario_id, $id]);
        }

        // abandonado y cualquier otro estado
        $sql = "UPDATE listas_lectura 
                SET estado = ?
                WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$nuevo_estado, $usuario_id, $id]);
    }

    public function eliminarLibro($usuario_id, $id) {
        $sql = "DELETE FROM listas_lectura 
                WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$usuario_id, $id]);
    }

    public function actualizarProgreso($usuario_id, $id, $progreso) {
        $progreso = max(0, min(100, (int)$progreso));

        $sql = "UPDATE listas_lectura 
                SET progreso = ?,
                    paginas_leidas = CASE WHEN paginas_totales > 0 
                                          THEN ROUND(paginas_totales * ? / 100) 
                                          ELSE paginas_leidas END
                WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$progreso, $progreso, $usuario_id, $id]);
    }

    public function actualizarFechas($usuario_id, $id, $inicio, $fin) {
        $sql = "UPDATE listas_lectura 
                SET fecha_inicio = ?, fecha_fin = ?
                WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$inicio, $fin, $usuario_id, $id]);
    }

    public function actualizarPaginas($usuario_id, $id, $paginas_totales, $paginas_leidas) {
        $paginas_totales = max(0, (int)$paginas_totales);
        $paginas_leidas  = max(0, (int)$paginas_leidas);
        if ($paginas_totales > 0 && $paginas_leidas > $paginas_totales) {
            $paginas_leidas = $paginas_totales;
        }

        $porcentaje = 0;
        if ($paginas_totales > 0) {
            $porcentaje = round(($paginas_leidas / $paginas_totales) * 100);
        }

        $sql = "UPDATE listas_lectura 
                SET paginas_totales = ?, paginas_leidas = ?, progreso = ?
                WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$paginas_totales, $paginas_leidas, $porcentaje, $usuario_id, $id]);
    }

    public function actualizarEstrellas($usuario_id, $id, $estrellas) {
        $sql = "UPDATE listas_lectura 
                SET estrellas = ?
                WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$estrellas, $usuario_id, $id]);
    }

    public function actualizarPaginasLeidas($usuario_id, $libro_id, $paginas) {
        $paginas = max(0, (int)$paginas);
        $sql = "UPDATE listas_lectura SET paginas_leidas = ? WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$paginas, $usuario_id, $libro_id]);
    }

    public function actualizarReseña($usuario_id, $libro_id, $reseñas) {
        $sql = "UPDATE listas_lectura SET reseña_personal = ? WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$reseñas, $usuario_id, $libro_id]);
    }

    public function actualizarMotivoAbandono($usuario_id, $libro_id, $motivo) {
        $sql = "UPDATE listas_lectura SET motivo_abandono = ? WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$motivo, $usuario_id, $libro_id]);
    }

    public function actualizarFechaFin($usuario_id, $libro_id, $fecha) {
        $sql = "UPDATE listas_lectura SET fecha_fin = ? WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$fecha, $usuario_id, $libro_id]);
    }
}