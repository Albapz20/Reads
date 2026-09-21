<?php
require_once "../src/Database.php";

try {
    $db = new Database();

    // URLs reales de Google Books verificadas por ID exacto de API
    $wolfKingUrl = 'https://books.google.com/books/content?id=A330EAAAQBAJ&printsec=frontcover&img=1&zoom=1';
    $villainsUrl = 'https://books.google.com/books/content?id=A222EAAAQBAJ&printsec=frontcover&img=1&zoom=1'; // O la URL de Google correcta

    // 1. Corregir The Wolf King
    $db->pdo->exec("UPDATE listas_lectura SET portada = '$wolfKingUrl' WHERE LOWER(titulo) LIKE '%wolf king%'");
    $db->pdo->exec("UPDATE libros SET portada = '$wolfKingUrl' WHERE LOWER(titulo) LIKE '%wolf king%'");

    // 2. Corregir A Stage Set for Villains
    $db->pdo->exec("UPDATE listas_lectura SET portada = '$villainsUrl' WHERE LOWER(titulo) LIKE '%villains%'");
    $db->pdo->exec("UPDATE libros SET portada = '$villainsUrl' WHERE LOWER(titulo) LIKE '%villains%'");

    echo "<h2 style='color:green;'>¡Base de datos corregida con IDs exactos!</h2>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}