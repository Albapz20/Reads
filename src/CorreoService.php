<?php
// src/CorreoService.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

class CorreoService {
    private $host = 'smtp.gmail.com'; // Servidor SMTP (ej. Gmail o el de tu hosting)
    private $port = 587;
    private $username = 'albaperezcc64@gmail.com'; // 👈 Tu correo donde recibirás los mensajes
    private $password = 'tu_contraseña_de_aplicacion'; // 👈 Tu contraseña de aplicación de 16 caracteres

    public function enviarContacto($emailUsuario, $nombreUsuario, $asunto, $mensajeContacto) {
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
            $mail->addAddress($this->username); // Te llega a ti como administrador
            $mail->addReplyTo($emailUsuario, $nombreUsuario); // Para responder directamente al usuario

            // Contenido del correo
            $mail->isHTML(true);
            $mail->Subject = 'Soporte Reads: ' . $asunto;
            
            $mail->Body = "
                <h2>Nuevo mensaje de contacto en Reads</h2>
                <p><strong>Usuario:</strong> {$nombreUsuario} ({$emailUsuario})</p>
                <p><strong>Asunto:</strong> {$asunto}</p>
                <hr>
                <p><strong>Mensaje:</strong></p>
                <p>" . nl2br(htmlspecialchars($mensajeContacto)) . "</p>
            ";

            $mail->send();
            return true;
        } catch (Exception $e) {
            // Se puede registrar el error en un archivo log si fuera necesario
            return false;
        }
    }
}