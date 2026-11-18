<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../api/helpers.php';

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'superadmin') {
    
// --- EQUIPO Y PROMOTORES ---
if ($action == 'add_promotor') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'equipo');
    }
    csrf_regenerate();
    $nombre    = trim($_POST['nombre'] ?? '');
    $documento = trim($_POST['documento'] ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $cargo     = trim($_POST['cargo'] ?? 'Promotor/a');
    $codigo    = strtoupper(preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($_POST['codigo_invitacion'] ?? '')));
    $comision  = floatval($_POST['porcentaje_comision'] ?? 15.00);
    $notas     = trim($_POST['notas'] ?? '');

    if ($nombre === '' || $codigo === '') {
        api_superadmin_finish('Nombre y código de invitación requeridos', 'equipo');
    }

    $chk = $conn->prepare('SELECT id FROM promotores WHERE UPPER(codigo_invitacion) = ?');
    $chk->bind_param('s', $codigo);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $chk->close();
        api_superadmin_finish('El código de invitación ya está en uso', 'equipo');
    }
    $chk->close();

    $stmt = $conn->prepare('INSERT INTO promotores (nombre, documento, telefono, email, cargo, codigo_invitacion, porcentaje_comision, notas, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
    $stmt->bind_param('ssssssds', $nombre, $documento, $telefono, $email, $cargo, $codigo, $comision, $notas);
    $stmt->execute();
    $stmt->close();

    api_superadmin_finish('Miembro del equipo registrado con éxito', 'equipo');
}

if ($action == 'edit_promotor') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'equipo');
    }
    csrf_regenerate();
    $id        = intval($_POST['id']);
    $nombre    = trim($_POST['nombre'] ?? '');
    $documento = trim($_POST['documento'] ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $cargo     = trim($_POST['cargo'] ?? 'Promotor/a');
    $codigo    = strtoupper(preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($_POST['codigo_invitacion'] ?? '')));
    $comision  = floatval($_POST['porcentaje_comision'] ?? 15.00);
    $activo    = isset($_POST['activo']) ? intval($_POST['activo']) : 1;
    $notas     = trim($_POST['notas'] ?? '');

    $chk = $conn->prepare('SELECT id FROM promotores WHERE UPPER(codigo_invitacion) = ? AND id != ?');
    $chk->bind_param('si', $codigo, $id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $chk->close();
        api_superadmin_finish('El código de invitación ya está en uso por otro miembro', 'equipo');
    }
    $chk->close();

    $stmt = $conn->prepare('UPDATE promotores SET nombre=?, documento=?, telefono=?, email=?, cargo=?, codigo_invitacion=?, porcentaje_comision=?, activo=?, notas=? WHERE id=?');
    $stmt->bind_param('ssssssdisi', $nombre, $documento, $telefono, $email, $cargo, $codigo, $comision, $activo, $notas, $id);
    $stmt->execute();
    $stmt->close();

    api_superadmin_finish('Miembro del equipo actualizado', 'equipo');
}

if ($action == 'delete_promotor') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'equipo');
    }
    csrf_regenerate();
    $id = intval($_POST['id']);
    $stmt = $conn->prepare('DELETE FROM promotores WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    api_superadmin_finish('Miembro eliminado del equipo', 'equipo');
}

if ($action == 'add_cargo') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'equipo');
    }
    csrf_regenerate();
    $nombre_cargo = trim($_POST['nombre_cargo'] ?? '');
    $desc         = trim($_POST['descripcion_cargo'] ?? '');

    if ($nombre_cargo !== '') {
        $stmt = $conn->prepare('INSERT IGNORE INTO cargos_equipo (nombre, descripcion) VALUES (?, ?)');
        $stmt->bind_param('ss', $nombre_cargo, $desc);
        $stmt->execute();
        $stmt->close();
    }

    api_superadmin_finish('Nuevo cargo creado con éxito', 'equipo');
}

if ($action == 'pagar_comision') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'equipo');
    }
    csrf_regenerate();
    $comision_id = intval($_POST['comision_id']);
    $referencia  = trim($_POST['referencia_pago'] ?? 'Pago procesado');

    $stmt = $conn->prepare("UPDATE comisiones_promotores SET estado = 'pagado', fecha_pago_comision = CURDATE(), referencia_pago = ? WHERE id = ?");
    $stmt->bind_param('si', $referencia, $comision_id);
    $stmt->execute();
    $stmt->close();

    api_superadmin_finish('Comisión marcada como pagada al promotor', 'equipo');
}

