<?php
session_start();
require_once '../config/config.php';

$allowed_roles = ['admin', 'superadmin', 'gerente'];
if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], $allowed_roles)) {
    header("Location: ../../");
    exit;
}

// Scoping helper
function get_scope_sucursal_id(): ?int {
    $rol = $_SESSION['rol'];
    if (in_array($rol, ['superadmin', 'admin'])) return null;
    if ($rol === 'gerente') return intval($_SESSION['sucursal_id'] ?? 0) ?: null;
    return null;
}

$scope_id = get_scope_sucursal_id();
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

// --- CRUD: CLIENTES ---
if ($action == 'add_cliente') {
    if (!csrf_validate()) {
        header("Location: ../../frontend/admin.php?page=clientes&msg=Error+de+seguridad");
        exit;
    }
    csrf_regenerate();

    $nombre = trim($_POST['nombre']);
    $telefono = trim($_POST['telefono']);
    $puntos = intval($_POST['puntos']);

    $stmt = $conn->prepare("INSERT INTO clientes (telefono, nombre, puntos, ultima_visita) VALUES (?, ?, ?, CURDATE()) ON DUPLICATE KEY UPDATE nombre=?, puntos=?");
    $stmt->bind_param("ssisi", $telefono, $nombre, $puntos, $nombre, $puntos);
    $stmt->execute();
    $stmt->close();
    header("Location: ../../frontend/admin.php?page=clientes&msg=Cliente+guardado+correctamente");
    exit;
}

if ($action == 'delete_cliente') {
    if (!csrf_validate()) {
        header("Location: ../../frontend/admin.php?page=clientes&msg=Error+de+seguridad");
        exit;
    }
    csrf_regenerate();

    $telefono = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM clientes WHERE telefono = ?");
    $stmt->bind_param("s", $telefono);
    $stmt->execute();
    $stmt->close();
    header("Location: ../../frontend/admin.php?page=clientes&msg=Cliente+eliminado+de+la+base+de+datos");
    exit;
}

