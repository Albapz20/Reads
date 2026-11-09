<?php
// src/CorreoService.php

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
    // Soporte alternativo 
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Para poder leer config.local.php con la función config()
if (!function_exists('config') && file_exists(__DIR__ . '/helpers.php')) {
    require_once __DIR__ . '/helpers.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class CorreoService {
    private string $host = 'smtp.gmail.com';
    private int $port = 587;
    private string $username;
    private string $password;

    /** Motivo del último fallo (se puede mostrar al administrador) */
    public string $ultimoError = '';

    public function __construct() {
        $this->username = function_exists('config') ? trim((string)config('smtp_usuario', '')) : '';
        $this->password = function_exists('config') ? (string)config('smtp_password', '') : '';
        $this->password = str_replace(' ', '', $this->password);
    }

    public function enviarContacto($emailUsuario, $nombreUsuario, $asunto, $mensajeContacto): bool {
        $this->ultimoError = '';

        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $this->ultimoError = 'PHPMailer no encontrado: revisa que Exception.php, PHPMailer.php y SMTP.php están directamente en src/PHPMailer/ (no en una subcarpeta).';
            error_log($this->ultimoError);
            return false;
        }

        if ($this->username === '' || $this->password === '') {
            $this->ultimoError = 'Faltan smtp_usuario o smtp_password: la función config() no los está leyendo de config.local.php.';
            error_log($this->ultimoError);
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
            $mail->Timeout    = 15;

            // Gmail exige que el remitente sea la propia cuenta
            $mail->setFrom($this->username, 'Reads App Soporte');
            $mail->addAddress($this->username);

            // Para poder responder directamente al usuario (solo si su correo es válido)
            if (filter_var($emailUsuario, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($emailUsuario, (string)$nombreUsuario);
            }

            // Contenido del correo
            $mail->isHTML(true);
            $mail->Subject = 'Soporte Reads: ' . $asunto;

            $mail->Body = "
                <h2>Nuevo mensaje de contacto en Reads</h2>
                <p><strong>Usuario:</strong> " . htmlspecialchars((string)$nombreUsuario) . " (" . htmlspecialchars((string)$emailUsuario) . ")</p>
                <p><strong>Asunto:</strong> " . htmlspecialchars((string)$asunto) . "</p>
                <hr>
                <p><strong>Mensaje:</strong></p>
                <p>" . nl2br(htmlspecialchars((string)$mensajeContacto)) . "</p>
            ";
            $mail->AltBody = "Usuario: $nombreUsuario ($emailUsuario)\nAsunto: $asunto\n\n$mensajeContacto";

            $mail->send();
            return true;
        } catch (Exception $e) {
            $this->ultimoError = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
            error_log("Error al enviar correo: " . $this->ultimoError);
            return false;
        }
    }
        /** Avisa al usuario de que su contraseña ha cambiado (nunca incluye la contraseña) */
    public function enviarAvisoPassword($emailUsuario, $nombreUsuario): bool {
        $this->ultimoError = '';

        if (!filter_var($emailUsuario, FILTER_VALIDATE_EMAIL)) {
            $this->ultimoError = 'El correo del usuario no es válido.';
            return false;
        }
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $this->ultimoError = 'PHPMailer no encontrado.';
            error_log($this->ultimoError);
            return false;
        }
        if ($this->username === '' || $this->password === '') {
            $this->ultimoError = 'Faltan smtp_usuario o smtp_password.';
            error_log($this->ultimoError);
            return false;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $this->host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->username;
            $mail->Password   = $this->password;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $this->port;
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 10;

            $mail->setFrom($this->username, 'Reads App');
            $mail->addAddress($emailUsuario, (string)$nombreUsuario);

            $nombre = htmlspecialchars((string)$nombreUsuario);
            $fecha  = date('d/m/Y H:i');

            $mail->isHTML(true);
            $mail->Subject = 'Tu contraseña de Reads ha cambiado';
            $mail->Body = "
                <h2>Contraseña actualizada</h2>
                <p>Hola" . ($nombre !== '' ? " $nombre" : '') . ",</p>
                <p>Te avisamos de que la contraseña de tu cuenta de Reads se cambió el <strong>$fecha</strong>.</p>
                <p>Si has sido tú, no tienes que hacer nada.</p>
                <p>Si <strong>no</strong> has sido tú, escríbenos cuanto antes desde la sección de contacto de Ajustes o respondiendo a este correo.</p>
            ";
            $mail->AltBody = "Hola $nombreUsuario,\n\nLa contraseña de tu cuenta de Reads se cambió el $fecha.\n"
                           . "Si has sido tú, no tienes que hacer nada. Si no has sido tú, escríbenos cuanto antes.";

            $mail->send();
            return true;
        } catch (Exception $e) {
            $this->ultimoError = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
            error_log("Error al enviar aviso de contraseña: " . $this->ultimoError);
            return false;
        }
    }
}