if (defined('ALCORTE_API_REQUEST')) {
        api_err('No autorizado', 401);
    }
    header('Location: ../../');
    exit;
}

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

// --- SUCURSALES ---
if ($action == 'add_sucursal') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'barbershops');
    }
    csrf_regenerate();
    $nombre    = trim($_POST['shop_name']);
    $direccion = trim($_POST['shop_address']);
    $token     = bin2hex(random_bytes(16));

    $promotor_id = !empty($_POST['promotor_id']) ? intval($_POST['promotor_id']) : null;
    $codigo_promotor = strtoupper(trim($_POST['codigo_promotor'] ?? ''));
    $descuento_pct = 0.00;

    if ($codigo_promotor !== '') {
        $chk_p = $conn->prepare('SELECT id, codigo_invitacion FROM promotores WHERE UPPER(codigo_invitacion) = ? AND activo = 1 LIMIT 1');
        $chk_p->bind_param('s', $codigo_promotor);
        $chk_p->execute();
        $p_row = $chk_p->get_result()->fetch_assoc();
        $chk_p->close();
        if ($p_row) {
            $promotor_id = (int)$p_row['id'];
            $codigo_promotor = $p_row['codigo_invitacion'];
            $descuento_pct = 10.00;
        }
    } elseif ($promotor_id) {
        $chk_p = $conn->prepare('SELECT codigo_invitacion FROM promotores WHERE id = ? AND activo = 1 LIMIT 1');
        $chk_p->bind_param('i', $promotor_id);
        $chk_p->execute();
        $p_row = $chk_p->get_result()->fetch_assoc();
        $chk_p->close();
        if ($p_row) {
            $codigo_promotor = $p_row['codigo_invitacion'];
            $descuento_pct = 10.00;
        }
    }

    $stmt = $conn->prepare('INSERT INTO sucursales (nombre, direccion, token, promotor_id, descuento_porcentaje, codigo_promotor) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssids', $nombre, $direccion, $token, $promotor_id, $descuento_pct, $codigo_promotor);
    $stmt->execute();
    $stmt->close();
    api_superadmin_finish('Tienda registrada con éxito' . ($descuento_pct > 0 ? ' (10% de descuento por promotor aplicado)' : ''), 'barbershops');
}

if ($action == 'edit_sucursal') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'barbershops');
    }
    csrf_regenerate();
    $id = intval($_POST['id']);
    $nombre = trim($_POST['shop_name']);
    $direccion = trim($_POST['shop_address']);

    $promotor_id = !empty($_POST['promotor_id']) ? intval($_POST['promotor_id']) : null;
    $codigo_promotor = strtoupper(trim($_POST['codigo_promotor'] ?? ''));
    $descuento_pct = 0.00;

    if ($codigo_promotor !== '') {
        $chk_p = $conn->prepare('SELECT id, codigo_invitacion FROM promotores WHERE UPPER(codigo_invitacion) = ? AND activo = 1 LIMIT 1');
        $chk_p->bind_param('s', $codigo_promotor);
        $chk_p->execute();
        $p_row = $chk_p->get_result()->fetch_assoc();
        $chk_p->close();
        if ($p_row) {
            $promotor_id = (int)$p_row['id'];
            $codigo_promotor = $p_row['codigo_invitacion'];
            $descuento_pct = 10.00;
        }
    } elseif ($promotor_id) {
        $chk_p = $conn->prepare('SELECT codigo_invitacion FROM promotores WHERE id = ? AND activo = 1 LIMIT 1');
        $chk_p->bind_param('i', $promotor_id);
        $chk_p->execute();
        $p_row = $chk_p->get_result()->fetch_assoc();
        $chk_p->close();
        if ($p_row) {
            $codigo_promotor = $p_row['codigo_invitacion'];
            $descuento_pct = 10.00;
        }
    }

    $stmt = $conn->prepare('UPDATE sucursales SET nombre = ?, direccion = ?, promotor_id = ?, descuento_porcentaje = ?, codigo_promotor = ? WHERE id = ?');
    $stmt->bind_param('ssidsi', $nombre, $direccion, $promotor_id, $descuento_pct, $codigo_promotor, $id);
    $stmt->execute();
    $stmt->close();
    api_superadmin_finish('Tienda actualizada', 'barbershops');
}

