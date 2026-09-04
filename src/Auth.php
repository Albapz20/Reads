<?php

class Auth {

    private static function iniciarSesionSiNoExiste() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function iniciarSesion($usuario) {
        self::iniciarSesionSiNoExiste();
        $_SESSION['usuario'] = [
            'id' => $usuario['id'],
            'nombre' => $usuario['nombre'],
            'email' => $usuario['email'],
            'tema_visual' => $usuario['tema_visual'] ?? 'default' // <-- AÑADIDO
        ];
    }

    // Nuevo método para actualizar el tema en la sesión activa al instante
    public static function actualizarTema($nuevoTema) {
        self::iniciarSesionSiNoExiste();
        if (isset($_SESSION['usuario'])) {
            $_SESSION['usuario']['tema_visual'] = $nuevoTema;
        }
    }

    public static function cerrarSesion() {
        self::iniciarSesionSiNoExiste();
        session_destroy();
    }

    public static function usuario() {
        self::iniciarSesionSiNoExiste();
        return $_SESSION['usuario'] ?? null;
    }

    public static function requiereLogin() {
        self::iniciarSesionSiNoExiste();
        if (!isset($_SESSION['usuario'])) {
            header("Location: login.php");
            exit;
        }
    }
}