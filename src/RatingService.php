<?php

require_once "Database.php";

class RatingService {

    private $db;

    public function __construct() {
        $this->db = new Database();
    }

    // Guardar o actualizar puntuación estilo Mistbook
    public function guardarPuntuacion($usuarioId, $libroId, $estrellas, $romance, $spicy, $lagrimas = 0, $plotTwist = 0) {

        // Comprobar si ya existe una puntuación
        $sql = "SELECT id FROM puntuaciones WHERE usuario_id = ? AND libro_id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$usuarioId, $libroId]);

        if ($stmt->fetch()) {
            // Actualizar
            $sql = "UPDATE puntuaciones 
                    SET estrellas = ?, romance = ?, spicy = ?, lagrimas = ?, plot_twist = ?
                    WHERE usuario_id = ? AND libro_id = ?";
            $stmt = $this->db->pdo->prepare($sql);
            $stmt->execute([$estrellas, $romance, $spicy, $lagrimas, $plotTwist, $usuarioId, $libroId]);
        } else {
            // Insertar
            $sql = "INSERT INTO puntuaciones (usuario_id, libro_id, estrellas, romance, spicy, lagrimas, plot_twist)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $this->db->pdo->prepare($sql);
            $stmt->execute([$usuarioId, $libroId, $estrellas, $romance, $spicy, $lagrimas, $plotTwist]);
        }

        return true;
    }

    // Obtener puntuación del usuario
    public function obtenerPuntuacionUsuario($usuarioId, $libroId) {
        $sql = "SELECT * FROM puntuaciones WHERE usuario_id = ? AND libro_id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$usuarioId, $libroId]);
        return $stmt->fetch();
    }

    // Obtener medias globales (ampliado con todas las métricas)
    public function obtenerMedias($libroId) {
        $sql = "SELECT 
                    AVG(estrellas) AS media_estrellas,
                    AVG(romance) AS media_romance,
                    AVG(spicy) AS media_spicy,
                    AVG(lagrimas) AS media_lagrimas,
                    AVG(plot_twist) AS media_plot_twist
                FROM puntuaciones
                WHERE libro_id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$libroId]);
        return $stmt->fetch();
    }
}