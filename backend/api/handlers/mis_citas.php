<?php
declare(strict_types=1);

$suc_id = store_id_from_token($conn, $_GET['t'] ?? '');
if (!$suc_id) {
    api_ok(['citas' => [], 'pedidos' => []]);
}

$telefono = preg_replace('/\D/', '', trim($_GET['telefono'] ?? ''));
if (!$telefono || strlen($telefono) < 7) {
    api_ok(['citas' => [], 'pedidos' => []]);
}

$stmt = $conn->prepare(
    'SELECT c.id, c.servicio, c.fecha, c.hora, c.estado, c.estado_pago, b.nombre AS barbero
     FROM citas c
     JOIN barberos b ON b.id = c.barbero_id
     WHERE c.cliente_telefono = ? AND c.sucursal_id = ?
     ORDER BY c.fecha DESC, c.hora DESC
     LIMIT 20'
);
$stmt->bind_param('si', $telefono, $suc_id);
$stmt->execute();
$res = $stmt->get_result();

$citas = [];
while ($row = $res->fetch_assoc()) {
    $citas[] = $row;
}
$stmt->close();

$pedidos = [];
$stmt2 = $conn->prepare(
    'SELECT p.id, p.metodo_pago, p.referencia_pago, p.estado_pago, p.estado, p.total, p.fecha
     FROM pedidos p
     WHERE p.cliente_telefono = ? AND p.sucursal_id = ?
     ORDER BY p.id DESC
     LIMIT 20'
);
$stmt2->bind_param('si', $telefono, $suc_id);
$stmt2->execute();
$res2 = $stmt2->get_result();

while ($row2 = $res2->fetch_assoc()) {
    $ped_id = (int) $row2['id'];
    $stmt3 = $conn->prepare('SELECT nombre_producto, cantidad, precio_unitario FROM pedido_detalles WHERE pedido_id = ?');
    $stmt3->bind_param('i', $ped_id);
    $stmt3->execute();
    $res3 = $stmt3->get_result();

    $articulos = [];
    while ($row3 = $res3->fetch_assoc()) {
        $articulos[] = $row3;
    }
    $stmt3->close();

    $row2['articulos'] = $articulos;
    $pedidos[] = $row2;
}
$stmt2->close();

api_ok(['citas' => $citas, 'pedidos' => $pedidos]);
