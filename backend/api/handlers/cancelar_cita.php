<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    api_err('Método no permitido', 405);
}

$input = api_input();
$suc_id = store_id_from_token($conn, $input['t'] ?? '');
$cita_id = (int) ($input['cita_id'] ?? 0);
$telefono = preg_replace('/\D/', '', trim($input['telefono'] ?? ''));

if (!$suc_id || !$cita_id || !$telefono || strlen($telefono) < 7) {
    api_err('Datos incompletos');
}

$stmt = $conn->prepare(
    'SELECT id, fecha, hora, estado FROM citas
     WHERE id = ? AND cliente_telefono = ? AND sucursal_id = ?'
);
$stmt->bind_param('isi', $cita_id, $telefono, $suc_id);
$stmt->execute();
$cita = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$cita) {
    api_err('Cita no encontrada', 404);
}
if ($cita['estado'] === 'cancelada') {
    api_err('Esta cita ya fue cancelada');
}
if ($cita['estado'] === 'completada') {
    api_err('No se puede cancelar una cita completada');
}

$cita_datetime = strtotime($cita['fecha'] . ' ' . $cita['hora']);
$limite = time() + (2 * 3600);

if ($cita_datetime < $limite) {
    api_err('Solo puedes cancelar con al menos 2 horas de anticipación');
}

$upd = $conn->prepare('UPDATE citas SET estado = \'cancelada\' WHERE id = ? AND sucursal_id = ?');
$upd->bind_param('ii', $cita_id, $suc_id);
$upd->execute();
$upd->close();

api_ok([], 'Cita cancelada');
