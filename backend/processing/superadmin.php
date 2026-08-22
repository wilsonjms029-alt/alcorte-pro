<?php
require_once '../config/config.php';
require_once __DIR__ . '/../api/helpers.php';

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'superadmin') {
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
    $stmt = $conn->prepare('INSERT INTO sucursales (nombre, direccion, token) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $nombre, $direccion, $token);
    $stmt->execute();
    $stmt->close();
    api_superadmin_finish('Tienda registrada con éxito', 'barbershops');
}

if ($action == 'edit_sucursal') {
    if (!csrf_validate()) {
        api_superadmin_finish('Error de seguridad', 'barbershops');
    }
    csrf_regenerate();
    $id = intval($_POST['id']);
    $nombre = trim($_POST['shop_name']);
    $direccion = trim($_POST['shop_address']);
    $stmt = $conn->prepare('UPDATE sucursales SET nombre = ?, direccion = ? WHERE id = ?');
    $stmt->bind_param('ssi', $nombre, $direccion, $id);
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

    $pm = $conn->prepare('SELECT precio_mensual FROM planes WHERE id = ?');
    $pm->bind_param('i', $plan_id);
    $pm->execute();
    $plan_row = $pm->get_result()->fetch_assoc();
    $pm->close();
    $monto = $plan_row ? floatval($plan_row['precio_mensual']) : 0;
    if ($monto > 0) {
        $sp = $conn->prepare(
            'INSERT INTO pagos_suscripcion (sucursal_id, plan_id, monto, fecha_pago, metodo, referencia, notas, registrado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $sp->bind_param('iidssssi', $sucursal_id, $plan_id, $monto, $inicio, $pago_metodo, $pago_ref, $pago_notas, $user_id);
        $sp->execute();
        $sp->close();
    }

    api_superadmin_finish('Plan asignado y pago registrado', 'barbershops');
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
    if ($stmt->execute()) {
        $stmt->close();
        api_superadmin_finish('Administrador creado con éxito', 'barbershops');
    }
    $stmt->close();
    api_superadmin_finish('El nombre de usuario ya existe', 'barbershops');
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
    $stmt->execute();
    $stmt->close();
    api_superadmin_finish('Administrador actualizado', 'barbershops');
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

if (defined('ALCORTE_API_REQUEST')) {
    api_err('Acción no reconocida', 400);
}

header('Location: ../../frontend/superadmin.php?page=dashboard');
exit;
