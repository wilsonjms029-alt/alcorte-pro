<?php
// La configuración central gestiona errores, conexión y sesión.
require_once './backend/config/config.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Si ya tiene sesión activa, redirigir según su rol
if (isset($_SESSION['user_id'])) {
    $rol = $_SESSION['rol'] ?? 'admin';
    if ($rol === 'superadmin') {
        header('Location: frontend/superadmin.php');
        exit;
    } elseif ($rol === 'barbero') {
        header('Location: frontend/barbero.php');
        exit;
    } else {
        header('Location: frontend/admin.php');
        exit;
    }
}

$error = '';
csrf_generate();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión — AlCorte Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --gold-primary: #D4AF37;
            --gold-secondary: #AA8C2C;
            --gold-glow: rgba(212, 175, 55, 0.3);
            --dark-bg: #07090e;
            --dark-surface: #0f131c;
            --dark-surface-elevated: #161b26;
            --text-primary: #FFFFFF;
            --text-secondary: #94a3b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            font-family: 'Outfit', sans-serif;
            background-color: var(--dark-bg);
            color: var(--text-primary);
        }

        .login-container {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        /* LEFT PANEL - BRAND */
        .login-brand {
            flex: 1.2;
            background: linear-gradient(135deg, rgba(7,9,14,0.92) 0%, rgba(15,19,28,0.97) 100%), 
                        url('https://images.unsplash.com/photo-1560066984-138dadb4c035?ixlib=rb-4.0.3&auto=format&fit=crop&w=2000&q=80') center/cover;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            position: relative;
            overflow: hidden;
            border-right: 1px solid rgba(212, 175, 55, 0.15);
        }

        .login-brand::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at center, transparent 0%, var(--dark-bg) 100%);
            z-index: 1;
        }

        .brand-content {
            position: relative;
            z-index: 2;
            text-align: center;
            padding: 40px;
            max-width: 520px;
            animation: fadeIn 1.2s ease-out;
        }

        .brand-icon {
            font-size: 80px;
            margin-bottom: 20px;
            background: linear-gradient(135deg, var(--gold-primary), #F3E5AB);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 4px 18px var(--gold-glow));
            transform: translateY(0);
            animation: float 6s ease-in-out infinite;
        }

        .brand-title {
            font-size: 50px;
            font-weight: 800;
            margin-bottom: 14px;
            letter-spacing: -1px;
            background: linear-gradient(to right, #FFFFFF, var(--gold-primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .brand-subtitle {
            font-size: 16px;
            color: var(--text-secondary);
            line-height: 1.6;
            margin: 0 auto 24px auto;
            font-weight: 300;
        }

        .brand-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: center;
            margin-top: 20px;
        }

        .badge-chip {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(212, 175, 55, 0.25);
            border-radius: 999px;
            padding: 6px 14px;
            font-size: 12px;
            color: #e2e8f0;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-chip i {
            color: var(--gold-primary);
        }

        /* RIGHT PANEL - FORM */
        .login-form-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px 30px;
            background: var(--dark-bg);
            position: relative;
        }

        .login-form-container::before {
            content: '';
            position: absolute;
            width: 480px;
            height: 480px;
            background: radial-gradient(circle, var(--gold-glow) 0%, transparent 70%);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.25;
            pointer-events: none;
        }

        .login-form-wrapper {
            width: 100%;
            max-width: 420px;
            position: relative;
            z-index: 1;
            background: rgba(15, 19, 28, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 45px 36px;
            border-radius: 24px;
            border: 1px solid rgba(212, 175, 55, 0.2);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
            animation: slideUp 0.8s ease-out;
        }

        .form-header {
            margin-bottom: 32px;
            text-align: center;
        }

        .form-header h1 {
            font-size: 30px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .form-header p {
            font-size: 14px;
            color: var(--text-secondary);
            font-weight: 300;
        }

        .back-home {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--gold-primary);
            text-decoration: none;
            margin-bottom: 20px;
            transition: opacity 0.2s;
        }
        .back-home:hover {
            opacity: 0.8;
            text-decoration: underline;
        }

        .form-group {
            margin-bottom: 22px;
            position: relative;
        }

        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-input-wrapper {
            position: relative;
        }

        .form-input-wrapper i.icon-left {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 16px;
            transition: color 0.3s;
            pointer-events: none;
        }

        .form-input {
            width: 100%;
            padding: 14px 16px 14px 44px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 12px;
            font-size: 15px;
            font-family: inherit;
            color: var(--text-primary);
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.07);
            border-color: var(--gold-primary);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.15);
        }

        .form-input:focus + i.icon-left,
        .form-input-wrapper:focus-within i.icon-left {
            color: var(--gold-primary);
        }

        .form-input::placeholder {
            color: rgba(255, 255, 255, 0.25);
        }

        .password-toggle-btn {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 16px;
            padding: 4px;
            transition: color 0.3s;
        }

        .password-toggle-btn:hover {
            color: var(--gold-primary);
        }

        .form-submit {
            width: 100%;
            padding: 15px;
            margin-top: 10px;
            background: linear-gradient(135deg, var(--gold-primary) 0%, var(--gold-secondary) 100%);
            color: #0b0f19;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px var(--gold-glow);
        }

        .form-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(212, 175, 55, 0.4);
        }

        .form-footer {
            margin-top: 24px;
            text-align: center;
            font-size: 13px;
            color: var(--text-secondary);
        }
        .form-footer a {
            color: var(--gold-primary);
            text-decoration: none;
            font-weight: 600;
        }
        .form-footer a:hover {
            text-decoration: underline;
        }

        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(25px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 900px) {
            .login-brand { display: none; }
            .login-form-container { min-height: 100vh; padding: 24px 18px; }
            .login-form-wrapper { padding: 36px 24px; }
        }
    </style>
    <meta name="alcorte-base" content="<?php echo htmlspecialchars(project_base_url()); ?>">
    <script src="frontend/assets/api.js" defer></script>
