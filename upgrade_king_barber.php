<?php
require_once 'backend/config/config.php';

// Find the ID of the 'Pro' plan
$plan_res = $conn->query("SELECT id FROM planes WHERE nombre = 'Pro' OR nivel = 3 LIMIT 1");
$plan = $plan_res->fetch_assoc();
$plan_id = $plan ? intval($plan['id']) : 3;

$sucursal_id = 4; // King Barber

echo "Asignando Plan Pro (ID $plan_id) a King Barber (ID $sucursal_id)...\n";

$conn->query("DELETE FROM suscripciones WHERE sucursal_id = $sucursal_id");

// Insert new active subscription with 1 year validity
$fecha_inicio = date('Y-m-d');
$fecha_vencimiento = date('Y-m-d', strtotime('+1 year'));
$stmt = $conn->prepare("INSERT INTO suscripciones (sucursal_id, plan_id, fecha_inicio, fecha_vencimiento, estado) VALUES (?, ?, ?, ?, 'activo')");
$stmt->bind_param("iiss", $sucursal_id, $plan_id, $fecha_inicio, $fecha_vencimiento);
$stmt->execute();
$stmt->close();

echo "¡King Barber actualizado al Plan Pro con éxito!\n";
?>
