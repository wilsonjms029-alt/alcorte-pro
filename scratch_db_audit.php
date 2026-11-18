<?php
require_once 'backend/config/config.php';

echo "=== TABLAS EN BD ===\n";
$t = $conn->query("SHOW TABLES");
while($r = $t->fetch_row()) echo $r[0] . "\n";

echo "\n=== ESTRUCTURA DE TABLAS CLAVE ===\n";
$tablas = ['citas','barberos','servicios','usuarios','sucursales','planes','suscripciones',
           'productos','pedidos','pedidos_items','configuracion','clientes','bloqueos_horario'];
foreach ($tablas as $tabla) {
    $check = $conn->query("SHOW TABLES LIKE '$tabla'");
    if ($check->num_rows > 0) {
        $cols = $conn->query("SHOW COLUMNS FROM $tabla");
        $col_names = [];
        while($c = $cols->fetch_assoc()) $col_names[] = $c['Field'];
        echo "$tabla: " . implode(', ', $col_names) . "\n";
    } else {
        echo "$tabla: NO EXISTE\n";
    }
}

echo "\n=== CONTEO DE DATOS ===\n";
foreach (['citas','barberos','servicios','usuarios','sucursales','suscripciones','configuracion'] as $t) {
    $r = $conn->query("SELECT COUNT(*) as c FROM $t");
    if ($r) echo "$t: " . $r->fetch_assoc()['c'] . " registros\n";
}