// --- CRUD: PERSONAL / BARBEROS ---
if ($action == 'add_barbero') {
    if (!csrf_validate()) {
        header("Location: ../../frontend/admin.php?page=personal&msg=Error+de+seguridad");
        exit;
    }
    csrf_regenerate();

    $nombre          = trim($_POST['nombre']);
    $hora_inicio     = $_POST['hora_inicio'];
    $hora_fin        = $_POST['hora_fin'];
    $almuerzo_inicio = $_POST['almuerzo_inicio'];
    $almuerzo_fin    = $_POST['almuerzo_fin'];
    $sucursal_id     = ($scope_id !== null) ? $scope_id : intval($_POST['sucursal_id'] ?? 0);
    if ($sucursal_id <= 1) {
        // Sin tienda elegida: usar la primera tienda real disponible
        $row = $conn->query("SELECT MIN(id) AS id FROM sucursales WHERE id > 1")->fetch_assoc();
        $sucursal_id = intval($row['id'] ?? 0) ?: 1;
    }
    $barb_usuario    = trim($_POST['barb_usuario'] ?? '');
    $barb_password   = trim($_POST['barb_password'] ?? '');

    $foto_url = 'https://ui-avatars.com/api/?background=333&color=fff&name=' . urlencode($nombre);

    $stmt = $conn->prepare("INSERT INTO barberos (nombre, foto_url, hora_inicio, hora_fin, almuerzo_inicio, almuerzo_fin, activo, sucursal_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
    $stmt->bind_param("ssssssi", $nombre, $foto_url, $hora_inicio, $hora_fin, $almuerzo_inicio, $almuerzo_fin, $sucursal_id);
    $stmt->execute();
    $nuevo_barbero_id = $stmt->insert_id;
    $stmt->close();

    // Crear usuario si se proporcionaron credenciales
    if ($barb_usuario !== '' && $barb_password !== '') {
        if (strlen($barb_password) < 8) {
            header("Location: ../../frontend/admin.php?page=personal&msg=Barbero+creado+pero+la+contraseña+debe+tener+mínimo+8+caracteres");
            exit;
        }
        $hash = password_hash($barb_password, PASSWORD_BCRYPT);
        $stmt2 = $conn->prepare("INSERT INTO usuarios (usuario, password, nombre, rol, sucursal_id, barbero_id) VALUES (?, ?, ?, 'barbero', ?, ?)");
        $stmt2->bind_param("sssii", $barb_usuario, $hash, $nombre, $sucursal_id, $nuevo_barbero_id);
        if (!$stmt2->execute()) {
            $stmt2->close();
            header("Location: ../../frontend/admin.php?page=personal&msg=Barbero+creado+pero+el+usuario+ya+existe");
            exit;
        }
        $stmt2->close();
        header("Location: ../../frontend/admin.php?page=personal&msg=Barbero+y+usuario+creados+exitosamente");
    } else {
        header("Location: ../../frontend/admin.php?page=personal&msg=Barbero+registrado+exitosamente");
    }
    exit;
}

if ($action == 'edit_barbero') {
    if (!csrf_validate()) {
        header("Location: ../../frontend/admin.php?page=personal&msg=Error+de+seguridad");
        exit;
    }
    csrf_regenerate();

    $id = intval($_POST['id']);
    $nombre = trim($_POST['nombre']);
    $hora_inicio = $_POST['hora_inicio'];
    $hora_fin = $_POST['hora_fin'];
    $almuerzo_inicio = $_POST['almuerzo_inicio'];
    $almuerzo_fin = $_POST['almuerzo_fin'];
    $activo = isset($_POST['activo']) ? 1 : 0;

    if ($scope_id !== null) {
        $stmt = $conn->prepare("UPDATE barberos SET nombre = ?, hora_inicio = ?, hora_fin = ?, almuerzo_inicio = ?, almuerzo_fin = ?, activo = ? WHERE id = ? AND sucursal_id = ?");
        $stmt->bind_param("sssssiii", $nombre, $hora_inicio, $hora_fin, $almuerzo_inicio, $almuerzo_fin, $activo, $id, $scope_id);
    } else {
        $stmt = $conn->prepare("UPDATE barberos SET nombre = ?, hora_inicio = ?, hora_fin = ?, almuerzo_inicio = ?, almuerzo_fin = ?, activo = ? WHERE id = ?");
        $stmt->bind_param("sssssii", $nombre, $hora_inicio, $hora_fin, $almuerzo_inicio, $almuerzo_fin, $activo, $id);
    }
    $stmt->execute();
    $stmt->close();
    header("Location: ../../frontend/admin.php?page=personal&msg=Barbero+actualizado");
    exit;
}

if ($action == 'delete_barbero') {
    if (!csrf_validate()) {
        header("Location: ../../frontend/admin.php?page=personal&msg=Error+de+seguridad");
        exit;
    }
    csrf_regenerate();

    $id = intval($_POST['id']);

    // Eliminar usuario vinculado al barbero
    $stmt = $conn->prepare("DELETE FROM usuarios WHERE barbero_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // Eliminar el barbero
    if ($scope_id !== null) {
        $stmt = $conn->prepare("DELETE FROM barberos WHERE id = ? AND sucursal_id = ?");
        $stmt->bind_param("ii", $id, $scope_id);
    } else {
        $stmt = $conn->prepare("DELETE FROM barberos WHERE id = ?");
        $stmt->bind_param("i", $id);
    }
    $stmt->execute();
    $stmt->close();
    header("Location: ../../frontend/admin.php?page=personal&msg=Barbero+y+usuario+eliminados+correctamente");
    exit;
}

// --- CRUD: SERVICIOS ---
if ($action == 'add_servicio') {
    if (!csrf_validate()) { header("Location: ../../frontend/admin.php?page=servicios&msg=Error+de+seguridad"); exit; }
    csrf_regenerate();
    $nombre   = trim($_POST['svc_nombre'] ?? '');
    $duracion = trim($_POST['svc_duracion'] ?? '30 min');
    $precio   = trim($_POST['svc_precio'] ?? '');
    $icono    = trim($_POST['svc_icono'] ?? 'fas fa-cut');
    $orden    = intval($_POST['svc_orden'] ?? 0);
    $suc_id   = ($scope_id !== null) ? $scope_id : 1;
    if ($nombre) {
        $stmt = $conn->prepare("INSERT INTO servicios (nombre, duracion, precio, icono, orden, sucursal_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssii", $nombre, $duracion, $precio, $icono, $orden, $suc_id);
        $stmt->execute(); $stmt->close();
    }
    header("Location: ../../frontend/admin.php?page=servicios&msg=Servicio+agregado"); exit;
}

if ($action == 'edit_servicio') {
    if (!csrf_validate()) { header("Location: ../../frontend/admin.php?page=servicios&msg=Error+de+seguridad"); exit; }
    csrf_regenerate();
    $id       = intval($_POST['svc_id']);
    $nombre   = trim($_POST['svc_nombre'] ?? '');
    $duracion = trim($_POST['svc_duracion'] ?? '30 min');
    $precio   = trim($_POST['svc_precio'] ?? '');
    $icono    = trim($_POST['svc_icono'] ?? 'fas fa-cut');
    $activo   = isset($_POST['svc_activo']) ? 1 : 0;
    $orden    = intval($_POST['svc_orden'] ?? 0);
    $suc_id   = ($scope_id !== null) ? $scope_id : 1;
    $stmt = $conn->prepare("UPDATE servicios SET nombre=?, duracion=?, precio=?, icono=?, activo=?, orden=? WHERE id=? AND sucursal_id=?");
    $stmt->bind_param("ssssiiii", $nombre, $duracion, $precio, $icono, $activo, $orden, $id, $suc_id);
    $stmt->execute(); $stmt->close();
    header("Location: ../../frontend/admin.php?page=servicios&msg=Servicio+actualizado"); exit;
}

if ($action == 'delete_servicio') {
    if (!csrf_validate()) { header("Location: ../../frontend/admin.php?page=servicios&msg=Error+de+seguridad"); exit; }
    csrf_regenerate();
    $id     = intval($_POST['svc_id']);
    $suc_id = ($scope_id !== null) ? $scope_id : 1;
    $stmt = $conn->prepare("DELETE FROM servicios WHERE id=? AND sucursal_id=?");
    $stmt->bind_param("ii", $id, $suc_id); $stmt->execute(); $stmt->close();
    header("Location: ../../frontend/admin.php?page=servicios&msg=Servicio+eliminado"); exit;
}

// --- ACTUALIZAR CONFIGURACIÓN DE MÉTODOS DE PAGO Y DATOS DE CUENTA ---
if ($action == 'update_sys_settings') {
    if (!csrf_validate()) {
        header("Location: ../../frontend/admin.php?page=ajustes&msg=Error+de+seguridad");
        exit;
    }
    csrf_regenerate();

    $conf_sucursal = ($scope_id !== null) ? $scope_id : 1;

    $estado_pago_movil = isset($_POST['estado_pago_movil']) ? '1' : '0';
    $estado_zelle = isset($_POST['estado_zelle']) ? '1' : '0';
    $estado_efectivo = isset($_POST['estado_efectivo']) ? '1' : '0';

    $banco_nombre = trim($_POST['banco_nombre']);
    $banco_telefono = trim($_POST['banco_telefono']);
    $banco_ci = trim($_POST['banco_ci']);
    $zelle_email = trim($_POST['zelle_email']);

    $payload = [
        'estado_pago_movil'  => $estado_pago_movil,
        'estado_zelle'       => $estado_zelle,
        'estado_efectivo'    => $estado_efectivo,
        'banco_nombre'       => $banco_nombre,
        'banco_telefono'     => $banco_telefono,
        'banco_ci'           => $banco_ci,
        'zelle_email'        => $zelle_email,
        'direccion'          => trim($_POST['direccion'] ?? ''),
        'horario'            => trim($_POST['horario'] ?? ''),
        'contacto'           => trim($_POST['contacto'] ?? ''),
        'politica_reserva'   => trim($_POST['politica_reserva'] ?? ''),
    ];

    $stmt = $conn->prepare("INSERT INTO configuracion (clave, valor, sucursal_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor = ?");
    foreach ($payload as $clave => $valor) {
        $stmt->bind_param("ssss", $clave, $valor, $conf_sucursal, $valor);
        $stmt->execute();
    }
    $stmt->close();

    header("Location: ../../frontend/admin.php?page=ajustes&msg=Configuración+de+pagos+guardada");
    exit;
}
?>

