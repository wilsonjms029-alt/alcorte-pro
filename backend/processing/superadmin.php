<?php
session_start();
require_once '../config/config.php';

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'superadmin') {
    header("Location: ../../");
    exit;
}

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

function suscripcion_fin_mensual(string $inicio): string {
    $dt = new DateTime($inicio);
    $dt->modify('+1 month');
    return $dt->format('Y-m-d');
}

function upsert_sucursal_suscripcion(mysqli $conn, int $sucursal_id, int $plan_id, string $inicio): void {
    if ($plan_id <= 0 || !$inicio) {
        return;
    }
    $fin = suscripcion_fin_mensual($inicio);
    $estado = (strtotime($fin) < strtotime('today')) ? 'vencido' : 'activo';
    $d = $conn->prepare("DELETE FROM suscripciones WHERE sucursal_id = ?");
    $d->bind_param("i", $sucursal_id);
    $d->execute();
    $d->close();
    $stmt = $conn->prepare("INSERT INTO suscripciones (sucursal_id, plan_id, fecha_inicio, fecha_vencimiento, estado) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisss", $sucursal_id, $plan_id, $inicio, $fin, $estado);
    $stmt->execute();
    $stmt->close();
}

function clear_sucursal_suscripcion(mysqli $conn, int $sucursal_id): void {
    $d = $conn->prepare("DELETE FROM suscripciones WHERE sucursal_id = ?");
    $d->bind_param("i", $sucursal_id);
    $d->execute();
    $d->close();
}