if ($action == 'delete_sucursal') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'barbershops');
    }
    csrf_regenerate();
    $id = intval($_POST['id']);
    $stmt = $conn->prepare('DELETE FROM sucursales WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    api_superadmin_finish('Tienda eliminada', 'barbershops');
}

// --- PLANES ---
if ($action == 'add_plan') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'planes');
    }
    csrf_regenerate();
    $nombre        = trim($_POST['plan_nombre']);
    $precio        = floatval($_POST['plan_precio']);
    $max_barberos  = intval($_POST['plan_max_barberos']);
    $nivel         = ($max_barberos === 1) ? 1 : (($max_barberos > 1 && $max_barberos <= 10) ? 2 : 3);
    $descripcion   = trim($_POST['plan_descripcion']);
    $stmt = $conn->prepare('INSERT INTO planes (nombre, precio_mensual, max_barberos, nivel, descripcion) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('sdiis', $nombre, $precio, $max_barberos, $nivel, $descripcion);
    $stmt->execute();
    $stmt->close();
    api_superadmin_finish('Plan creado con éxito', 'planes');
}

if ($action == 'edit_plan') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'planes');
    }
    csrf_regenerate();
    $id            = intval($_POST['plan_id']);
    $nombre        = trim($_POST['plan_nombre']);
    $precio        = floatval($_POST['plan_precio']);
    $max_barberos  = intval($_POST['plan_max_barberos']);
    $nivel         = ($max_barberos === 1) ? 1 : (($max_barberos > 1 && $max_barberos <= 10) ? 2 : 3);
    $descripcion   = trim($_POST['plan_descripcion']);
    $stmt = $conn->prepare('UPDATE planes SET nombre=?, precio_mensual=?, max_barberos=?, nivel=?, descripcion=? WHERE id=?');
    $stmt->bind_param('sdiisi', $nombre, $precio, $max_barberos, $nivel, $descripcion, $id);
    $stmt->execute();
    $stmt->close();
    api_superadmin_finish('Plan actualizado', 'planes');
}

if ($action == 'delete_plan') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'planes');
    }
    csrf_regenerate();
    $id = intval($_POST['id']);
    $stmt = $conn->prepare('DELETE FROM planes WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    api_superadmin_finish('Plan eliminado', 'planes');
}

// --- PAGOS ---
if ($action == 'add_pago') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'pagos');
    }
    csrf_regenerate();
    $sucursal_id = intval($_POST['pago_sucursal']);
    $plan_id     = intval($_POST['pago_plan']) ?: null;
    $monto       = floatval($_POST['pago_monto']);
    if ($monto <= 0 && $plan_id) {
        $pm = $conn->prepare('SELECT precio_mensual FROM planes WHERE id = ?');
        $pm->bind_param('i', $plan_id);
        $pm->execute();
        $pr = $pm->get_result()->fetch_assoc();
        if ($pr) {
            $monto = floatval($pr['precio_mensual']);
        }
        $pm->close();
    }
    $fecha       = trim($_POST['pago_fecha']);
    $metodo      = trim($_POST['pago_metodo']);
    $referencia  = trim($_POST['pago_referencia']);
    $notas       = trim($_POST['pago_notas']);
    $user_id     = $_SESSION['user_id'];
    $stmt = $conn->prepare(
        'INSERT INTO pagos_suscripcion (sucursal_id, plan_id, monto, fecha_pago, metodo, referencia, notas, registrado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iidssssi', $sucursal_id, $plan_id, $monto, $fecha, $metodo, $referencia, $notas, $user_id);
    $stmt->execute();
    $stmt->close();
    api_superadmin_finish('Pago registrado con éxito', 'pagos');
}

if ($action == 'delete_pago') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'pagos');
    }
    csrf_regenerate();
    $id = intval($_POST['id']);
    $stmt = $conn->prepare('DELETE FROM pagos_suscripcion WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    api_superadmin_finish('Pago eliminado', 'pagos');
}

