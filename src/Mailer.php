<?php
require_once __DIR__ . "/PHPMailer/Exception.php";
require_once __DIR__ . "/PHPMailer/PHPMailer.php";
require_once __DIR__ . "/PHPMailer/SMTP.php";

if (!defined('APP_BASE') && file_exists(__DIR__ . '/../config/config.php')) {
    require_once __DIR__ . '/../config/config.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    public static string $ultimoError = '';

    public static function enviarVerificacion($email, $nombre, $token): bool
    {
        self::$ultimoError = '';
        $config = require __DIR__ . "/../config/config_mail.php";

        $esquema = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
        $base    = defined('APP_BASE') ? rtrim(APP_BASE, '/') : '';
        $enlace  = $esquema . "://" . $_SERVER["HTTP_HOST"] . $base . "/public/verificar.php?token=" . urlencode($token);
        $nombreSeguro = htmlspecialchars($nombre);

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $config["host"];
            $mail->SMTPAuth   = true;
            $mail->Username   = trim($config["usuario"]);
            $mail->Password   = str_replace(' ', '', $config["password"]);
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $config["puerto"];
            $mail->CharSet    = "UTF-8";
            $mail->Timeout    = 15;

            $mail->setFrom($config["usuario"], "Reads");
            $mail->addAddress($email, $nombre);

            $mail->isHTML(true);
            $mail->Subject = "Confirma tu cuenta en Reads";
            $mail->Body    = "<p>Hola <strong>{$nombreSeguro}</strong>,</p>"
                           . "<p>Gracias por registrarte en Reads. Confirma tu cuenta pulsando aquí (el enlace caduca en 24 horas):</p>"
                           . "<p><a href=\"{$enlace}\">Confirmar mi cuenta</a></p>"
                           . "<p>Si no has sido tú, ignora este mensaje.</p>";
            $mail->AltBody = "Hola {$nombre}, confirma tu cuenta en Reads (caduca en 24 horas): {$enlace}";

            $mail->send();
            return true;
        } catch (Exception $e) {
            self::$ultimoError = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
            error_log("Error enviando email de verificación: " . self::$ultimoError);
            return false;
        }
    }
}