<?php

require_once "Database.php";

class ReviewService {

    private $db;

    public function __construct() {
        $this->db = new Database();
    }

    // Guarda la reseña del usuario: la crea si no existe, la edita si ya existe
    // y la borra si llega vacía. Devuelve true (o un mensaje de error).
    public function guardarReseña($usuarioId, $libroId, $contenido) {
        $libroId   = trim((string)$libroId);
        $contenido = trim((string)$contenido);

        if ($libroId === '') {
            return "Libro no válido.";
        }

        $stmt = $this->db->pdo->prepare("SELECT id FROM reseñas WHERE usuario_id = ? AND libro_id = ? LIMIT 1");
        $stmt->execute([$usuarioId, $libroId]);
        $existente = $stmt->fetchColumn();

        if ($contenido === '') {
            if ($existente) {
                $this->db->pdo->prepare("DELETE FROM reseñas WHERE id = ?")->execute([$existente]);
            }
        } elseif ($existente) {
            $this->db->pdo->prepare("UPDATE reseñas SET contenido = ?, fecha = NOW() WHERE id = ?")
                      ->execute([$contenido, $existente]);
        } else {
            $this->db->pdo->prepare("INSERT INTO reseñas (usuario_id, libro_id, contenido) VALUES (?, ?, ?)")
                      ->execute([$usuarioId, $libroId, $contenido]);
        }
    
        try {
            $this->db->pdo->prepare(
                "UPDATE listas_lectura SET reseña_personal = ?
                 WHERE usuario_id = ? AND (libro_id = ? OR ((libro_id IS NULL OR libro_id = '') AND id = ?))"
            )->execute([$contenido === '' ? null : $contenido, $usuarioId, $libroId, $libroId]);
        } catch (Throwable $e) {}

        return true;
    }

    // Reseña del propio usuario para un libro (cadena vacía si no hay)
    public function obtenerReseñaUsuario($usuarioId, $libroId) {
        $stmt = $this->db->pdo->prepare("SELECT contenido FROM reseñas WHERE usuario_id = ? AND libro_id = ? LIMIT 1");
        $stmt->execute([$usuarioId, (string)$libroId]);
        $c = $stmt->fetchColumn();
        return $c === false ? '' : (string)$c;
    }

    // Todas las reseñas del usuario, indexadas por libro_id 
    public function obtenerReseñasUsuario($usuarioId) {
        $stmt = $this->db->pdo->prepare("SELECT libro_id, contenido FROM reseñas WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);
        $mapa = [];
        foreach ($stmt->fetchAll() as $r) {
            $mapa[(string)$r['libro_id']] = $r['contenido'];
        }
        return $mapa;
    }

    // Obtener reseñas de un libro
    public function obtenerReseñas($libroId) {
        $sql = "SELECT r.contenido, u.nombre 
                FROM reseñas r 
                JOIN usuarios u ON r.usuario_id = u.id
                WHERE r.libro_id = ?
                ORDER BY r.id DESC";

        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$libroId]);

        return $stmt->fetchAll();
    }
}