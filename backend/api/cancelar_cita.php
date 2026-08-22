<?php
require_once '../config/config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$cita_id  = intval($data['cita_id'] ?? 0);
$telefono = preg_replace('/\D/', '', trim($data['telefono'] ?? ''));

if (!$cita_id || !$telefono) {
    echo json_encode(['ok' => false, 'error' => 'Datos incompletos']);
    exit;
}

$stmt = $conn->prepare(
    "SELECT id, fecha, hora, estado FROM citas WHERE id = ? AND cliente_telefono = ?"
);
$stmt->bind_param("is", $cita_id, $telefono);
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

$upd = $conn->prepare("UPDATE citas SET estado = 'cancelada' WHERE id = ?");
$upd->bind_param("i", $cita_id);
$upd->execute();
$upd->close();

echo json_encode(['ok' => true]);
