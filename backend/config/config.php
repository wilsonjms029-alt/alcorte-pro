<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = "127.0.0.1";
$user = "root";
$pass = "";
$db   = "barberia_db";

try {
    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error);
    }
} catch (Exception $e) {
    die("<div style='background:#fee2e2;color:#991b1b;padding:20px;font-family:sans-serif;border-radius:8px;margin:20px'>
        <strong>Error de Base de Datos:</strong> " . $e->getMessage() . "
        <br><small>Verifica que MySQL esté corriendo en XAMPP y que exista la base de datos <b>barberia_db</b>.</small>
    </div>");
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function csrf_generate(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_validate(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function csrf_regenerate(): void {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
