<?php

require_once "Database.php";

class AjustesService {

    private $db;

    public function __construct() {
        $this->db = new Database();
    }

    // Crear ajustes por defecto si no existen
    public function crearAjustesSiNoExisten($usuario_id) {

        $sql = "SELECT id FROM ajustes_usuario WHERE usuario_id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$usuario_id]);

        if (!$stmt->fetch()) {

            // Crear ajustes por defecto
            $sqlInsert = "INSERT INTO ajustes_usuario 
                          (usuario_id, mostrar_email, mostrar_listas, objetivo_anual)
                          VALUES (?, 0, 1, 50)";

            $stmtInsert = $this->db->pdo->prepare($sqlInsert);
            $stmtInsert->execute([$usuario_id]);
        }
    }

    // Obtener ajustes del usuario
    public function obtenerAjustes($usuario_id) {

        $sql = "SELECT mostrar_email, mostrar_listas, objetivo_anual
                FROM ajustes_usuario
                WHERE usuario_id = ?";

        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$usuario_id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Actualizar ajustes
    public function actualizarAjustes($usuario_id, $mostrar_email, $mostrar_listas, $objetivo_anual)
    {
        $sql = "UPDATE ajustes_usuario 
                SET mostrar_email = ?, mostrar_listas = ?, objetivo_anual = ?
                WHERE usuario_id = ?";

        $stmt = $this->db->pdo->prepare($sql);

        return $stmt->execute([
            $mostrar_email,
            $mostrar_listas,
            $objetivo_anual,
            $usuario_id
        ]);
    }
}