</head>
<body>
    <div class="login-container">
        <!-- LEFT PANEL -->
        <div class="login-brand">
            <div class="brand-content">
                <div class="brand-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <h2 class="brand-title">AlCorte Pro</h2>
                <p class="brand-subtitle">Gestión inteligente de citas y clientes para Negocios de Belleza & Bienestar.</p>
                <div class="brand-badges">
                    <span class="badge-chip"><i class="fas fa-cut"></i> Barberías</span>
                    <span class="badge-chip"><i class="fas fa-spa"></i> Spas & Estéticas</span>
                    <span class="badge-chip"><i class="fas fa-hand-sparkles"></i> Salones de Uñas</span>
                    <span class="badge-chip"><i class="fas fa-eye"></i> Pestañas & Cejas</span>
                    <span class="badge-chip"><i class="fas fa-paint-brush"></i> Tatuajes</span>
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL -->
        <div class="login-form-container">
            <div class="login-form-wrapper">
                <a href="index.php" class="back-home">
                    <i class="fas fa-arrow-left"></i> Volver a la página principal
                </a>
                
                <div class="form-header">
                    <h1>Acceso al Sistema</h1>
                    <p>Ingresa tus credenciales para administrar tu negocio</p>
                </div>

                <form method="POST" id="loginForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

                    <div class="form-group">
                        <label class="form-label">Usuario</label>
                        <div class="form-input-wrapper">
                            <i class="fas fa-user icon-left"></i>
                            <input type="text" name="usuario" class="form-input" placeholder="Tu usuario asignado" required autocomplete="username">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Contraseña</label>
                        <div class="form-input-wrapper">
                            <i class="fas fa-lock icon-left"></i>
                            <input type="password" name="password" id="password" class="form-input" placeholder="Tu contraseña" required autocomplete="current-password">
                            <button type="button" class="password-toggle-btn" onclick="togglePassword()" title="Mostrar/Ocultar contraseña">
                                <i class="fas fa-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="form-submit" id="loginSubmit">
                        <i class="fas fa-arrow-right-to-bracket" style="margin-right: 8px;"></i>
                        Ingresar al Panel
                    </button>
                </form>

                <div class="form-footer">
                    ¿Aún no tienes cuenta? <a href="index.php#planes">Ver Planes y Precios</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        document.getElementById('loginForm')?.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = document.getElementById('loginSubmit');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verificando...';
            }
            var fd = new FormData(this);
            if (window.AlCorte) {
                AlCorte.postJson('auth/login', {
                    csrf_token: fd.get('csrf_token'),
                    usuario: fd.get('usuario'),
                    password: fd.get('password'),
                }).then(function (data) {
                    window.location.href = data.data.redirect || './frontend/admin.php';
                }).catch(function (err) {
                    alert(err.message || 'Error al iniciar sesión');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fas fa-arrow-right-to-bracket" style="margin-right:8px;"></i> Ingresar al Panel';
                    }
                });
            }
        });
    </script>
</body>
</html>
