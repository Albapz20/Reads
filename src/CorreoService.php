<?php
// src/CorreoService.php

// Se comprobará si los archivos de PHPMailer existen antes de intentar cargarlos
$phpmailerDir = __DIR__ . '/PHPMailer/';

if (
    file_exists($phpmailerDir . 'Exception.php') &&
    file_exists($phpmailerDir . 'PHPMailer.php') &&
    file_exists($phpmailerDir . 'SMTP.php')
) {
    require_once $phpmailerDir . 'Exception.php';
    require_once $phpmailerDir . 'PHPMailer.php';
    require_once $phpmailerDir . 'SMTP.php';
} elseif (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    // Soporte alternativo si usaste Composer
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Importar las clases necesarias de PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class CorreoService {
    private $host = 'smtp.gmail.com'; 
    private $port = 587;
    private $username = 'albaperezcc64@gmail.com'; 
    private $password = 'tu_contraseña_de_aplicacion'; // Recuerda generar esta clave desde tu cuenta Google

    public function enviarContacto($emailUsuario, $nombreUsuario, $asunto, $mensajeContacto) {
        
        // Evitar que la web colapse si faltan los archivos de PHPMailer
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            error_log("PHPMailer no está disponible. Comprueba la carpeta src/PHPMailer/");
            return false;
        }

        $mail = new PHPMailer(true);

        try {
            // Configuración del servidor
            $mail->isSMTP();
            $mail->Host       = $this->host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->username;
            $mail->Password   = $this->password;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $this->port;
            $mail->CharSet    = 'UTF-8';

            // Destinatarios y remitente
            $mail->setFrom($this->username, 'Reads App Soporte');
            $mail->addAddress($this->username); 
            $mail->addReplyTo($emailUsuario, $nombreUsuario); 

            // Contenido del correo
            $mail->isHTML(true);
            $mail->Subject = 'Soporte Reads: ' . $asunto;
            
            $mail->Body = "
                <h2>Nuevo mensaje de contacto en Reads</h2>
                <p><strong>Usuario:</strong> " . htmlspecialchars($nombreUsuario) . " (" . htmlspecialchars($emailUsuario) . ")</p>
                <p><strong>Asunto:</strong> " . htmlspecialchars($asunto) . "</p>
                <hr>
                <p><strong>Mensaje:</strong></p>
                <p>" . nl2br(htmlspecialchars($mensajeContacto)) . "</p>
            ";

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Error al enviar correo: " . $mail->ErrorInfo);
            return false;
        }
    }
}