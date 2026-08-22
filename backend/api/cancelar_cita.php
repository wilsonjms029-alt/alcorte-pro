<?php
require_once '../config/config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$suc_id = store_id_from_token($conn, $data['t'] ?? '');
$cita_id = (int) ($data['cita_id'] ?? 0);
$telefono = preg_replace('/\D/', '', trim($data['telefono'] ?? ''));

if (!$suc_id || !$cita_id || !$telefono || strlen($telefono) < 7) {
    echo json_encode(['ok' => false, 'error' => 'Datos incompletos']);
    exit;
}

$stmt = $conn->prepare(
    "SELECT id, fecha, hora, estado FROM citas
     WHERE id = ? AND cliente_telefono = ? AND sucursal_id = ?"
);
$stmt->bind_param("isi", $cita_id, $telefono, $suc_id);
$stmt->execute();
$cita = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$cita) {
    echo json_encode(['ok' => false, 'error' => 'Cita no encontrada']);
    exit;
}

if ($cita['estado'] === 'cancelada') {
    echo json_encode(['ok' => false, 'error' => 'Esta cita ya fue cancelada']);
    exit;
}

if ($cita['estado'] === 'completada') {
    echo json_encode(['ok' => false, 'error' => 'No se puede cancelar una cita completada']);
    exit;
}

$cita_datetime = strtotime($cita['fecha'] . ' ' . $cita['hora']);
$limite = time() + (2 * 3600);

if ($cita_datetime < $limite) {
    echo json_encode(['ok' => false, 'error' => 'Solo puedes cancelar con al menos 2 horas de anticipación']);
    exit;
}

$upd = $conn->prepare("UPDATE citas SET estado = 'cancelada' WHERE id = ? AND sucursal_id = ?");
$upd->bind_param("ii", $cita_id, $suc_id);
$upd->execute();
$upd->close();

echo json_encode(['ok' => true]);
