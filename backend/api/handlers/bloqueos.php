<?php
declare(strict_types=1);

$suc_id = store_id_from_token($conn, $_GET['t'] ?? '');
$barbero_id = (int) ($_GET['barbero_id'] ?? 0);
$fecha = trim($_GET['fecha'] ?? '');

if (!$suc_id || !$barbero_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    api_ok(['bloqueos' => []]);
}

$chk = $conn->prepare('SELECT id FROM barberos WHERE id = ? AND sucursal_id = ? AND activo = 1 LIMIT 1');
$chk->bind_param('ii', $barbero_id, $suc_id);
$chk->execute();
if (!$chk->get_result()->fetch_assoc()) {
    $chk->close();
    api_ok(['bloqueos' => []]);
}
$chk->close();

$stmt = $conn->prepare(
    'SELECT id, hora_inicio, hora_fin, motivo, dia_completo
     FROM bloqueos_horario
     WHERE barbero_id = ? AND fecha = ? AND sucursal_id = ?'
);
$stmt->bind_param('isi', $barbero_id, $fecha, $suc_id);
$stmt->execute();
$res = $stmt->get_result();

$bloqueos = [];
while ($row = $res->fetch_assoc()) {
    $bloqueos[] = $row;
}
$stmt->close();

api_ok(['bloqueos' => $bloqueos]);
