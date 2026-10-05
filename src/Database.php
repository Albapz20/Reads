<?php

require_once __DIR__ . '/../config/config.php';

class Database {

    private $host;
    private $db;
    private $user;
    private $pass;
    private $charset = "utf8mb4";

    public $pdo;

    public function __construct() {
        // Los datos de conexión vienen de config/config.local.php
        $this->host = config('db_host', 'localhost');
        $this->db   = config('db_name', 'Reads');
        $this->user = config('db_user', 'root');
        $this->pass = config('db_pass', '');

        try {
            $dsn = "mysql:host={$this->host};dbname={$this->db};charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $this->pdo = new PDO($dsn, $this->user, $this->pass, $options);

        } catch (PDOException $e) {
            die("Error de conexión: " . $e->getMessage());
        }
    }

    /* Funciones para actualizar y guardar libros */
    public function actualizarDescripcionLibro($id_externo, $descripcion) {
        $sql = "UPDATE libros SET descripcion = ? WHERE id_externo = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$descripcion, $id_externo]);
    }

    public function guardarLibro($id_externo, $fuente_api, $titulo, $autor, $portada, $descripcion, $idioma) {
        $sql = "INSERT INTO libros (id_externo, fuente_api, titulo, autor, portada, descripcion, idioma)
                VALUES (?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$id_externo, $fuente_api, $titulo, $autor, $portada, $descripcion, $idioma]);
    }

    public function obtenerLibroPorIdExterno($id_externo) {
        $sql = "SELECT * FROM libros WHERE id_externo = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id_externo]);
        return $stmt->fetch();
    }

}