<?php
require_once '../config/config.php';
header('Content-Type: application/json');

$barbero_id = intval($_GET['barbero_id'] ?? 0);
$fecha      = trim($_GET['fecha'] ?? '');

if (!$barbero_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    echo json_encode(['bloqueos' => []]);
    exit;
}

$stmt = $conn->prepare(
    "SELECT id, hora_inicio, hora_fin, motivo, dia_completo
     FROM bloqueos_horario
     WHERE barbero_id = ? AND fecha = ?"
);
$stmt->bind_param("is", $barbero_id, $fecha);
$stmt->execute();
$res = $stmt->get_result();

$bloqueos = [];
while ($row = $res->fetch_assoc()) $bloqueos[] = $row;
$stmt->close();

echo json_encode(['bloqueos' => $bloqueos]);
