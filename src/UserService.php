<?php

require_once "Database.php";

class UserService {

    private $db;

    public function __construct() {
        // Tu clase Database usa PDO, así que aquí guardamos el objeto PDO
        $this->db = new Database();
    }

    /* ============================
       REGISTRAR USUARIO
       ============================ */
    public function registrar($nombre, $email, $password) {

        // Comprobar si el email ya existe
        $sql = "SELECT id FROM usuarios WHERE email = ?";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            return "El email ya está registrado.";
        }

        // Registrar usuario
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO usuarios (nombre, email, password) VALUES (?, ?, ?)";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$nombre, $email, $passwordHash]);

        return true;
    }

    /* ============================
       LOGIN
       ============================ */
    public function login($email, $password) {

        $sql = "SELECT * FROM usuarios WHERE email = ?";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$email]);

        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            return "El email no existe.";
        }

        if (!password_verify($password, $usuario['password'])) {
            return "La contraseña es incorrecta.";
        }

        return $usuario;
    }

    /* ============================
       OBTENER USUARIO POR ID
       ============================ */
    public function obtenerUsuarioPorId($id) {

        $sql = "SELECT * FROM usuarios WHERE id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /* ============================
       ACTUALIZAR DATOS DEL USUARIO
       ============================ */
    public function actualizarUsuario($id, $nombre, $email, $privacidad, $tema_visual) {
    $sql = "UPDATE usuarios 
            SET nombre = ?, email = ?, privacidad = ?, tema_visual = ?
            WHERE id = ?";

        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$nombre, $email, $privacidad, $tema, $id]);
    }

    /* ============================
       CAMBIAR CONTRASEÑA
       ============================ */
    public function cambiarContraseña($id, $nuevaContraseña) {

        $passwordHash = password_hash($nuevaContraseña, PASSWORD_DEFAULT);

        $sql = "UPDATE usuarios 
                SET password = ?
                WHERE id = ?";

        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$passwordHash, $id]);
    }
    public function generarLomoDesdePortada($rutaPortada, $titulo, $idLibro) {

    // Si no existe la portada, no hacemos nada
    if (!file_exists($rutaPortada)) {
        return null;
    }

    // Cargar imagen original
    $img = imagecreatefromjpeg($rutaPortada);

    $ancho = imagesx($img);
    $alto = imagesy($img);

    // Recortar una franja vertical del centro
    $franja = imagecreatetruecolor(70, $alto);
    imagecopyresampled(
        $franja, $img,
        0, 0,
        $ancho / 2 - 35, 0,
        70, $alto,
        70, $alto
    );

    // Añadir degradado naturalista
    $overlay = imagecreatetruecolor(70, $alto);
    $color = imagecolorallocatealpha($overlay, 50, 70, 50, 60);
    imagefilledrectangle($overlay, 0, 0, 70, $alto, $color);
    imagecopymerge($franja, $overlay, 0, 0, 0, 0, 70, $alto, 40);

    // Añadir título en vertical
    $colorTexto = imagecolorallocate($franja, 255, 255, 255);
    $fuente = __DIR__ . "/../fonts/Georgia.ttf"; // usa tu fuente
    imagettftext($franja, 14, 90, 10, $alto - 10, $colorTexto, $fuente, $titulo);

    // Guardar lomo
    $rutaLomo = "../uploads/lomos/lomo_$idLibro.jpg";
    imagejpeg($franja, $rutaLomo, 90);

    return $rutaLomo;
}

}
