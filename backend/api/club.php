<?php
require_once '../config/config.php';
header('Content-Type: application/json');

$suc_id = store_id_from_token($conn, $_GET['t'] ?? '');
if (!$suc_id) {
    echo json_encode(['encontrado' => false]);
    exit;
}

$telefono = preg_replace('/\D/', '', trim($_GET['telefono'] ?? ''));
if (!$telefono || strlen($telefono) < 7) {
    echo json_encode(['encontrado' => false]);
    exit;
}

// Solo expone datos si el cliente tiene historial en esta tienda
$stmt = $conn->prepare(
    "SELECT c.nombre, c.puntos, c.ultima_visita
     FROM clientes c
     WHERE c.telefono = ?
       AND (
           EXISTS (SELECT 1 FROM citas WHERE cliente_telefono = c.telefono AND sucursal_id = ? LIMIT 1)
           OR EXISTS (SELECT 1 FROM pedidos WHERE cliente_telefono = c.telefono AND sucursal_id = ? LIMIT 1)
       )
     LIMIT 1"
);
$stmt->bind_param("sii", $telefono, $suc_id, $suc_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo $row
    ? json_encode([
        'encontrado' => true,
        'nombre' => $row['nombre'],
        'puntos' => (int) $row['puntos'],
        'ultima_visita' => $row['ultima_visita'],
    ])
    : json_encode(['encontrado' => false]);
