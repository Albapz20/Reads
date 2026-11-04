<?php

require_once "Database.php";

class UserService {

    private $db;

    public function __construct() {
        // Tu clase Database usa PDO, así que aquí guardamos el objeto PDO
        $this->db = new Database();
    }

    /* Registrar usuario (queda pendiente de verificar por email) */
    public function registrar($nombre, $email, $password) {

        // Comprobar si el email ya existe
        $sql = "SELECT id, verificado FROM usuarios WHERE email = ?";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$email]);
        $existente = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existente && (int)$existente["verificado"] === 1) {
            return "El email ya está registrado.";
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // El token en claro va por email; en la BD solo se guarda su hash
        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash("sha256", $token);

        if ($existente) {
            // Cuenta creada pero nunca confirmada: se actualiza y se genera un token nuevo
            $sql = "UPDATE usuarios
                       SET nombre = ?, password = ?, token_verificacion = ?,
                           token_expira = DATE_ADD(NOW(), INTERVAL 24 HOUR)
                     WHERE id = ?";
            $stmt = $this->db->pdo->prepare($sql);
            $stmt->execute([$nombre, $passwordHash, $tokenHash, $existente["id"]]);
        } else {
            $sql = "INSERT INTO usuarios (nombre, email, password, verificado, token_verificacion, token_expira)
                    VALUES (?, ?, ?, 0, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))";
            $stmt = $this->db->pdo->prepare($sql);
            $stmt->execute([$nombre, $email, $passwordHash, $tokenHash]);
        }

        return ["email" => $email, "token" => $token];
    }

    /* Confirmar email a partir del token recibido por correo */
    public function verificarEmail($token) {

        if (!preg_match('/^[a-f0-9]{64}$/', (string)$token)) {
            return false;
        }

        $sql = "UPDATE usuarios
                   SET verificado = 1, token_verificacion = NULL, token_expira = NULL
                 WHERE token_verificacion = ? AND token_expira > NOW() AND verificado = 0";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([hash("sha256", $token)]);

        return $stmt->rowCount() === 1;
    }

    /* Generar un token nuevo para reenviar el email de confirmación */
    public function regenerarToken($email) {

        $sql = "SELECT id, nombre FROM usuarios WHERE email = ? AND verificado = 0";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            return null;
        }

        $token = bin2hex(random_bytes(32));

        $sql = "UPDATE usuarios
                   SET token_verificacion = ?, token_expira = DATE_ADD(NOW(), INTERVAL 24 HOUR)
                 WHERE id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([hash("sha256", $token), $usuario["id"]]);

        return ["email" => $email, "nombre" => $usuario["nombre"], "token" => $token];
    }

    /* Login */
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

        // Se comprueba después de la contraseña para no revelar el estado a quien no la sabe
        if ((int)$usuario['verificado'] !== 1) {
            return "Debes confirmar tu correo antes de iniciar sesión. Revisa tu bandeja de entrada (y el spam).";
        }

        return $usuario;
    }

    /* Obtener usuario por ID */
    public function obtenerUsuarioPorId($id) {

        $sql = "SELECT * FROM usuarios WHERE id = ?";
        $stmt = $this->db->pdo->prepare($sql);
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /* Actualizar datos del usuario */
    public function actualizarUsuario($id, $nombre, $email, $privacidad, $tema_visual) {
        $sql = "UPDATE usuarios
                SET nombre = ?, email = ?, privacidad = ?, tema_visual = ?
                WHERE id = ?";

        $stmt = $this->db->pdo->prepare($sql);
        return $stmt->execute([$nombre, $email, $privacidad, $tema_visual, $id]);
    }

    /* Cambiar contraseña */
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