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

if ($action == 'crear_usuario') {
    if (!csrf_validate()) {
        api_usuarios_finish('Error de seguridad', 'usuarios');
    }
    csrf_regenerate();

    $usuario = trim($_POST['usuario']);
    $nombre = trim($_POST['nombre']);
    $telefono = trim($_POST['telefono']);
    $password = trim($_POST['password']);
    $rol = trim($_POST['rol']);
    $sucursal_id = in_array($rol, ['admin', 'gerente', 'barbero'], true) ? intval($_POST['sucursal_id'] ?? 1) : null;

    if (empty($usuario) || empty($nombre) || empty($password) || empty($rol) || strlen($password) < 8) {
        api_usuarios_finish('Datos inválidos o contraseña muy corta', 'usuarios');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $conn->prepare('INSERT INTO usuarios (usuario, password, nombre, telefono, rol, sucursal_id) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('sssssi', $usuario, $hash, $nombre, $telefono, $rol, $sucursal_id);

    try {
        $stmt->execute();
        api_usuarios_finish('Usuario creado exitosamente', 'usuarios');
    } catch (\mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) {
            api_usuarios_finish('El nombre de usuario ya existe', 'usuarios');
        }
        api_usuarios_finish('Error al crear usuario', 'usuarios');
    }
    $stmt->close();
}

if ($action == 'editar_usuario') {
    if (!csrf_validate()) {
        api_usuarios_finish('Error de seguridad', 'usuarios');
    }
    csrf_regenerate();

    $id = intval($_POST['id']);
    $nombre = trim($_POST['nombre']);
    $telefono = trim($_POST['telefono']);
    $password = trim($_POST['password']);
    $rol = trim($_POST['rol']);
    $sucursal_id = in_array($rol, ['admin', 'gerente', 'barbero'], true) ? intval($_POST['sucursal_id'] ?? 1) : null;

    if (empty($nombre) || empty($rol)) {
        api_usuarios_finish('Datos inválidos', 'usuarios');
    }

    if (!empty($password) && strlen($password) < 8) {
        api_usuarios_finish('Contraseña muy corta', 'usuarios');
    }

    if (!empty($password)) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare('UPDATE usuarios SET nombre = ?, telefono = ?, password = ?, rol = ?, sucursal_id = ? WHERE id = ?');
        $stmt->bind_param('ssssii', $nombre, $telefono, $hash, $rol, $sucursal_id, $id);
    } else {
        $stmt = $conn->prepare('UPDATE usuarios SET nombre = ?, telefono = ?, rol = ?, sucursal_id = ? WHERE id = ?');
        $stmt->bind_param('sssii', $nombre, $telefono, $rol, $sucursal_id, $id);
    }

    if ($stmt->execute()) {
        api_usuarios_finish('Usuario actualizado exitosamente', 'usuarios');
    }
    api_usuarios_finish('Error al actualizar usuario', 'usuarios');
    $stmt->close();
}

if ($action == 'eliminar_usuario') {
    if (!csrf_validate()) {
        api_usuarios_finish('Error de seguridad', 'usuarios');
    }
    csrf_regenerate();

    $id = intval($_REQUEST['id']);

    if ($id === intval($_SESSION['user_id'])) {
        api_usuarios_finish('No puedes eliminar tu propia cuenta', 'usuarios');
    }

    $stmt = $conn->prepare('DELETE FROM usuarios WHERE id = ?');
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        api_usuarios_finish('Usuario eliminado exitosamente', 'usuarios');
    }
    api_usuarios_finish('Error al eliminar usuario', 'usuarios');
    $stmt->close();
}

if (defined('ALCORTE_API_REQUEST')) {
    api_err('Acción no reconocida', 400);
}

header('Location: ../../frontend/superadmin.php?page=usuarios');
exit;
