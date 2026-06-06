<?php
// Forzar reporte de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Cargamos la conexión asegurando que no explote
require_once './backend/config/config.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Rate limiting
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_last_attempt'] = 0;
    }

    if ($_SESSION['login_attempts'] >= 5 && (time() - $_SESSION['login_last_attempt']) < 900) {
        $error = "Demasiados intentos fallidos. Espera 15 minutos antes de intentar de nuevo.";
    } else {
        // CSRF validation
        if (!csrf_validate()) {
            $error = "Token de seguridad inválido. Recarga la página.";
        } else {
            csrf_regenerate();

            $usuario = trim($_POST['usuario']);
            $password = trim($_POST['password']);

            if (!isset($conn) || $conn === null) {
                $error = "Error: El archivo conexion.php no pudo entregar el objeto de conexión (\$conn).";
            } else {
                $stmt = $conn->prepare("SELECT id, usuario, password, nombre, rol, barbero_id, telefono, sucursal_id FROM usuarios WHERE usuario = ?");

                if ($stmt) {
                    $stmt->bind_param("s", $usuario);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($user = $result->fetch_assoc()) {
                        if (password_verify($password, $user['password'])) {
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['nombre'] = $user['nombre'];
                            $_SESSION['rol'] = $user['rol'];
                            $_SESSION['barbero_id'] = $user['barbero_id'];
                            $_SESSION['telefono'] = $user['telefono'];
                            $_SESSION['sucursal_id'] = $user['sucursal_id'];

                            // Reset rate limiting on success
                            $_SESSION['login_attempts'] = 0;
                            $_SESSION['login_last_attempt'] = 0;

                            if ($user['rol'] == 'superadmin') { header("Location: ./frontend/superadmin.php"); exit; }
                            if ($user['rol'] == 'admin') { header("Location: ./frontend/admin.php"); exit; }
                            if ($user['rol'] == 'gerente') { header("Location: ./frontend/admin.php"); exit; }
                            if ($user['rol'] == 'barbero') { header("Location: ./frontend/barbero.php"); exit; }
                            if ($user['rol'] == 'cliente') { header("Location: ./frontend/cliente.php"); exit; }
                        } else {
                            $_SESSION['login_attempts']++;
                            $_SESSION['login_last_attempt'] = time();
                            $error = "Contraseña incorrecta para el usuario ingresado.";
                        }
                    } else {
                        $_SESSION['login_attempts']++;
                        $_SESSION['login_last_attempt'] = time();
                        $error = "El usuario '" . htmlspecialchars($usuario) . "' no existe en la base de datos.";
                    }
                    $stmt->close();
                } else {
                    $error = "Error al preparar la consulta en la base de datos: " . $conn->error;
                }
            }
        }
    }
} else {
    csrf_generate();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - AlCorte Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        body {
            background: #f9fafb;
            color: #111827;
            display: flex;
        }

        .login-container {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        /* LEFT PANEL - BRAND */
        .login-brand {
            flex: 1;
            background: linear-gradient(135deg, #0f0f0f 0%, #1a1a1a 100%);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 60px 40px;
            position: relative;
            overflow: hidden;
        }

        .login-brand::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(180, 147, 99, 0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .login-brand::after {
            content: '';
            position: absolute;
            bottom: -50%;
            left: -50%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(180, 147, 99, 0.05) 0%, transparent 70%);
            border-radius: 50%;
        }

        .brand-content {
            position: relative;
            z-index: 1;
            text-align: center;
        }

        .brand-icon {
            font-size: 80px;
            margin-bottom: 30px;
            color: #b49363;
        }

        .brand-title {
            font-size: 42px;
            font-weight: 700;
            margin-bottom: 15px;
            letter-spacing: -1px;
        }

        .brand-subtitle {
            font-size: 16px;
            color: rgba(255, 255, 255, 0.7);
            max-width: 300px;
            line-height: 1.6;
        }

        /* RIGHT PANEL - FORM */
        .login-form-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 60px 40px;
            background: #ffffff;
        }

        .login-form-wrapper {
            width: 100%;
            max-width: 380px;
        }

        .form-header {
            margin-bottom: 40px;
        }

        .form-header-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: 700;
            color: #b49363;
        }

        .form-header-logo i {
            font-size: 24px;
        }

        .form-header h1 {
            font-size: 32px;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }

        .form-header p {
            font-size: 14px;
            color: #6b7280;
            margin-top: 8px;
        }

        .error-banner {
            margin-bottom: 24px;
            padding: 14px 16px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            font-size: 14px;
            color: #7f1d1d;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .error-banner i {
            font-size: 16px;
            flex-shrink: 0;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            background: #ffffff;
            color: #111827;
            transition: all 0.2s;
        }

        .form-input:focus {
            outline: none;
            border-color: #b49363;
            box-shadow: 0 0 0 3px rgba(180, 147, 99, 0.1);
        }

        .form-input::placeholder {
            color: #9ca3af;
        }

        .password-toggle {
            position: relative;
        }

        .password-toggle-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            font-size: 16px;
            padding: 4px 8px;
            display: flex;
            align-items: center;
        }

        .password-toggle-btn:hover {
            color: #111827;
        }

        .form-submit {
            width: 100%;
            padding: 12px;
            margin-top: 28px;
            background: #18181b;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .form-submit:hover {
            background: #0f0f0f;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
        }

        .form-submit:active {
            transform: scale(0.98);
        }

        /* RESPONSIVE */
        @media (max-width: 1024px) {
            .login-container {
                flex-direction: column;
            }

            .login-brand {
                padding: 40px 20px;
                min-height: 200px;
            }

            .brand-icon {
                font-size: 60px;
                margin-bottom: 20px;
            }

            .brand-title {
                font-size: 32px;
                margin-bottom: 10px;
            }

            .login-form-container {
                padding: 40px 20px;
            }
        }

        @media (max-width: 640px) {
            .login-form-wrapper {
                max-width: 100%;
            }

            .form-header h1 {
                font-size: 24px;
            }

            .brand-icon {
                font-size: 48px;
            }

            .brand-title {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- LEFT PANEL -->
        <div class="login-brand">
            <div class="brand-content">
                <div class="brand-icon">
                    <i class="fas fa-scissors"></i>
                </div>
                <h2 class="brand-title">AlCorte Pro</h2>
                <p class="brand-subtitle">Sistema profesional de gestión de citas para barberías. Agenda inteligente, clientes satisfechos.</p>
            </div>
        </div>

        <!-- RIGHT PANEL -->
        <div class="login-form-container">
            <div class="login-form-wrapper">
                <div class="form-header">
                    <div class="form-header-logo">
                        <i class="fas fa-scissors"></i>
                        AlCorte
                    </div>
                    <h1>Bienvenido de vuelta</h1>
                    <p>Inicia sesión en tu cuenta para continuar</p>
                </div>

                <?php if(!empty($error)): ?>
                    <div class="error-banner">
                        <i class="fas fa-circle-xmark"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">

                    <div class="form-group">
                        <label class="form-label">Usuario</label>
                        <input type="text" name="usuario" class="form-input" placeholder="Ingresa tu usuario" required autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Contraseña</label>
                        <div class="password-toggle">
                            <input type="password" name="password" id="password" class="form-input" placeholder="Ingresa tu contraseña" required>
                            <button type="button" class="password-toggle-btn" onclick="togglePassword()">
                                <i class="fas fa-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="form-submit">
                        <i class="fas fa-sign-in-alt" style="margin-right: 8px;"></i>
                        Iniciar Sesión
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>