// --- SUSCRIPCIONES ---
if ($action == 'assign_plan') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'barbershops');
    }
    csrf_regenerate();
    $sucursal_id = intval($_POST['sub_sucursal']);
    $plan_id     = intval($_POST['sub_plan']);
    $inicio      = trim($_POST['sub_inicio']);
    $fin         = trim($_POST['sub_fin']);
    $pago_metodo = trim($_POST['pago_metodo'] ?? 'Efectivo');
    $pago_ref    = trim($_POST['pago_referencia'] ?? '');
    $pago_notas  = trim($_POST['pago_notas'] ?? '');
    $user_id     = $_SESSION['user_id'];

    $d = $conn->prepare('DELETE FROM suscripciones WHERE sucursal_id = ?');
    $d->bind_param('i', $sucursal_id);
    $d->execute();
    $d->close();

    $stmt = $conn->prepare(
        'INSERT INTO suscripciones (sucursal_id, plan_id, fecha_inicio, fecha_vencimiento, estado) VALUES (?, ?, ?, ?, \'activo\')'
    );
    $stmt->bind_param('iiss', $sucursal_id, $plan_id, $inicio, $fin);
    $stmt->execute();
    $stmt->close();

    $pm = $conn->prepare('SELECT precio_mensual, nombre FROM planes WHERE id = ?');
    $pm->bind_param('i', $plan_id);
    $pm->execute();
    $plan_row = $pm->get_result()->fetch_assoc();
    $pm->close();
    $precio_base = $plan_row ? floatval($plan_row['precio_mensual']) : 0;

    // Verificar si la tienda tiene promotor y descuento
    $sc = $conn->prepare('SELECT promotor_id, descuento_porcentaje, codigo_promotor FROM sucursales WHERE id = ?');
    $sc->bind_param('i', $sucursal_id);
    $sc->execute();
    $suc_info = $sc->get_result()->fetch_assoc();
    $sc->close();

    $promotor_id   = !empty($suc_info['promotor_id']) ? intval($suc_info['promotor_id']) : null;
    $descuento_pct = !empty($suc_info['descuento_porcentaje']) ? floatval($suc_info['descuento_porcentaje']) : 0.00;
    if ($promotor_id && $descuento_pct <= 0) {
        $descuento_pct = 10.00;
    }

    $monto = round($precio_base * (1 - ($descuento_pct / 100)), 2);
    $pago_id = null;

    if ($monto > 0) {
        $nota_completa = $pago_notas;
        if ($descuento_pct > 0) {
            $cod_p = $suc_info['codigo_promotor'] ?? '';
            $nota_completa = trim($nota_completa . " [10% Descuento Promotor ($cod_p)]");
        }
        $sp = $conn->prepare(
            'INSERT INTO pagos_suscripcion (sucursal_id, plan_id, monto, fecha_pago, metodo, referencia, notas, registrado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $sp->bind_param('iidssssi', $sucursal_id, $plan_id, $monto, $inicio, $pago_metodo, $pago_ref, $nota_completa, $user_id);
        $sp->execute();
        $pago_id = $sp->insert_id;
        $sp->close();
    }

    // Registrar comisión para el promotor si la tienda fue afiliada por uno
    if ($promotor_id && $monto > 0) {
        $pr = $conn->prepare('SELECT porcentaje_comision, nombre FROM promotores WHERE id = ? AND activo = 1');
        $pr->bind_param('i', $promotor_id);
        $pr->execute();
        $promo_data = $pr->get_result()->fetch_assoc();
        $pr->close();

        if ($promo_data) {
            $comision_pct   = floatval($promo_data['porcentaje_comision']);
            $comision_monto = round($monto * ($comision_pct / 100), 2);
            $plan_nom       = $plan_row['nombre'] ?? 'Plan';
            $com_nota       = "Comisión de promotor por alquiler de $plan_nom (\$$monto)";

            $cp = $conn->prepare(
                'INSERT INTO comisiones_promotores (promotor_id, sucursal_id, pago_id, plan_id, monto_plan, descuento_porcentaje, monto_pagado, comision_porcentaje, monto_comision, estado, notas)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'pendiente\', ?)'
            );
            $cp->bind_param('iiiiddddds', $promotor_id, $sucursal_id, $pago_id, $plan_id, $precio_base, $descuento_pct, $monto, $comision_pct, $comision_monto, $com_nota);
            $cp->execute();
            $cp->close();
        }
    }

    $msg_extra = $descuento_pct > 0 ? ' (Con 10% desc. de promotor)' : '';
    api_superadmin_finish('Plan asignado y pago registrado' . $msg_extra, 'barbershops');
}

