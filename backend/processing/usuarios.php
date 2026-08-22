<?php
session_start();
require_once '../config/config.php';

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'superadmin') {
    header("Location: ../../");
    exit;
}

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

if ($action == 'crear_usuario') {
    if (!csrf_validate()) {
        header("Location: ../../frontend/superadmin.php?page=usuarios&msg=Error+de+seguridad");
        exit;
    }
    csrf_regenerate();

    $usuario = trim($_POST['usuario']);
    $nombre = trim($_POST['nombre']);
    $telefono = trim($_POST['telefono']);
    $password = trim($_POST['password']);
    $rol = trim($_POST['rol']);
    $sucursal_id = in_array($rol, ['admin', 'gerente', 'barbero']) ? intval($_POST['sucursal_id'] ?? 1) : null;

    if (empty($usuario) || empty($nombre) || empty($password) || empty($rol) || strlen($password) < 8) {
        header("Location: ../../frontend/superadmin.php?page=usuarios&msg=Datos+inválidos+o+contraseña+muy+corta");
        exit;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("INSERT INTO usuarios (usuario, password, nombre, telefono, rol, sucursal_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssi", $usuario, $hash, $nombre, $telefono, $rol, $sucursal_id);

    try {
        $stmt->execute();
        header("Location: ../../frontend/superadmin.php?page=usuarios&msg=Usuario+creado+exitosamente");
    } catch (\mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) {
            header("Location: ../../frontend/superadmin.php?page=usuarios&msg=El+nombre+de+usuario+ya+existe");
        } else {
            header("Location: ../../frontend/superadmin.php?page=usuarios&msg=Error+al+crear+usuario");
        }
    }
    $stmt->close();
    exit;
}

if ($action == 'editar_usuario') {
    if (!csrf_validate()) {
        header("Location: ../../frontend/superadmin.php?page=usuarios&msg=Error+de+seguridad");
        exit;
    }
    csrf_regenerate();

    $id = intval($_POST['id']);
    $nombre = trim($_POST['nombre']);
    $telefono = trim($_POST['telefono']);
    $password = trim($_POST['password']);
    $rol = trim($_POST['rol']);
    $sucursal_id = in_array($rol, ['admin', 'gerente', 'barbero']) ? intval($_POST['sucursal_id'] ?? 1) : null;

    if (empty($nombre) || empty($rol)) {
        header("Location: ../../frontend/superadmin.php?page=usuarios&msg=Datos+inválidos");
        exit;
    }

    if (!empty($password) && strlen($password) < 8) {
        header("Location: ../../frontend/superadmin.php?page=usuarios&id={$id}&msg=Contraseña+muy+corta");
        exit;
    }

    if (!empty($password)) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE usuarios SET nombre = ?, telefono = ?, password = ?, rol = ?, sucursal_id = ? WHERE id = ?");
        $stmt->bind_param("ssssii", $nombre, $telefono, $hash, $rol, $sucursal_id, $id);
    } else {
        $stmt = $conn->prepare("UPDATE usuarios SET nombre = ?, telefono = ?, rol = ?, sucursal_id = ? WHERE id = ?");
        $stmt->bind_param("sssii", $nombre, $telefono, $rol, $sucursal_id, $id);
    }

    if ($stmt->execute()) {
        header("Location: ../../frontend/superadmin.php?page=usuarios&msg=Usuario+actualizado+exitosamente");
    } else {
        header("Location: ../../frontend/superadmin.php?page=usuarios&msg=Error+al+actualizar+usuario");
    }
    $stmt->close();
    exit;
}

if ($action == 'eliminar_usuario') {
    if (!csrf_validate()) {
        header("Location: ../../frontend/usuarios.php?page=lista&msg=Error+de+seguridad");
        exit;
    }
    csrf_regenerate();

    $id = intval($_REQUEST['id']);

    if ($id === intval($_SESSION['user_id'])) {
        header("Location: ../../frontend/superadmin.php?page=usuarios&msg=No+puedes+eliminar+tu+propia+cuenta");
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        header("Location: ../../frontend/superadmin.php?page=usuarios&msg=Usuario+eliminado+exitosamente");
    } else {
        header("Location: ../../frontend/superadmin.php?page=usuarios&msg=Error+al+eliminar+usuario");
    }
    $stmt->close();
    exit;
}

header("Location: ../../frontend/superadmin.php?page=usuarios");
exit;
?>

