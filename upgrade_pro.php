<?php
require_once 'backend/config/config.php';

// Find the ID of the 'Básico' plan
$plan_res = $conn->query("SELECT id FROM planes WHERE nombre = 'Básico' LIMIT 1");
$plan = $plan_res->fetch_assoc();
$plan_id = $plan ? intval($plan['id']) : 1;

// Find sucursal_id for 'Barbaroja' or default to 1
$suc_res = $conn->query("SELECT id FROM sucursales WHERE nombre LIKE '%barbaroja%' LIMIT 1");
$suc = $suc_res->fetch_assoc();
$sucursal_id = $suc ? intval($suc['id']) : 1;

echo "Devolviendo sucursal ID $sucursal_id al Plan Básico (ID $plan_id)...\n";

$conn->query("DELETE FROM suscripciones WHERE sucursal_id = $sucursal_id");

// Insert active subscription with Básico
$fecha_inicio = date('Y-m-d');
$fecha_vencimiento = date('Y-m-d', strtotime('+1 year'));
$stmt = $conn->prepare("INSERT INTO suscripciones (sucursal_id, plan_id, fecha_inicio, fecha_vencimiento, estado) VALUES (?, ?, ?, ?, 'activo')");
$stmt->bind_param("iiss", $sucursal_id, $plan_id, $fecha_inicio, $fecha_vencimiento);
$stmt->execute();
$stmt->close();

echo "¡Revertido con éxito!\n";
?>
