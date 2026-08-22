<?php
require_once 'backend/config/config.php';

echo "=== SUCURSALES ===\n";
$res = $conn->query("SELECT * FROM sucursales");
while($row = $res->fetch_assoc()) {
    echo "ID: {$row['id']} - Nombre: {$row['nombre']} - Activo: {$row['activo']}\n";
}

echo "\n=== PLANES ===\n";
$res = $conn->query("SELECT * FROM planes");
while($row = $res->fetch_assoc()) {
    echo "ID: {$row['id']} - Nombre: {$row['nombre']} - Nivel: {$row['nivel']}\n";
}

echo "\n=== SUSCRIPCIONES ===\n";
$res = $conn->query("SELECT s.*, p.nombre as plan_nombre, suc.nombre as suc_nombre FROM suscripciones s JOIN planes p ON s.plan_id = p.id JOIN sucursales suc ON s.sucursal_id = suc.id");
while($row = $res->fetch_assoc()) {
    echo "ID: {$row['id']} - Sucursal: {$row['suc_nombre']} (ID {$row['sucursal_id']}) - Plan: {$row['plan_nombre']} - Estado: {$row['estado']} - Vence: {$row['fecha_vencimiento']}\n";
}
?>