// --- SETTINGS ---
if ($action == 'save_settings') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'settings');
    }
    csrf_regenerate();
    $nombre_negocio = trim($_POST['nombre_negocio']);
    $zelle_email    = trim($_POST['zelle_email']);
    $stmt = $conn->prepare('INSERT INTO configuracion (clave, valor, sucursal_id) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE valor = ?');
    foreach (['nombre_negocio' => $nombre_negocio, 'zelle_email' => $zelle_email] as $clave => $valor) {
        $stmt->bind_param('sss', $clave, $valor, $valor);
        $stmt->execute();
    }
    $stmt->close();
    api_superadmin_finish('Configuración actualizada', 'settings');
}

// --- ADMINISTRADORES ---
if ($action == 'add_admin') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'barbershops');
    }
    csrf_regenerate();
    $nombre    = trim($_POST['adm_nombre'] ?? '');
    $usuario   = trim($_POST['adm_usuario'] ?? '');
    $password  = trim($_POST['adm_password'] ?? '');
    $telefono  = trim($_POST['adm_telefono'] ?? '');
    $sucursal  = intval($_POST['adm_sucursal'] ?? 0);

    if (!$nombre || !$usuario || strlen($password) < 8 || !$sucursal) {
        api_superadmin_finish('Completa todos los campos (contraseña mín. 8 caracteres)', 'barbershops');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('INSERT INTO usuarios (usuario, password, nombre, telefono, rol, sucursal_id) VALUES (?, ?, ?, ?, \'admin\', ?)');
    $stmt->bind_param('ssssi', $usuario, $hash, $nombre, $telefono, $sucursal);
    try {
        $stmt->execute();
        $stmt->close();
        api_superadmin_finish('Administrador creado con éxito', 'barbershops');
    } catch (\mysqli_sql_exception $e) {
        $stmt->close();
        if ($e->getCode() === 1062) {
            api_superadmin_finish('El nombre de usuario ya existe', 'barbershops');
        }
        throw $e;
    }
}

if ($action == 'edit_admin') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'barbershops');
    }
    csrf_regenerate();
    $id       = intval($_POST['adm_id'] ?? 0);
    $nombre   = trim($_POST['adm_nombre'] ?? '');
    $usuario  = trim($_POST['adm_usuario'] ?? '');
    $telefono = trim($_POST['adm_telefono'] ?? '');
    $sucursal = intval($_POST['adm_sucursal'] ?? 0);
    $password = trim($_POST['adm_password'] ?? '');

    if (!$id || !$nombre || !$usuario || !$sucursal) {
        api_superadmin_finish('Datos incompletos', 'barbershops');
    }
    if ($password !== '') {
        if (strlen($password) < 8) {
            api_superadmin_finish('Contraseña mínimo 8 caracteres', 'barbershops');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE usuarios SET nombre=?, usuario=?, telefono=?, sucursal_id=?, password=? WHERE id=? AND rol=\'admin\'');
        $stmt->bind_param('sssisi', $nombre, $usuario, $telefono, $sucursal, $hash, $id);
    } else {
        $stmt = $conn->prepare('UPDATE usuarios SET nombre=?, usuario=?, telefono=?, sucursal_id=? WHERE id=? AND rol=\'admin\'');
        $stmt->bind_param('sssii', $nombre, $usuario, $telefono, $sucursal, $id);
    }
    try {
        $stmt->execute();
        $stmt->close();
        api_superadmin_finish('Administrador actualizado', 'barbershops');
    } catch (\mysqli_sql_exception $e) {
        $stmt->close();
        if ($e->getCode() === 1062) {
            api_superadmin_finish('El nombre de usuario ya existe', 'barbershops');
        }
        throw $e;
    }
}

if ($action == 'delete_admin') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'barbershops');
    }
    csrf_regenerate();
    $id = intval($_POST['adm_id'] ?? 0);
    if ($id) {
        $stmt = $conn->prepare('DELETE FROM usuarios WHERE id=? AND rol=\'admin\'');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }
    api_superadmin_finish('Administrador eliminado', 'barbershops');
}


