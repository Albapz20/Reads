<?php
require_once "../src/Auth.php";

Auth::cerrarSesion();
header("Location: login.php");
exit;
