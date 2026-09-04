<?php

require_once "Database.php";

class ReviewService {

    private $db;

    public function __construct() {
        $this->db = new Database();
    }

    // Guardar reseña
    public function guardarReseña($usuarioId, $libroId, $contenido) {

        // Comprobar si ya existe una reseña del usuario para ese libro
        $sql = "SELECT id FROM reseñas WHERE usuario_id = ? AND libro_id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$usuarioId, $libroId]);

        if ($stmt->fetch()) {
            return "Ya has escrito una reseña para este libro.";
        }

        // Insertar reseña
        $sql = "INSERT INTO reseñas (usuario_id, libro_id, contenido) VALUES (?, ?, ?)";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$usuarioId, $libroId, $contenido]);

        return true;
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