function crear_admin_tienda(mysqli $conn, int $sucursal_id, string $nombre, string $usuario, string $password, string $telefono): bool {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO usuarios (usuario, password, nombre, telefono, rol, sucursal_id) VALUES (?, ?, ?, ?, 'admin', ?)");
    $stmt->bind_param("ssssi", $usuario, $hash, $nombre, $telefono, $sucursal_id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function actualizar_admin_tienda(mysqli $conn, int $adm_id, int $sucursal_id, string $nombre, string $usuario, string $telefono, string $password = ''): bool {
    if ($password !== '') {
        if (strlen($password) < 8) {
            return false;
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE usuarios SET nombre=?, usuario=?, telefono=?, sucursal_id=?, password=? WHERE id=? AND rol='admin'");
        $stmt->bind_param("sssisi", $nombre, $usuario, $telefono, $sucursal_id, $hash, $adm_id);
    } else {
        $stmt = $conn->prepare("UPDATE usuarios SET nombre=?, usuario=?, telefono=?, sucursal_id=? WHERE id=? AND rol='admin'");
        $stmt->bind_param("sssii", $nombre, $usuario, $telefono, $sucursal_id, $adm_id);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// --- SUCURSALES ---
if ($action == 'add_sucursal') {
    if (!csrf_validate()) { header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Error+de+seguridad"); exit; }
    csrf_regenerate();
    $nombre    = trim($_POST['shop_name'] ?? '');
    $direccion = trim($_POST['shop_address'] ?? '');
    $plan_id   = intval($_POST['shop_plan'] ?? 0);
    $inicio    = trim($_POST['shop_sub_inicio'] ?? '');
    $sin_plan  = isset($_POST['shop_sin_plan']) && $_POST['shop_sin_plan'] === '1';
    $with_plan = array_key_exists('shop_sub_inicio', $_POST) || array_key_exists('shop_sin_plan', $_POST);
    $adm_nombre   = trim($_POST['shop_adm_nombre'] ?? '');
    $adm_usuario  = trim($_POST['shop_adm_usuario'] ?? '');
    $adm_password = trim($_POST['shop_adm_password'] ?? '');
    $adm_telefono = trim($_POST['shop_adm_telefono'] ?? '');

    if (!$nombre || !$direccion) {
        header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Completa+nombre+y+dirección+de+la+tienda");
        exit;
    }
    if ($with_plan && !$sin_plan && $plan_id <= 0) {
        header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Selecciona+un+plan+o+marca+“Sin+plan+por+ahora”");
        exit;
    }
    if ($with_plan && !$sin_plan && !$inicio) {
        header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Indica+el+inicio+del+periodo+mensual");
        exit;
    }
    if (!$adm_nombre || !$adm_usuario || strlen($adm_password) < 8) {
        header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Completa+los+datos+del+administrador+%28contraseña+mín.+8+caracteres%29");
        exit;
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO sucursales (nombre, direccion) VALUES (?, ?)");
        $stmt->bind_param("ss", $nombre, $direccion);
        $stmt->execute();
        $sucursal_id = $stmt->insert_id;
        $stmt->close();

        if (!$sin_plan && $with_plan) {
            upsert_sucursal_suscripcion($conn, $sucursal_id, $plan_id, $inicio);
        }

        if (!crear_admin_tienda($conn, $sucursal_id, $adm_nombre, $adm_usuario, $adm_password, $adm_telefono)) {
            throw new RuntimeException('usuario_duplicado');
        }

        $conn->commit();
        header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Tienda+y+administrador+registrados+con+éxito");
    } catch (RuntimeException $e) {
        $conn->rollback();
        if ($e->getMessage() === 'usuario_duplicado') {
            header("Location: ../../frontend/superadmin.php?page=barbershops&msg=El+nombre+de+usuario+del+admin+ya+existe");
        } else {
            header("Location: ../../frontend/superadmin.php?page=barbershops&msg=No+se+pudo+registrar+la+tienda");
        }
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('add_sucursal: ' . $e->getMessage());
        header("Location: ../../frontend/superadmin.php?page=barbershops&msg=No+se+pudo+registrar+la+tienda");
    }
    exit;
}

if ($action == 'edit_sucursal') {
    if (!csrf_validate()) { header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Error+de+seguridad"); exit; }
    csrf_regenerate();
    $id        = intval($_POST['id'] ?? 0);
    $nombre    = trim($_POST['shop_name'] ?? '');
    $direccion = trim($_POST['shop_address'] ?? '');
    $plan_id   = intval($_POST['shop_plan'] ?? 0);
    $inicio    = trim($_POST['shop_sub_inicio'] ?? '');
    $sin_plan  = isset($_POST['shop_sin_plan']) && $_POST['shop_sin_plan'] === '1';
    $with_plan = array_key_exists('shop_sub_inicio', $_POST) || array_key_exists('shop_sin_plan', $_POST);
    $adm_id       = intval($_POST['shop_adm_id'] ?? 0);
    $adm_nombre   = trim($_POST['shop_adm_nombre'] ?? '');
    $adm_usuario  = trim($_POST['shop_adm_usuario'] ?? '');
    $adm_password = trim($_POST['shop_adm_password'] ?? '');
    $adm_telefono = trim($_POST['shop_adm_telefono'] ?? '');

    if (!$id || $id === 1 || !$nombre || !$direccion) {
        header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Datos+de+tienda+incompletos");
        exit;
    }
    if ($with_plan && !$sin_plan && $plan_id <= 0) {
        header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Selecciona+un+plan+o+marca+“Sin+plan+por+ahora”");
        exit;
    }
    if (!$adm_nombre || !$adm_usuario) {
        header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Completa+los+datos+del+administrador");
        exit;
    }
    if (!$adm_id && strlen($adm_password) < 8) {
        header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Contraseña+del+admin+mínimo+8+caracteres");
        exit;
    }
    if ($adm_password !== '' && strlen($adm_password) < 8) {
        header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Contraseña+del+admin+mínimo+8+caracteres");
        exit;
    }

    $stmt = $conn->prepare("UPDATE sucursales SET nombre = ?, direccion = ? WHERE id = ?");
    $stmt->bind_param("ssi", $nombre, $direccion, $id);
    $stmt->execute();
    $stmt->close();

    if ($with_plan) {
        if ($sin_plan) {
            clear_sucursal_suscripcion($conn, $id);
        } else {
            upsert_sucursal_suscripcion($conn, $id, $plan_id, $inicio);
        }
    }

    if ($adm_id > 0) {
        if (!actualizar_admin_tienda($conn, $adm_id, $id, $adm_nombre, $adm_usuario, $adm_telefono, $adm_password)) {
            header("Location: ../../frontend/superadmin.php?page=barbershops&msg=No+se+pudo+actualizar+el+administrador");
            exit;
        }
    } else {
        if (!crear_admin_tienda($conn, $id, $adm_nombre, $adm_usuario, $adm_password, $adm_telefono)) {
            header("Location: ../../frontend/superadmin.php?page=barbershops&msg=El+nombre+de+usuario+del+admin+ya+existe");
            exit;
        }
    }

    header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Tienda+actualizada");
    exit;
}

if ($action == 'delete_sucursal') {
    if (!csrf_validate()) { header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Error+de+seguridad"); exit; }
    csrf_regenerate();
    $id = intval($_POST['id']);
    if ($id === 1) { header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Esa+tienda+no+se+puede+eliminar"); exit; }
    $stmt = $conn->prepare("DELETE FROM sucursales WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Tienda+eliminada");
    exit;
}

// --- PLANES ---
if ($action == 'add_plan') {
    if (!csrf_validate()) { header("Location: ../../frontend/superadmin.php?page=planes&msg=Error+de+seguridad"); exit; }
    csrf_regenerate();
    $nombre        = trim($_POST['plan_nombre']);
    $precio        = floatval($_POST['plan_precio']);
    $max_barberos  = intval($_POST['plan_max_barberos']);
    $max_citas     = intval($_POST['plan_max_citas']);
    $descripcion   = trim($_POST['plan_descripcion']);
    $stmt = $conn->prepare("INSERT INTO planes (nombre, precio_mensual, max_barberos, max_citas_mes, descripcion) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sdiis", $nombre, $precio, $max_barberos, $max_citas, $descripcion);
    $stmt->execute();
    $stmt->close();
    header("Location: ../../frontend/superadmin.php?page=planes&msg=Plan+creado+con+éxito");
    exit;
}

if ($action == 'edit_plan') {
    if (!csrf_validate()) { header("Location: ../../frontend/superadmin.php?page=planes&msg=Error+de+seguridad"); exit; }
    csrf_regenerate();
    $id            = intval($_POST['plan_id']);
    $nombre        = trim($_POST['plan_nombre']);
    $precio        = floatval($_POST['plan_precio']);
    $max_barberos  = intval($_POST['plan_max_barberos']);
    $max_citas     = intval($_POST['plan_max_citas']);
    $descripcion   = trim($_POST['plan_descripcion']);
    $stmt = $conn->prepare("UPDATE planes SET nombre=?, precio_mensual=?, max_barberos=?, max_citas_mes=?, descripcion=? WHERE id=?");
    $stmt->bind_param("sdiisi", $nombre, $precio, $max_barberos, $max_citas, $descripcion, $id);
    $stmt->execute();
    $stmt->close();
    header("Location: ../../frontend/superadmin.php?page=planes&msg=Plan+actualizado");
    exit;
}

if ($action == 'delete_plan') {
    if (!csrf_validate()) { header("Location: ../../frontend/superadmin.php?page=planes&msg=Error+de+seguridad"); exit; }
    csrf_regenerate();
    $id = intval($_POST['id']);
    $stmt = $conn->prepare("DELETE FROM planes WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: ../../frontend/superadmin.php?page=planes&msg=Plan+eliminado");
    exit;
}

// --- PAGOS ---
if ($action == 'add_pago') {
    if (!csrf_validate()) { header("Location: ../../frontend/superadmin.php?page=pagos&msg=Error+de+seguridad"); exit; }
    csrf_regenerate();
    $sucursal_id = intval($_POST['pago_sucursal']);
    $plan_id     = intval($_POST['pago_plan']) ?: null;
    $monto       = floatval($_POST['pago_monto']);
    $fecha       = trim($_POST['pago_fecha']);
    $metodo      = trim($_POST['pago_metodo']);
    $referencia  = trim($_POST['pago_referencia']);
    $notas       = trim($_POST['pago_notas']);
    $user_id     = $_SESSION['user_id'];
    $stmt = $conn->prepare("INSERT INTO pagos_suscripcion (sucursal_id, plan_id, monto, fecha_pago, metodo, referencia, notas, registrado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iidssssi", $sucursal_id, $plan_id, $monto, $fecha, $metodo, $referencia, $notas, $user_id);
    $stmt->execute();
    $stmt->close();
    header("Location: ../../frontend/superadmin.php?page=pagos&msg=Pago+registrado+con+éxito");
    exit;
}

if ($action == 'delete_pago') {
    if (!csrf_validate()) { header("Location: ../../frontend/superadmin.php?page=pagos&msg=Error+de+seguridad"); exit; }
    csrf_regenerate();
    $id = intval($_POST['id']);
    $stmt = $conn->prepare("DELETE FROM pagos_suscripcion WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: ../../frontend/superadmin.php?page=pagos&msg=Pago+eliminado");
    exit;
}

// --- SUSCRIPCIONES ---
if ($action == 'assign_plan') {
    if (!csrf_validate()) { header("Location: ../../frontend/superadmin.php?page=estadisticas&msg=Error+de+seguridad"); exit; }
    csrf_regenerate();
    $sucursal_id = intval($_POST['sub_sucursal']);
    $plan_id     = intval($_POST['sub_plan']);
    $inicio      = trim($_POST['sub_inicio']);
    $fin         = suscripcion_fin_mensual($inicio);
    // Una sucursal tiene una sola suscripción activa: reemplazar la anterior.
    $d = $conn->prepare("DELETE FROM suscripciones WHERE sucursal_id = ?");
    $d->bind_param("i", $sucursal_id);
    $d->execute();
    $d->close();
    $stmt = $conn->prepare("INSERT INTO suscripciones (sucursal_id, plan_id, fecha_inicio, fecha_vencimiento, estado) VALUES (?, ?, ?, ?, 'activo')");
    $stmt->bind_param("iiss", $sucursal_id, $plan_id, $inicio, $fin);
    $stmt->execute();
    $stmt->close();
    header("Location: ../../frontend/superadmin.php?page=estadisticas&msg=Suscripción+asignada+correctamente");
    exit;
}

// --- SETTINGS ---
if ($action == 'save_settings') {
    if (!csrf_validate()) { header("Location: ../../frontend/superadmin.php?page=settings&msg=Error+de+seguridad"); exit; }
    csrf_regenerate();
    $nombre_negocio = trim($_POST['nombre_negocio']);
    $zelle_email    = trim($_POST['zelle_email']);
    $stmt = $conn->prepare("INSERT INTO configuracion (clave, valor, sucursal_id) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE valor = ?");
    foreach (['nombre_negocio' => $nombre_negocio, 'zelle_email' => $zelle_email] as $clave => $valor) {
        $stmt->bind_param("sss", $clave, $valor, $valor);
        $stmt->execute();
    }
    $stmt->close();
    header("Location: ../../frontend/superadmin.php?page=settings&msg=Configuración+actualizada");
    exit;
}

// --- ADMINISTRADORES ---
if ($action == 'add_admin') {
    if (!csrf_validate()) { header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Error+de+seguridad"); exit; }
    csrf_regenerate();
    $nombre    = trim($_POST['adm_nombre']   ?? '');
    $usuario   = trim($_POST['adm_usuario']  ?? '');
    $password  = trim($_POST['adm_password'] ?? '');
    $telefono  = trim($_POST['adm_telefono'] ?? '');
    $sucursal  = intval($_POST['adm_sucursal'] ?? 0);

    if (!$nombre || !$usuario || strlen($password) < 8 || !$sucursal) {
        header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Completa+todos+los+campos+%28contraseña+mín.+8+caracteres%29");
        exit;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO usuarios (usuario, password, nombre, telefono, rol, sucursal_id) VALUES (?, ?, ?, ?, 'admin', ?)");
    $stmt->bind_param("ssssi", $usuario, $hash, $nombre, $telefono, $sucursal);
    if ($stmt->execute()) {
        header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Administrador+creado+con+éxito");
    } else {
        header("Location: ../../frontend/superadmin.php?page=barbershops&msg=El+nombre+de+usuario+ya+existe");
    }
    $stmt->close();
    exit;
}

if ($action == 'edit_admin') {
    if (!csrf_validate()) { header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Error+de+seguridad"); exit; }
    csrf_regenerate();
    $id       = intval($_POST['adm_id']       ?? 0);
    $nombre   = trim($_POST['adm_nombre']     ?? '');
    $usuario  = trim($_POST['adm_usuario']    ?? '');
    $telefono = trim($_POST['adm_telefono']   ?? '');
    $sucursal = intval($_POST['adm_sucursal'] ?? 0);
    $password = trim($_POST['adm_password']   ?? '');

    if (!$id || !$nombre || !$usuario || !$sucursal) {
        header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Datos+incompletos");
        exit;
    }
    if ($password !== '') {
        if (strlen($password) < 8) {
            header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Contraseña+mínimo+8+caracteres");
            exit;
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE usuarios SET nombre=?, usuario=?, telefono=?, sucursal_id=?, password=? WHERE id=? AND rol='admin'");
        $stmt->bind_param("sssisi", $nombre, $usuario, $telefono, $sucursal, $hash, $id);
    } else {
        $stmt = $conn->prepare("UPDATE usuarios SET nombre=?, usuario=?, telefono=?, sucursal_id=? WHERE id=? AND rol='admin'");
        $stmt->bind_param("sssii", $nombre, $usuario, $telefono, $sucursal, $id);
    }
    $stmt->execute();
    $stmt->close();
    header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Administrador+actualizado");
    exit;
}

if ($action == 'delete_admin') {
    if (!csrf_validate()) { header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Error+de+seguridad"); exit; }
    csrf_regenerate();
    $id = intval($_POST['adm_id'] ?? 0);
    if ($id) {
        $stmt = $conn->prepare("DELETE FROM usuarios WHERE id=? AND rol='admin'");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: ../../frontend/superadmin.php?page=barbershops&msg=Administrador+eliminado");
    exit;
}
