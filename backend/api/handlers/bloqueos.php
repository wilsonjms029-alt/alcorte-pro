<?php
declare(strict_types=1);

$suc_id = store_id_from_token($conn, $_GET['t'] ?? '');
$barbero_id = (int) ($_GET['barbero_id'] ?? 0);
$fecha = trim($_GET['fecha'] ?? '');

if (!$suc_id || !$barbero_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    api_ok(['bloqueos' => [], 'citas' => [], 'horario' => null]);
}

$chk = $conn->prepare('SELECT id, hora_inicio, hora_fin, almuerzo_inicio, almuerzo_fin FROM barberos WHERE id = ? AND sucursal_id = ? AND activo = 1 LIMIT 1');
$chk->bind_param('ii', $barbero_id, $suc_id);
$chk->execute();
$bdata = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$bdata) {
    api_ok(['bloqueos' => [], 'citas' => [], 'horario' => null]);
}

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

// Citas activas ya reservadas
$stmt_c = $conn->prepare(
    'SELECT hora
     FROM citas
     WHERE barbero_id = ? AND fecha = ? AND sucursal_id = ? AND estado != \'cancelada\''
);
$stmt_c->bind_param('isi', $barbero_id, $fecha, $suc_id);
$stmt_c->execute();
$res_c = $stmt_c->get_result();

$citas = [];
while ($row_c = $res_c->fetch_assoc()) {
    $citas[] = substr((string)$row_c['hora'], 0, 5);
}
$stmt_c->close();

api_ok([
    'bloqueos' => $bloqueos,
    'citas'    => $citas,
    'horario'  => [
        'hora_inicio'     => substr((string)$bdata['hora_inicio'], 0, 5),
        'hora_fin'        => substr((string)$bdata['hora_fin'], 0, 5),
        'almuerzo_inicio' => substr((string)$bdata['almuerzo_inicio'], 0, 5),
        'almuerzo_fin'    => substr((string)$bdata['almuerzo_fin'], 0, 5),
    ]
]);
