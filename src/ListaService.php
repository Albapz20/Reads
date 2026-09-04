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

    // Añadir libro a una lista
    public function agregarLibro($usuario_id, $libro_id, $titulo, $portada, $estado) {

        // Si ya existe, actualizamos el estado
        $sqlCheck = "SELECT id FROM listas_lectura WHERE usuario_id = ? AND (libro_id = ? OR titulo = ?)";
        $stmtCheck = $this->db->pdo->prepare($sqlCheck);
        $stmtCheck->execute([$usuario_id, $libro_id, $titulo]);
        $existente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($existente) {
            $sqlUpdate = "UPDATE listas_lectura 
                          SET estado = ?, fecha = NOW()
                          WHERE id = ?";
            $stmtUpdate = $this->db->pdo->prepare($sqlUpdate);
            return $stmtUpdate->execute([$estado, $existente['id']]);
        }

        // Obtener datos desde Google Books API de forma segura (con cURL y fallback)
        $paginas_totales = 0;
        $autores = "";
        $descripcion = "";
        $categorias = "";
        $publicado = "";

        if (!empty($libro_id)) {
            $apiUrl = "https://www.googleapis.com/books/v1/volumes/" . urlencode($libro_id);
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_USERAGENT => 'Mozilla/5.0'
            ]);
            $json = curl_exec($ch);
            curl_close($ch);

            if ($json) {
                $data = json_decode($json, true);
                if (isset($data["volumeInfo"])) {
                    $info = $data["volumeInfo"];
                    $paginas_totales = $info["pageCount"] ?? 0;
                    $autores = isset($info["authors"]) ? implode(", ", $info["authors"]) : "";
                    $descripcion = $info["description"] ?? "";
                    $categorias = isset($info["categories"]) ? implode(", ", $info["categories"]) : "";
                    $publicado = $info["publishedDate"] ?? "";
                }
            }
        }

        // Insertar libro
        $sqlInsert = "INSERT INTO listas_lectura 
                      (usuario_id, libro_id, titulo, portada, estado, fecha,
                       paginas_totales, paginas_leidas, autores, descripcion, categorias, publicado)
                      VALUES (?, ?, ?, ?, ?, NOW(), ?, 0, ?, ?, ?, ?)";

        $stmtInsert = $this->db->pdo->prepare($sqlInsert);
        return $stmtInsert->execute([
            $usuario_id,
            $libro_id,
            $titulo,
            $portada,
            $estado,
            $paginas_totales,
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
            $sql = "UPDATE listas_lectura 
                    SET estado = ?, fecha_fin = ?, progreso = 100, paginas_leidas = paginas_totales 
                    WHERE usuario_id = ? AND id = ?";
            $stmt = $this->db->pdo->prepare($sql);
            return $stmt->execute([$nuevo_estado, $hoy, $usuario_id, $id]);
        }

        if ($nuevo_estado === "guardado" || $nuevo_estado === "tbr") {
            $sql = "UPDATE listas_lectura 
                    SET estado = ?, fecha_inicio = NULL, fecha_fin = NULL, progreso = 0 
                    WHERE usuario_id = ? AND id = ?";
            $stmt = $this->db->pdo->prepare($sql);
            return $stmt->execute([$nuevo_estado, $usuario_id, $id]);
        }

        if ($nuevo_estado === "abandonado") {
            $sql = "UPDATE listas_lectura 
                    SET estado = ?
                    WHERE usuario_id = ? AND id = ?";
            $stmt = $this->db->pdo->prepare($sql);
            return $stmt->execute([$nuevo_estado, $usuario_id, $id]);
        }

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
        $sql = "UPDATE listas_lectura 
                SET progreso = ?
                WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$progreso, $usuario_id, $id]);
    }

    public function actualizarFechas($usuario_id, $id, $inicio, $fin) {
        $sql = "UPDATE listas_lectura 
                SET fecha_inicio = ?, fecha_fin = ?
                WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$inicio, $fin, $usuario_id, $id]);
    }

    public function actualizarPaginas($usuario_id, $id, $paginas_totales, $paginas_leidas) {
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

    public function guardarReseñaPersonal($usuario_id, $id, $texto) {
        $sql = "UPDATE listas_lectura 
                SET reseña_personal = ?
                WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$texto, $usuario_id, $id]);
    }

    public function actualizarEstrellas($usuario_id, $id, $estrellas) {
        $sql = "UPDATE listas_lectura 
                SET estrellas = ?
                WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$estrellas, $usuario_id, $id]);
    }

    public function actualizarPaginasLeidas($usuario_id, $libro_id, $paginas) {
        $sql = "UPDATE listas_lectura SET paginas_leidas = ? WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$paginas, $usuario_id, $libro_id]);
    }

    public function actualizarReseña($usuario_id, $libro_id, $reseñas) {
        $sql = "UPDATE listas_lectura SET reseña_personal = ? WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$reseñas, $usuario_id, $libro_id]);
    }

    public function actualizarMotivoAbandono($usuario_id, $libro_id, $motivo) {
        $sql = "UPDATE listas_lectura SET motivo_abandono = ? WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$motivo, $usuario_id, $libro_id]);
    }

    public function actualizarFechaFin($usuario_id, $libro_id, $fecha) {
        $sql = "UPDATE listas_lectura SET fecha_fin = ? WHERE usuario_id = ? AND id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$fecha, $usuario_id, $libro_id]);
    }
}