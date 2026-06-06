<?php
/**
 * Datos de demostración para AlCorte Pro.
 * Limpia los datos de demo y los regenera de forma consistente para que
 * los paneles (citas, ingresos, suscripciones, Club VIP) se vean vivos.
 *
 * Seguro de re-ejecutar: borra y vuelve a crear los datos de ejemplo.
 * No toca al usuario admin ni a la Sede Central (id=1).
 */
require_once '../config/config.php';

mt_srand(2026); // resultados reproducibles
$ok = [];

// ─────────── 1. Limpiar datos de demo (orden respetando llaves foráneas) ───────────
$conn->query("DELETE FROM pagos_suscripcion");
$conn->query("DELETE FROM suscripciones");
$conn->query("DELETE FROM citas");
$conn->query("DELETE FROM clientes");
$conn->query("DELETE FROM usuarios WHERE rol = 'barbero'");
$conn->query("DELETE FROM barberos");
$conn->query("DELETE FROM sucursales WHERE id > 1");
$ok[] = "Datos de demo anteriores limpiados";

// id=1 = ámbito "Administración" (global/admin). NO es una tienda visible.
$conn->query("INSERT INTO sucursales (id, nombre, direccion, activo) VALUES (1, 'Administración', 'Ámbito global del sistema', 1)
              ON DUPLICATE KEY UPDATE nombre = 'Administración', direccion = 'Ámbito global del sistema'");

// Corregir acentos de los planes (el import original de init.sql los dañó)
$planes_fix = [
    [1, 'Básico',      'Plan ideal para barberías pequeñas que inician'],
    [2, 'Profesional', 'Para negocios en crecimiento con múltiples barberos'],
    [3, 'Empresarial', 'Para cadenas y franquicias con múltiples sedes'],
];
foreach ($planes_fix as $pf) {
    $stmt = $conn->prepare("UPDATE planes SET nombre = ?, descripcion = ? WHERE id = ?");
    $stmt->bind_param("ssi", $pf[1], $pf[2], $pf[0]);
    $stmt->execute();
    $stmt->close();
}
$ok[] = "Nombres de planes corregidos";

// ─────────── 2. Tiendas reales (id=1 Administración no cuenta como tienda) ───────────
$tiendas = [];
foreach ([
    'maracay'  => ['Tienda Maracay',   'Av. Las Delicias, Maracay'],
    'sandiego' => ['Tienda San Diego', 'C.C. Hyper Jumbo, San Diego, Carabobo'],
    'valencia' => ['Tienda Valencia',  'Av. Bolívar Norte, Valencia'],
] as $key => $s) {
    $stmt = $conn->prepare("INSERT INTO sucursales (nombre, direccion, activo) VALUES (?, ?, 1)");
    $stmt->bind_param("ss", $s[0], $s[1]);
    $stmt->execute();
    $tiendas[$key] = $stmt->insert_id;
    $stmt->close();
}
$suc_maracay  = $tiendas['maracay'];
$suc_sandiego = $tiendas['sandiego'];
$suc_valencia = $tiendas['valencia'];
$ok[] = count($tiendas) . " tiendas (Maracay, San Diego, Valencia)";

// ─────────── 3. Barberos (+ usuarios de acceso) ───────────
$barberos_def = [
    ['Joshy Méndez',    '09:00:00', '17:00:00', '12:00:00', '13:00:00', $suc_maracay,  'joshy'],
    ['Carlos Rondón',   '10:00:00', '18:00:00', '13:00:00', '14:00:00', $suc_maracay,  'carlos'],
    ['Andrés Pacheco',  '08:00:00', '16:00:00', '12:00:00', '13:00:00', $suc_sandiego, 'andres'],
    ['Miguel Soto',     '09:00:00', '17:00:00', '12:30:00', '13:30:00', $suc_sandiego, 'miguel'],
    ['Luis Fermín',     '11:00:00', '19:00:00', '14:00:00', '15:00:00', $suc_valencia, 'luis'],
];
$barbero_ids = [];
$hash_barbero = password_hash('barbero123', PASSWORD_DEFAULT);
foreach ($barberos_def as $b) {
    [$nombre, $hi, $hf, $ai, $af, $suc, $usuario] = $b;
    $foto = 'https://ui-avatars.com/api/?background=b49363&color=fff&name=' . urlencode($nombre);
    $stmt = $conn->prepare("INSERT INTO barberos (nombre, foto_url, hora_inicio, hora_fin, almuerzo_inicio, almuerzo_fin, activo, sucursal_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
    $stmt->bind_param("ssssssi", $nombre, $foto, $hi, $hf, $ai, $af, $suc);
    $stmt->execute();
    $bid = $stmt->insert_id;
    $stmt->close();
    $barbero_ids[] = ['id' => $bid, 'nombre' => $nombre, 'suc' => $suc, 'hi' => $hi, 'hf' => $hf];

    $stmt = $conn->prepare("INSERT INTO usuarios (usuario, password, nombre, rol, sucursal_id, barbero_id) VALUES (?, ?, ?, 'barbero', ?, ?)");
    $stmt->bind_param("sssii", $usuario, $hash_barbero, $nombre, $suc, $bid);
    $stmt->execute();
    $stmt->close();
}
$ok[] = count($barbero_ids) . " barberos (acceso: usuario / barbero123)";

// ─────────── 4. Clientes Club VIP ───────────
$clientes_def = [
    ['Pedro Linares',   '04141112233', 9],
    ['María Gómez',     '04241223344', 12],
    ['José Castillo',   '04125334455', 4],
    ['Ana Rodríguez',   '04161445566', 7],
    ['Luis Hernández',  '04145556677', 2],
    ['Carmen Díaz',     '04246667788', 11],
    ['Rafael Pérez',    '04127778899', 1],
    ['Gabriela Torres', '04168889900', 6],
    ['Daniel Ramírez',  '04149990011', 3],
    ['Sofía Mendoza',   '04240001122', 8],
];
$cliente_phones = [];
foreach ($clientes_def as $idx => $c) {
    [$nombre, $tel, $pts] = $c;
    $dias_atras = mt_rand(0, 25);
    $ult = date('Y-m-d', strtotime("-$dias_atras days"));
    $stmt = $conn->prepare("INSERT INTO clientes (telefono, nombre, puntos, ultima_visita) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssis", $tel, $nombre, $pts, $ult);
    $stmt->execute();
    $stmt->close();
    $cliente_phones[] = ['nombre' => $nombre, 'tel' => $tel];
}
$ok[] = count($clientes_def) . " clientes del Club VIP";

// ─────────── 5. Citas (de -10 a +3 días, para poblar gráficas y KPIs) ───────────
$servicios = ['Corte Clásico', 'Barba Premium', 'Combo AlCorte Pro', 'Corte + Cejas'];
$metodos   = ['Efectivo', 'Pago Móvil', 'Zelle'];
$horas_validas = ['09:00:00', '10:00:00', '11:00:00', '14:00:00', '15:00:00', '16:00:00'];
$n_citas = 0;
for ($d = -10; $d <= 3; $d++) {
    $fecha = date('Y-m-d', strtotime("$d days"));
    $citas_dia = mt_rand(2, 5);
    $usadas = []; // evitar choque barbero+hora el mismo día
    for ($k = 0; $k < $citas_dia; $k++) {
        $barb = $barbero_ids[array_rand($barbero_ids)];
        $hora = $horas_validas[array_rand($horas_validas)];
        $slot = $barb['id'] . '|' . $hora;
        if (isset($usadas[$slot])) continue;
        $usadas[$slot] = true;

        $cli = $cliente_phones[array_rand($cliente_phones)];
        $servicio = $servicios[array_rand($servicios)];
        $metodo   = $metodos[array_rand($metodos)];

        if ($d < 0) {
            $estado = mt_rand(0, 9) < 8 ? 'completada' : 'cancelada';
        } elseif ($d === 0) {
            $estado = mt_rand(0, 1) ? 'completada' : 'programada';
        } else {
            $estado = 'programada';
        }
        $estado_pago = ($metodo === 'Efectivo' || $estado === 'completada') ? 'verificado' : (mt_rand(0, 1) ? 'verificado' : 'pendiente');
        $ref = $metodo === 'Efectivo' ? null : (string) mt_rand(10000000, 99999999);

        $stmt = $conn->prepare("INSERT INTO citas (barbero_id, cliente_nombre, cliente_telefono, servicio, fecha, hora, metodo_pago, referencia_pago, estado_pago, estado, sucursal_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssssssssi", $barb['id'], $cli['nombre'], $cli['tel'], $servicio, $fecha, $hora, $metodo, $ref, $estado_pago, $estado, $barb['suc']);
        $stmt->execute();
        $stmt->close();
        $n_citas++;
    }
}
$ok[] = "$n_citas citas (últimos 10 días + próximos 3)";

// ─────────── 6. Planes (ids por nombre) ───────────
$planes = [];
$r = $conn->query("SELECT id, nombre, precio_mensual FROM planes");
while ($p = $r->fetch_assoc()) $planes[$p['nombre']] = $p;
$plan_basico  = $planes['Básico']       ?? null;
$plan_pro     = $planes['Profesional']  ?? null;
$plan_emp     = $planes['Empresarial']  ?? null;

// ─────────── 7. Suscripciones por sucursal ───────────
$asignaciones = [
    $suc_maracay  => [$plan_pro,    '+18 days'], // Maracay: activa
    $suc_sandiego => [$plan_emp,    '+25 days'], // San Diego: activa
    $suc_valencia => [$plan_basico, '-5 days'],  // Valencia: vencida
];
$n_subs = 0;
foreach ($asignaciones as $sid => [$plan, $venc]) {
    if (!$plan) continue;
    $inicio = date('Y-m-d', strtotime('-1 month'));
    $fin    = date('Y-m-d', strtotime($venc));
    $estado = (strtotime($fin) < strtotime('today')) ? 'vencido' : 'activo';
    $stmt = $conn->prepare("INSERT INTO suscripciones (sucursal_id, plan_id, fecha_inicio, fecha_vencimiento, estado) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisss", $sid, $plan['id'], $inicio, $fin, $estado);
    $stmt->execute();
    $stmt->close();
    $n_subs++;
}
$ok[] = "$n_subs suscripciones (activas + 1 vencida)";

// ─────────── 8. Pagos de suscripción (últimos 6 meses, para gráfica de ingresos) ───────────
$metodos_pago = ['Zelle', 'Transferencia', 'PayPal', 'Efectivo'];
$admin_id = $conn->query("SELECT id FROM usuarios WHERE rol='superadmin' LIMIT 1")->fetch_assoc()['id'] ?? null;
$n_pagos = 0;
foreach ($asignaciones as $sid => [$plan, $venc]) {
    if (!$plan) continue;
    for ($m = 5; $m >= 0; $m--) {
        // Valencia (vencida) deja de pagar hace 2 meses
        if ($sid === $suc_valencia && $m < 2) continue;
        $fecha = date('Y-m-05', strtotime("-$m months"));
        $monto = (float) $plan['precio_mensual'];
        $metodo = $metodos_pago[array_rand($metodos_pago)];
        $ref = 'TXN-' . mt_rand(100000, 999999);
        $notas = 'Pago mensual ' . $plan['nombre'];
        $stmt = $conn->prepare("INSERT INTO pagos_suscripcion (sucursal_id, plan_id, monto, fecha_pago, metodo, referencia, notas, registrado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iidssssi", $sid, $plan['id'], $monto, $fecha, $metodo, $ref, $notas, $admin_id);
        $stmt->execute();
        $stmt->close();
        $n_pagos++;
    }
}
$ok[] = "$n_pagos pagos de suscripción (últimos 6 meses)";

// ─────────── 9. Configuración de pagos de demo (Sede Central) ───────────
$cfg = [
    'nombre_negocio'    => 'AlCorte Pro',
    'estado_pago_movil' => '1',
    'estado_zelle'      => '1',
    'estado_efectivo'   => '1',
    'banco_nombre'      => 'Banesco',
    'banco_telefono'    => '0414-1234567',
    'banco_ci'          => 'V-12.345.678',
    'zelle_email'       => 'pagos@alcortepro.com',
];
$stmt = $conn->prepare("INSERT INTO configuracion (clave, valor, sucursal_id) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE valor = ?");
foreach ($cfg as $k => $v) { $stmt->bind_param("sss", $k, $v, $v); $stmt->execute(); }
$stmt->close();
$ok[] = "Métodos de pago configurados (Pago Móvil, Zelle, Efectivo)";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Datos de Demo — AlCorte Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: Inter, sans-serif; background: #f9fafb; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .box { background: white; border-radius: 16px; padding: 36px; max-width: 520px; width: 90%; box-shadow: 0 4px 16px rgba(0,0,0,.08); }
        h2 { font-size: 22px; font-weight: 800; margin: 0 0 6px; color: #111827; }
        .sub { color: #6b7280; font-size: 14px; margin-bottom: 22px; }
        .ok { background: #ecfdf5; border: 1px solid #d1fae5; border-radius: 8px; padding: 12px 16px; color: #065f46; margin-bottom: 8px; font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        .creds { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 14px 16px; color: #92400e; font-size: 13px; margin: 18px 0 4px; line-height: 1.7; }
        .btn { display: inline-block; margin-top: 22px; padding: 11px 22px; background: #18181b; color: white; text-decoration: none; border-radius: 8px; font-weight: 700; font-size: 14px; }
    </style>
</head>
<body>
    <div class="box">
        <h2>✓ Datos de demostración cargados</h2>
        <p class="sub">El sistema ahora tiene información realista para explorar todos los paneles.</p>
        <?php foreach ($ok as $msg): ?>
            <div class="ok">✓ <?php echo htmlspecialchars($msg); ?></div>
        <?php endforeach; ?>
        <div class="creds">
            <strong>Accesos de prueba:</strong><br>
            SuperAdmin → <b>admin</b> / admin1234<br>
            Barberos → <b>joshy</b>, <b>carlos</b>, <b>andres</b>, <b>miguel</b>, <b>luis</b> / barbero123
        </div>
        <a href="../../frontend/superadmin.php" class="btn">← Ir al Panel SuperAdmin</a>
    </div>
</body>
</html>