// --- EQUIPO Y PROMOTORES ---
if ($action == 'add_promotor') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'equipo');
    }
    csrf_regenerate();
    $nombre    = trim($_POST['nombre'] ?? '');
    $documento = trim($_POST['documento'] ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $cargo     = trim($_POST['cargo'] ?? 'Promotor/a');
    $codigo    = strtoupper(preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($_POST['codigo_invitacion'] ?? '')));
    $comision  = floatval($_POST['porcentaje_comision'] ?? 15.00);
    $notas     = trim($_POST['notas'] ?? '');

    if ($nombre === '' || $codigo === '') {
        api_superadmin_finish('Nombre y código de invitación requeridos', 'equipo');
    }

    $chk = $conn->prepare('SELECT id FROM promotores WHERE UPPER(codigo_invitacion) = ?');
    $chk->bind_param('s', $codigo);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $chk->close();
        api_superadmin_finish('El código de invitación ya está en uso', 'equipo');
    }
    $chk->close();

    $stmt = $conn->prepare('INSERT INTO promotores (nombre, documento, telefono, email, cargo, codigo_invitacion, porcentaje_comision, notas, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
    $stmt->bind_param('ssssssds', $nombre, $documento, $telefono, $email, $cargo, $codigo, $comision, $notas);
    $stmt->execute();
    $stmt->close();

    api_superadmin_finish('Miembro del equipo registrado con éxito', 'equipo');
}

if ($action == 'edit_promotor') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'equipo');
    }
    csrf_regenerate();
    $id        = intval($_POST['id']);
    $nombre    = trim($_POST['nombre'] ?? '');
    $documento = trim($_POST['documento'] ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $cargo     = trim($_POST['cargo'] ?? 'Promotor/a');
    $codigo    = strtoupper(preg_replace('/[^a-zA-Z0-9_\-]/', '', trim($_POST['codigo_invitacion'] ?? '')));
    $comision  = floatval($_POST['porcentaje_comision'] ?? 15.00);
    $activo    = isset($_POST['activo']) ? intval($_POST['activo']) : 1;
    $notas     = trim($_POST['notas'] ?? '');

    $chk = $conn->prepare('SELECT id FROM promotores WHERE UPPER(codigo_invitacion) = ? AND id != ?');
    $chk->bind_param('si', $codigo, $id);
    $chk->execute();
    if ($chk->get_result()->num_rows > 0) {
        $chk->close();
        api_superadmin_finish('El código de invitación ya está en uso por otro miembro', 'equipo');
    }
    $chk->close();

    $stmt = $conn->prepare('UPDATE promotores SET nombre=?, documento=?, telefono=?, email=?, cargo=?, codigo_invitacion=?, porcentaje_comision=?, activo=?, notas=? WHERE id=?');
    $stmt->bind_param('ssssssdisi', $nombre, $documento, $telefono, $email, $cargo, $codigo, $comision, $activo, $notas, $id);
    $stmt->execute();
    $stmt->close();

    api_superadmin_finish('Miembro del equipo actualizado', 'equipo');
}

if ($action == 'delete_promotor') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'equipo');
    }
    csrf_regenerate();
    $id = intval($_POST['id']);
    $stmt = $conn->prepare('DELETE FROM promotores WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    api_superadmin_finish('Miembro eliminado del equipo', 'equipo');
}

if ($action == 'add_cargo') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'equipo');
    }
    csrf_regenerate();
    $nombre_cargo = trim($_POST['nombre_cargo'] ?? '');
    $desc         = trim($_POST['descripcion_cargo'] ?? '');

    if ($nombre_cargo !== '') {
        $stmt = $conn->prepare('INSERT IGNORE INTO cargos_equipo (nombre, descripcion) VALUES (?, ?)');
        $stmt->bind_param('ss', $nombre_cargo, $desc);
        $stmt->execute();
        $stmt->close();
    }

    api_superadmin_finish('Nuevo cargo creado con éxito', 'equipo');
}

if ($action == 'pagar_comision') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'equipo');
    }
    csrf_regenerate();
    $comision_id = intval($_POST['comision_id']);
    $referencia  = trim($_POST['referencia_pago'] ?? 'Pago procesado');

    $stmt = $conn->prepare("UPDATE comisiones_promotores SET estado = 'pagado', fecha_pago_comision = CURDATE(), referencia_pago = ? WHERE id = ?");
    $stmt->bind_param('si', $referencia, $comision_id);
    $stmt->execute();
    $stmt->close();

    api_superadmin_finish('Comisión marcada como pagada al promotor', 'equipo');
}

if (defined('ALCORTE_API_REQUEST')) {
    api_err('Acción no reconocida', 400);
}

header('Location: ../../frontend/superadmin.php?page=dashboard');
exit;
