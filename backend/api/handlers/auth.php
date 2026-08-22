<?php
declare(strict_types=1);

$action = api_route_sub() ?: (api_input()['action'] ?? '');

if ($action === 'login' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $input = api_input();

    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_last_attempt'] = 0;
    }

    if ($_SESSION['login_attempts'] >= 5 && (time() - $_SESSION['login_last_attempt']) < 900) {
        api_err('Demasiados intentos fallidos. Espera 15 minutos antes de intentar de nuevo.', 429);
    }

    api_require_csrf($input);

    $usuario = trim($input['usuario'] ?? '');
    $password = trim($input['password'] ?? '');

    if ($usuario === '' || $password === '') {
        api_err('Usuario y contraseña requeridos');
    }

    $stmt = $conn->prepare(
        'SELECT id, usuario, password, nombre, rol, barbero_id, telefono, sucursal_id FROM usuarios WHERE usuario = ?'
    );
    $stmt->bind_param('s', $usuario);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !password_verify($password, $user['password'])) {
        $_SESSION['login_attempts']++;
        $_SESSION['login_last_attempt'] = time();
        api_err('Usuario o contraseña incorrectos', 401);
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['nombre'] = $user['nombre'];
    $_SESSION['rol'] = $user['rol'];
    $_SESSION['barbero_id'] = $user['barbero_id'];
    $_SESSION['telefono'] = $user['telefono'];
    $_SESSION['sucursal_id'] = $user['sucursal_id'];
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_last_attempt'] = 0;

    $redirect = './frontend/cliente.php';
    $rol = $user['rol'];

    if ($rol === 'superadmin') {
        $redirect = './frontend/superadmin.php';
    } elseif ($rol === 'admin' || $rol === 'gerente') {
        $redirect = './frontend/admin.php';
    } elseif ($rol === 'barbero') {
        $suc_id = (int) ($user['sucursal_id'] ?? 0);
        $plan_activo = get_plan_sucursal($conn, $suc_id);
        $is_basic = $plan_activo && $plan_activo['nombre'] === 'Básico';
        if ($is_basic) {
            session_destroy();
            api_err('El plan básico no incluye acceso al panel de barbero.', 403);
        }
        $redirect = './frontend/barbero.php';
    }

    api_ok([
        'redirect' => $redirect,
        'rol' => $rol,
        'nombre' => $user['nombre'],
    ], 'Inicio de sesión exitoso');
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    api_ok(['redirect' => './'], 'Sesión cerrada');
}

api_err('Acción de autenticación no válida', 400);
