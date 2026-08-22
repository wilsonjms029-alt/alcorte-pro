<?php
// La configuración central gestiona errores, conexión y sesión.
require_once './backend/config/config.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$error = '';
csrf_generate();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - AlCorte Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --gold-primary: #D4AF37;
            --gold-secondary: #AA8C2C;
            --gold-glow: rgba(212, 175, 55, 0.3);
            --dark-bg: #050505;
            --dark-surface: #121212;
            --dark-surface-elevated: #1E1E1E;
            --text-primary: #FFFFFF;
            --text-secondary: #A0A0A0;
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
            background: linear-gradient(135deg, rgba(5,5,5,0.92) 0%, rgba(18,18,18,0.97) 100%), 
                        url('https://images.unsplash.com/photo-1585747860715-2ba37e788b70?ixlib=rb-4.0.3&auto=format&fit=crop&w=2074&q=80') center/cover;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            position: relative;
            overflow: hidden;
            border-right: 1px solid rgba(212, 175, 55, 0.1);
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
            animation: fadeIn 1.2s ease-out;
        }

        .brand-icon {
            font-size: 90px;
            margin-bottom: 24px;
            background: linear-gradient(135deg, var(--gold-primary), #F3E5AB);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 4px 15px var(--gold-glow));
            transform: translateY(0);
            animation: float 6s ease-in-out infinite;
        }

        .brand-title {
            font-size: 56px;
            font-weight: 700;
            margin-bottom: 16px;
            letter-spacing: -1px;
            background: linear-gradient(to right, #FFFFFF, var(--gold-primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .brand-subtitle {
            font-size: 18px;
            color: var(--text-secondary);
            max-width: 400px;
            line-height: 1.6;
            margin: 0 auto;
            font-weight: 300;
        }

        /* RIGHT PANEL - FORM */
        .login-form-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 60px 40px;
            background: var(--dark-bg);
            position: relative;
        }

        /* Subtle gold glow behind the form */
        .login-form-container::before {
            content: '';
            position: absolute;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, var(--gold-glow) 0%, transparent 70%);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.3;
            pointer-events: none;
        }

        .login-form-wrapper {
            width: 100%;
            max-width: 420px;
            position: relative;
            z-index: 1;
            background: rgba(18, 18, 18, 0.7);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            padding: 50px 40px;
            border-radius: 24px;
            border: 1px solid rgba(212, 175, 55, 0.15);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: slideUp 0.8s ease-out;
        }

        .form-header {
            margin-bottom: 40px;
            text-align: center;
        }

        .form-header h1 {
            font-size: 32px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 10px;
            letter-spacing: -0.5px;
        }

        .form-header p {
            font-size: 15px;
            color: var(--text-secondary);
            font-weight: 300;
        }

        .error-banner {
            margin-bottom: 24px;
            padding: 16px;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 12px;
            font-size: 14px;
            color: #EF4444;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: shake 0.5s ease-in-out;
        }

        .form-group {
            margin-bottom: 24px;
            position: relative;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: color 0.3s;
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
            font-size: 18px;
            transition: color 0.3s;
            pointer-events: none;
        }

        .form-input {
            width: 100%;
            padding: 16px 16px 16px 48px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            font-size: 15px;
            font-family: inherit;
            color: var(--text-primary);
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--gold-primary);
            box-shadow: 0 0 0 4px rgba(212, 175, 55, 0.1);
        }

        .form-input:focus + i.icon-left,
        .form-input-wrapper:focus-within i.icon-left {
            color: var(--gold-primary);
        }

        .form-input::placeholder {
            color: rgba(255, 255, 255, 0.2);
        }

        .password-toggle-btn {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 18px;
            padding: 4px;
            transition: color 0.3s;
        }

        .password-toggle-btn:hover {
            color: var(--gold-primary);
        }

        .form-submit {
            width: 100%;
            padding: 16px;
            margin-top: 12px;
            background: linear-gradient(135deg, var(--gold-primary) 0%, var(--gold-secondary) 100%);
            color: #000000;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px var(--gold-glow);
        }

        .form-submit::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            transition: left 0.5s ease;
        }

        .form-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(212, 175, 55, 0.4);
        }

        .form-submit:hover::before {
            left: 100%;
        }

        .form-submit:active {
            transform: translateY(1px);
        }

        /* Animations */
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-12px); }
            100% { transform: translateY(0px); }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }

        /* RESPONSIVE */
        .mobile-brand-logo {
            display: none;
        }

        @media (max-width: 900px) {
            .login-brand {
                display: none;
            }

            .login-form-container {
                min-height: 100vh;
                padding: 24px 20px;
                justify-content: center;
            }

            .login-form-wrapper {
                padding: 40px 24px;
                border-radius: 20px;
                background: rgba(18, 18, 18, 0.85);
            }

            .mobile-brand-logo {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 12px;
                font-size: 26px;
                font-weight: 700;
                color: var(--gold-primary);
                margin-bottom: 24px;
                letter-spacing: -0.5px;
            }

            .mobile-brand-logo i {
                font-size: 28px;
            }
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
                    <i class="fas fa-cut"></i>
                </div>
                <h2 class="brand-title">AlCorte Pro</h2>
                <p class="brand-subtitle">Eleva el estándar de tu barbería. Gestión inteligente, diseño premium.</p>
            </div>
        </div>

        <!-- RIGHT PANEL -->
        <div class="login-form-container">
            <div class="login-form-wrapper">
                <div class="form-header">
                    <div class="mobile-brand-logo">
                        <i class="fas fa-cut"></i>
                        <span>AlCorte Pro</span>
                    </div>
                    <h1>Bienvenido</h1>
                    <p>Inicia sesión para acceder al panel</p>
                </div>

                <?php if(!empty($error)): ?>
                    <div class="error-banner">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" id="loginForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

                    <div class="form-group">
                        <label class="form-label">Usuario</label>
                        <div class="form-input-wrapper">
                            <i class="fas fa-user icon-left"></i>
                            <input type="text" name="usuario" class="form-input" placeholder="Tu nombre de usuario" required autocomplete="off">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Contraseña</label>
                        <div class="form-input-wrapper">
                            <i class="fas fa-lock icon-left"></i>
                            <input type="password" name="password" id="password" class="form-input" placeholder="Tu contraseña secreta" required>
                            <button type="button" class="password-toggle-btn" onclick="togglePassword()" title="Mostrar/Ocultar contraseña">
                                <i class="fas fa-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="form-submit" id="loginSubmit">
                        <i class="fas fa-arrow-right-to-bracket" style="margin-right: 8px;"></i>
                        Ingresar al Sistema
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
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        document.getElementById('loginForm')?.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = document.getElementById('loginSubmit');
            if (btn) btn.disabled = true;
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
                    if (btn) btn.disabled = false;
                });
            }
        });

        // Animación sutil en el foco de los inputs
        document.querySelectorAll('.form-input').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'translateY(-2px)';
                this.parentElement.style.transition = 'transform 0.3s ease';
            });
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>
</html>

