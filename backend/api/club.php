<?php
require_once '../config/config.php';
header('Content-Type: application/json');

$telefono = trim($_GET['telefono'] ?? '');
if (!$telefono) { echo json_encode(['encontrado' => false]); exit; }

$stmt = $conn->prepare("SELECT nombre, puntos, ultima_visita FROM clientes WHERE telefono = ?");
$stmt->bind_param("s", $telefono);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo $row
    ? json_encode(['encontrado' => true, 'nombre' => $row['nombre'], 'puntos' => (int)$row['puntos'], 'ultima_visita' => $row['ultima_visita']])
    : json_encode(['encontrado' => false]);
?>
