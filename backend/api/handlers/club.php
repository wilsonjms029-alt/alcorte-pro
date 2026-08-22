<?php
declare(strict_types=1);

$suc_id = store_id_from_token($conn, $_GET['t'] ?? '');
if (!$suc_id) {
    api_ok(['encontrado' => false]);
}

$telefono = preg_replace('/\D/', '', trim($_GET['telefono'] ?? ''));
if (!$telefono || strlen($telefono) < 7) {
    api_ok(['encontrado' => false]);
}

$stmt = $conn->prepare(
    'SELECT c.nombre, c.puntos, c.ultima_visita
     FROM clientes c
     WHERE c.telefono = ?
       AND (
           EXISTS (SELECT 1 FROM citas WHERE cliente_telefono = c.telefono AND sucursal_id = ? LIMIT 1)
           OR EXISTS (SELECT 1 FROM pedidos WHERE cliente_telefono = c.telefono AND sucursal_id = ? LIMIT 1)
       )
     LIMIT 1'
);
$stmt->bind_param('sii', $telefono, $suc_id, $suc_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row) {
    api_ok([
        'encontrado' => true,
        'nombre' => $row['nombre'],
        'puntos' => (int) $row['puntos'],
        'ultima_visita' => $row['ultima_visita'],
    ]);
}

api_ok(['encontrado' => false]);
