<?php
require_once '../backend/config/config.php';

$msg      = "";
$msg_type = "success";
$tab      = isset($_GET['tab']) ? $_GET['tab'] : 'reservar';

// Servicios disponibles (catálogo)
$servicios = [
    "Corte Clásico"    => "30 min — Bs 8",
    "Barba Premium"    => "20 min — Bs 6",
    "Combo AlCorte Pro"=> "50 min — Bs 12",
    "Corte + Cejas"    => "40 min — Bs 10",
];

// PROCESAR RESERVA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'agendar') {
    if (!csrf_validate()) {
        $msg = "Error de seguridad. Recarga la página.";
        $msg_type = "error";
    } else {
        csrf_regenerate();

        $nombre    = trim($_POST['cliente_nombre'] ?? '');
        $telefono  = trim($_POST['cliente_telefono'] ?? '');
        $barbero   = intval($_POST['barbero_id'] ?? 0);
        $servicio  = trim($_POST['servicio'] ?? '');
        $fecha     = trim($_POST['fecha'] ?? '');
        $hora      = trim($_POST['hora'] ?? '');
        $metodo    = trim($_POST['metodo_pago'] ?? '');
        $referencia = trim($_POST['referencia_pago'] ?? '');
        $tel_digitos = preg_replace('/\D/', '', $telefono);

        // ─── Validación de servidor (no confiar solo en el navegador) ───
        $error_val = null;
        if (!$nombre || !$telefono || !$fecha || !$hora || !$metodo || !$servicio || !$barbero) {
            $error_val = "Por favor, completa todos los campos requeridos.";
        } elseif (strlen($tel_digitos) < 7 || strlen($tel_digitos) > 15) {
            $error_val = "El número de teléfono no parece válido.";
        } elseif (!array_key_exists($servicio, $servicios)) {
            $error_val = "El servicio seleccionado no es válido.";
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha < date('Y-m-d')) {
            $error_val = "La fecha no puede ser anterior a hoy.";
        } else {
            // El barbero debe existir y estar activo
            $stmt_b = $conn->prepare("SELECT nombre, hora_inicio, hora_fin, almuerzo_inicio, almuerzo_fin, sucursal_id FROM barberos WHERE id = ? AND activo = 1");
            $stmt_b->bind_param("i", $barbero);
            $stmt_b->execute();
            $bdata = $stmt_b->get_result()->fetch_assoc();
            $stmt_b->close();

            if (!$bdata) {
                $error_val = "El especialista seleccionado no está disponible.";
            } else {
                $t    = strtotime($hora);
                $ini  = strtotime($bdata['hora_inicio']);
                $fin  = strtotime($bdata['hora_fin']);
                $almi = strtotime($bdata['almuerzo_inicio']);
                $almf = strtotime($bdata['almuerzo_fin']);
                $hoy  = date('Y-m-d');

                if ($t === false || $t < $ini || $t >= $fin) {
                    $error_val = "Ese horario está fuera del turno de " . $bdata['nombre']
                        . " (" . date('h:i A', $ini) . " a " . date('h:i A', $fin) . ").";
                } elseif ($t >= $almi && $t < $almf) {
                    $error_val = $bdata['nombre'] . " está en su hora de almuerzo a esa hora. Elige otro horario.";
                } elseif ($fecha === $hoy && $t < strtotime(date('H:i'))) {
                    $error_val = "Esa hora ya pasó. Elige un horario futuro.";
                } else {
                    // El cupo no debe estar ya tomado (excluye citas canceladas)
                    $stmt_chk = $conn->prepare("SELECT id FROM citas WHERE barbero_id = ? AND fecha = ? AND hora = ? AND estado != 'cancelada'");
                    $stmt_chk->bind_param("iss", $barbero, $fecha, $hora);
                    $stmt_chk->execute();
                    if ($stmt_chk->get_result()->num_rows > 0) {
                        $error_val = "Ese cupo ya fue reservado. Por favor elige otra hora.";
                    }
                    $stmt_chk->close();
                }
            }
        }

        if ($error_val !== null) {
            $msg = $error_val;
            $msg_type = "error";
        } else {
            $estado_pago = ($metodo === 'Efectivo') ? 'verificado' : 'pendiente';
            $suc_cita    = intval($bdata['sucursal_id'] ?? 1) ?: 1;

            $stmt = $conn->prepare(
                "INSERT INTO citas (barbero_id, cliente_nombre, cliente_telefono, servicio, fecha, hora, metodo_pago, referencia_pago, estado_pago, sucursal_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("issssssssi", $barbero, $nombre, $tel_digitos, $servicio, $fecha, $hora, $metodo, $referencia, $estado_pago, $suc_cita);

            if ($stmt->execute()) {
                $msg = "¡Turno solicitado con éxito! Te esperamos el " . date('d/m/Y', strtotime($fecha)) . " a las " . date('h:i A', strtotime($hora)) . ".";
                $msg_type = "success";
                $_POST = []; // limpiar el formulario tras éxito
            } else {
                $msg = "Ese cupo ya fue reservado. Por favor elige otra hora.";
                $msg_type = "error";
            }
            $stmt->close();
        }
    }
}

// CARGAR CONFIGURACIÓN DE PAGOS
$config = [];
$res_conf = $conn->query("SELECT clave, valor FROM configuracion");
if ($res_conf) {
    while ($row = $res_conf->fetch_assoc()) {
        $config[$row['clave']] = $row['valor'];
    }
}

// CARGAR BARBEROS ACTIVOS
$barberos = [];
$res_b = $conn->query("SELECT id, nombre, foto_url FROM barberos WHERE activo = 1 ORDER BY nombre");
if ($res_b) {
    while ($b = $res_b->fetch_assoc()) {
        $barberos[] = $b;
    }
}

$nombre_negocio = $config['nombre_negocio'] ?? 'AlCorte Pro';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title><?php echo htmlspecialchars($nombre_negocio); ?> — Reservas</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --gold:    #b49363;
            --gold-dk: #9d7e54;
            --gold-lt: #fef3c7;
            --bg:      #f9fafb;
            --white:   #ffffff;
            --border:  #e5e7eb;
            --txt:     #111827;
            --muted:   #6b7280;
            --faint:   #9ca3af;
            --success-bg: #ecfdf5;
            --success-tx: #047857;
            --success-bd: #d1fae5;
            --error-bg:   #fef2f2;
            --error-tx:   #991b1b;
            --error-bd:   #fecaca;
        }

        html, body {
            height: 100%;
            font-family: 'Inter', system-ui, sans-serif;
            background: var(--bg);
            color: var(--txt);
            font-size: 14px;
        }

        body { display: flex; flex-direction: column; }

        /* ──────────── HEADER ──────────── */
        .site-header {
            background: var(--white);
            border-bottom: 1px solid var(--border);
            padding: 20px 20px 16px;
            text-align: center;
            flex-shrink: 0;
        }
        .site-header .logo-icon { font-size: 28px; color: var(--gold); }
        .site-header h1 { font-size: 20px; font-weight: 800; letter-spacing: -0.5px; margin-top: 6px; }
        .site-header p  { font-size: 11px; color: var(--faint); text-transform: uppercase; letter-spacing: 1px; margin-top: 3px; }

        /* ──────────── TAB NAV (top) ──────────── */
        .tab-nav {
            background: var(--white);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: center;
            gap: 4px;
            padding: 10px 16px;
            flex-shrink: 0;
        }
        .tab-nav a {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            border-radius: 9999px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            color: var(--muted);
            transition: all .18s;
            white-space: nowrap;
        }
        .tab-nav a:hover { background: var(--bg); color: var(--txt); }
        .tab-nav a.active {
            background: var(--gold-lt);
            color: var(--gold-dk);
        }
        .tab-nav a.active i { color: var(--gold); }

        /* ──────────── MAIN ──────────── */
        .main {
            flex: 1;
            overflow-y: auto;
            padding: 24px 16px 32px;
        }
        .container {
            max-width: 520px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* ──────────── MESSAGES ──────────── */
        .alert {
            padding: 14px 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.4;
        }
        .alert i { margin-top: 1px; flex-shrink: 0; }
        .alert.success { background: var(--success-bg); color: var(--success-tx); border-color: var(--success-bd); }
        .alert.error   { background: var(--error-bg);   color: var(--error-tx);   border-color: var(--error-bd); }

        /* ──────────── CARD ──────────── */
        .card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
            overflow: hidden;
        }
        .card-head {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--muted);
            background: var(--bg);
        }
        .card-head i { color: var(--gold); font-size: 14px; }
        .card-body { padding: 20px; }

        /* ──────────── FORM ──────────── */
        .field { margin-bottom: 16px; }
        .field:last-child { margin-bottom: 0; }
        label.lbl {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--muted);
            margin-bottom: 6px;
        }
        input[type=text],
        input[type=tel],
        input[type=date],
        input[type=time],
        select {
            width: 100%;
            padding: 11px 12px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            color: var(--txt);
            background: var(--white);
            transition: border-color .18s, box-shadow .18s;
            appearance: none;
            -webkit-appearance: none;
        }
        input:focus, select:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 0 3px rgba(180,147,99,.12);
        }
        input::placeholder { color: var(--faint); }

        .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

        /* Selector de servicio visual */
        .servicios-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .servicio-card {
            border: 1.5px solid var(--border);
            border-radius: 10px;
            padding: 12px;
            cursor: pointer;
            transition: all .18s;
            position: relative;
            background: var(--white);
        }
        .servicio-card:hover { border-color: var(--gold); background: #fffdf9; }
        .servicio-card.selected {
            border-color: var(--gold);
            background: var(--gold-lt);
        }
        .servicio-card .s-name { font-weight: 700; font-size: 13px; color: var(--txt); }
        .servicio-card .s-meta { font-size: 11px; color: var(--faint); margin-top: 3px; }
        .servicio-card .s-check {
            position: absolute;
            top: 8px; right: 8px;
            width: 18px; height: 18px;
            border-radius: 50%;
            background: var(--gold);
            color: white;
            font-size: 10px;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .servicio-card.selected .s-check { display: flex; }
        input[name=servicio] { display: none; }

        /* Selector de barbero */
        .barberos-grid {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .barbero-btn {
            flex: 1;
            min-width: 110px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding: 14px 8px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            cursor: pointer;
            background: var(--white);
            transition: all .18s;
        }
        .barbero-btn:hover { border-color: var(--gold); }
        .barbero-btn.selected {
            border-color: var(--gold);
            background: var(--gold-lt);
        }
        .barbero-btn img {
            width: 48px; height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--border);
        }
        .barbero-btn .b-name {
            font-size: 12px;
            font-weight: 700;
            color: var(--txt);
            text-align: center;
        }
        .barbero-btn.selected img { border-color: var(--gold); }
        input[name=barbero_id] { display: none; }

        /* Divisor */
        .divider {
            border: none;
            border-top: 1px dashed var(--border);
            margin: 18px 0;
        }

        /* Info boxes pago */
        .pay-info {
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 12px;
            line-height: 1.6;
            margin-top: 12px;
            display: none;
        }
        .pay-info.blue   { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a; }
        .pay-info.purple { background: #f5f3ff; border: 1px solid #ddd6fe; color: #4c1d95; }
        .pay-info .pay-title {
            font-weight: 700;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .pay-info .pay-row { display: flex; justify-content: space-between; }
        .pay-info .pay-row strong { font-weight: 700; }
        .pay-info .pay-mono {
            font-family: monospace;
            background: rgba(255,255,255,.7);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 13px;
            letter-spacing: .5px;
        }

        /* ──────────── BUTTON ──────────── */
        .btn {
            width: 100%;
            padding: 13px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: .3px;
            cursor: pointer;
            transition: all .18s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-gold { background: var(--gold); color: white; }
        .btn-gold:hover { background: var(--gold-dk); }
        .btn-gold:active { transform: scale(.98); }
        .btn-outline { background: var(--bg); color: var(--muted); border: 1.5px solid var(--border); }
        .btn-outline:hover { border-color: var(--gold); color: var(--gold-dk); background: var(--gold-lt); }

        /* ──────────── VIP ──────────── */
        .vip-hero {
            text-align: center;
            padding: 8px 0 20px;
        }
        .vip-crown {
            width: 72px; height: 72px;
            border-radius: 50%;
            background: var(--gold-lt);
            border: 2px solid #fcd34d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: var(--gold);
            margin: 0 auto 14px;
        }
        .vip-hero h3 { font-size: 18px; font-weight: 800; }
        .vip-hero p  { color: var(--muted); font-size: 13px; margin-top: 6px; line-height: 1.5; max-width: 300px; margin-inline: auto; }

        .stars-row {
            display: flex;
            justify-content: center;
            gap: 6px;
            margin: 14px 0;
            flex-wrap: wrap;
        }
        .star {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: var(--bg);
            border: 1.5px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: var(--faint);
        }
        .star.filled { background: var(--gold-lt); border-color: #fcd34d; color: var(--gold); }

        .vip-result-box {
            margin-top: 14px;
            padding: 14px;
            border-radius: 10px;
            background: var(--bg);
            border: 1.5px solid var(--border);
            font-size: 13px;
            display: none;
        }
        .vip-result-box.show { display: block; }
        .vip-result-box.found { border-color: #fcd34d; background: var(--gold-lt); }
        .vip-result-name { font-size: 16px; font-weight: 800; margin-bottom: 4px; }
        .vip-result-pts  { font-size: 13px; color: var(--muted); }
        .vip-result-pts span { font-weight: 700; color: var(--gold-dk); font-size: 20px; }

        /* Progress bar de estrellas */
        .progress-wrap { margin: 10px 0 4px; }
        .progress-bar {
            height: 8px;
            background: #e5e7eb;
            border-radius: 9999px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--gold), #e9c46a);
            border-radius: 9999px;
            transition: width .4s ease;
        }
        .progress-label { font-size: 11px; color: var(--faint); margin-top: 4px; text-align: right; }

        /* ──────────── INFO ──────────── */
        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 14px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            margin-bottom: 12px;
        }
        .info-item:last-child { margin-bottom: 0; }
        .info-icon {
            width: 40px; height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .info-icon.gold   { background: var(--gold-lt); color: var(--gold); }
        .info-icon.green  { background: #dcfce7; color: #16a34a; }
        .info-icon.blue   { background: #dbeafe; color: #2563eb; }
        .info-icon.red    { background: #fee2e2; color: #dc2626; }
        .info-txt h4 { font-size: 13px; font-weight: 700; margin-bottom: 3px; }
        .info-txt p  { font-size: 12px; color: var(--muted); line-height: 1.4; }

        /* ──────────── SPINNER ──────────── */
        .spinner {
            display: inline-block;
            width: 16px; height: 16px;
            border: 2px solid rgba(0,0,0,.15);
            border-top-color: var(--gold);
            border-radius: 50%;
            animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Mobile */
        @media (max-width: 480px) {
            .row2 { grid-template-columns: 1fr; }
            .servicios-grid { grid-template-columns: 1fr; }
            .tab-nav a span { display: none; }
            .tab-nav a { padding: 8px 14px; }
        }
    </style>
</head>
<body>

<!-- HEADER -->
<header class="site-header">
    <div class="logo-icon"><i class="fas fa-scissors"></i></div>
    <h1><?php echo htmlspecialchars($nombre_negocio); ?></h1>
    <p>Reserva tu turno en línea</p>
</header>

<!-- TABS -->
<nav class="tab-nav">
    <a href="?tab=reservar" class="<?php echo $tab === 'reservar' ? 'active' : ''; ?>">
        <i class="fas fa-calendar-check"></i> <span>Reservar</span>
    </a>
    <a href="?tab=club" class="<?php echo $tab === 'club' ? 'active' : ''; ?>">
        <i class="fas fa-crown"></i> <span>Club VIP</span>
    </a>
    <a href="?tab=info" class="<?php echo $tab === 'info' ? 'active' : ''; ?>">
        <i class="fas fa-circle-info"></i> <span>Info</span>
    </a>
</nav>

<!-- MAIN -->
<main class="main">
<div class="container">

    <!-- MENSAJE -->
    <?php if (!empty($msg)): ?>
        <div class="alert <?php echo $msg_type; ?>">
            <i class="fas fa-<?php echo $msg_type === 'success' ? 'circle-check' : 'circle-exclamation'; ?>"></i>
            <span><?php echo htmlspecialchars($msg); ?></span>
        </div>
    <?php endif; ?>

    <!-- ══════════ TAB: RESERVAR ══════════ -->
    <?php if ($tab === 'reservar'): ?>

    <div class="card">
        <div class="card-head"><i class="fas fa-calendar-plus"></i> Nueva Cita</div>
        <div class="card-body">
            <form method="POST" action="?tab=reservar" id="formReserva">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                <input type="hidden" name="action" value="agendar">
                <input type="hidden" name="servicio" id="servicio_val">
                <input type="hidden" name="barbero_id" id="barbero_val">

                <!-- NOMBRE -->
                <div class="field">
                    <label class="lbl">Nombre completo</label>
                    <input type="text" name="cliente_nombre" placeholder="Ej. Juan Pérez" required
                           value="<?php echo htmlspecialchars($_POST['cliente_nombre'] ?? ''); ?>">
                </div>

                <!-- TELÉFONO -->
                <div class="field">
                    <label class="lbl">Teléfono</label>
                    <input type="tel" name="cliente_telefono" placeholder="Ej. 04121234567" required
                           value="<?php echo htmlspecialchars($_POST['cliente_telefono'] ?? ''); ?>">
                </div>

                <hr class="divider">

                <!-- SERVICIO -->
                <div class="field">
                    <label class="lbl">Elige tu servicio</label>
                    <div class="servicios-grid">
                        <?php foreach ($servicios as $nombre_svc => $meta_svc): ?>
                        <div class="servicio-card" onclick="selectServicio(this, '<?php echo htmlspecialchars($nombre_svc, ENT_QUOTES); ?>')">
                            <div class="s-check"><i class="fas fa-check"></i></div>
                            <div class="s-name"><?php echo htmlspecialchars($nombre_svc); ?></div>
                            <div class="s-meta"><?php echo $meta_svc; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- BARBERO -->
                <?php if (!empty($barberos)): ?>
                <div class="field">
                    <label class="lbl">Elige tu especialista</label>
                    <div class="barberos-grid">
                        <?php foreach ($barberos as $b): ?>
                        <button type="button" class="barbero-btn"
                                onclick="selectBarbero(this, <?php echo $b['id']; ?>)">
                            <img src="<?php echo htmlspecialchars($b['foto_url'] ?? 'https://ui-avatars.com/api/?name=' . urlencode($b['nombre']) . '&background=b49363&color=fff'); ?>"
                                 alt="<?php echo htmlspecialchars($b['nombre']); ?>"
                                 onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($b['nombre']); ?>&background=b49363&color=fff'">
                            <span class="b-name"><?php echo htmlspecialchars($b['nombre']); ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <hr class="divider">

                <!-- FECHA Y HORA -->
                <div class="field">
                    <div class="row2">
                        <div>
                            <label class="lbl">Fecha</label>
                            <input type="date" name="fecha" required
                                   min="<?php echo date('Y-m-d'); ?>"
                                   value="<?php echo htmlspecialchars($_POST['fecha'] ?? ''); ?>">
                        </div>
                        <div>
                            <label class="lbl">Hora</label>
                            <input type="time" name="hora" required min="09:00" max="17:00"
                                   value="<?php echo htmlspecialchars($_POST['hora'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <hr class="divider">

                <!-- MÉTODO DE PAGO -->
                <div class="field">
                    <label class="lbl">Método de pago</label>
                    <select name="metodo_pago" id="metodo_pago" onchange="onMetodoPago()" required>
                        <option value="">— Selecciona —</option>
                        <?php if (($config['estado_pago_movil'] ?? '0') === '1'): ?>
                            <option value="Pago Móvil">Pago Móvil (Bs)</option>
                        <?php endif; ?>
                        <?php if (($config['estado_zelle'] ?? '0') === '1'): ?>
                            <option value="Zelle">Zelle ($)</option>
                        <?php endif; ?>
                        <?php if (($config['estado_efectivo'] ?? '0') === '1'): ?>
                            <option value="Efectivo">Efectivo en local</option>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- INFO PAGO MÓVIL -->
                <div class="pay-info blue" id="info_pm">
                    <div class="pay-title"><i class="fas fa-university"></i> Datos de Pago Móvil</div>
                    <div class="pay-row"><span>Banco</span><strong><?php echo htmlspecialchars($config['banco_nombre'] ?? '—'); ?></strong></div>
                    <div class="pay-row"><span>Teléfono</span><strong><?php echo htmlspecialchars($config['banco_telefono'] ?? '—'); ?></strong></div>
                    <div class="pay-row"><span>Cédula/RIF</span><strong><?php echo htmlspecialchars($config['banco_ci'] ?? '—'); ?></strong></div>
                </div>

                <!-- INFO ZELLE -->
                <div class="pay-info purple" id="info_zelle">
                    <div class="pay-title"><i class="fas fa-dollar-sign"></i> Cuenta Zelle</div>
                    <div class="pay-mono"><?php echo htmlspecialchars($config['zelle_email'] ?? '—'); ?></div>
                </div>

                <!-- REFERENCIA -->
                <div class="field" id="campo_ref" style="display:none; margin-top:14px;">
                    <label class="lbl">Número de referencia</label>
                    <input type="text" id="referencia_pago" name="referencia_pago" placeholder="Ej. 45892314">
                </div>

                <button type="submit" class="btn btn-gold" style="margin-top:20px;" id="btnEnviar">
                    <i class="fas fa-paper-plane"></i> Confirmar Reserva
                </button>
            </form>
        </div>
    </div>

    <!-- ══════════ TAB: CLUB VIP ══════════ -->
    <?php elseif ($tab === 'club'): ?>

    <div class="card">
        <div class="card-head"><i class="fas fa-crown"></i> Club VIP AlCorte</div>
        <div class="card-body">
            <div class="vip-hero">
                <div class="vip-crown"><i class="fas fa-crown"></i></div>
                <h3>Acumula Estrellas</h3>
                <p>Por cada cita confirmada ganas 1 estrella. Al llegar a 10, ¡tu próximo corte es gratis!</p>
            </div>

            <div class="stars-row" id="starsRow">
                <?php for ($i = 0; $i < 10; $i++): ?>
                    <div class="star" id="star<?php echo $i; ?>"><i class="fas fa-star"></i></div>
                <?php endfor; ?>
            </div>

            <div class="field" style="margin-top: 16px;">
                <label class="lbl">Tu número de teléfono</label>
                <input type="tel" id="vip_phone" placeholder="Ej. 04121234567"
                       style="text-align: center; letter-spacing: 1px;">
            </div>

            <button class="btn btn-outline" onclick="consultarVIP()">
                <i class="fas fa-search"></i> Consultar mis puntos
            </button>

            <div class="vip-result-box" id="vipResult"></div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><i class="fas fa-gift"></i> Beneficios del Club</div>
        <div class="card-body">
            <?php
            $beneficios = [
                ['5 estrellas',  'Descuento del 10% en tu próximo servicio.',  'fas fa-tag',    'gold'],
                ['10 estrellas', 'Corte clásico completamente gratis.',          'fas fa-scissors','green'],
                ['15 estrellas', 'Acceso VIP: turno preferencial sin espera.',   'fas fa-bolt',   'blue'],
            ];
            foreach ($beneficios as [$nivel, $desc, $icon, $color]):
            ?>
            <div class="info-item">
                <div class="info-icon <?php echo $color; ?>"><i class="<?php echo $icon; ?>"></i></div>
                <div class="info-txt">
                    <h4><?php echo $nivel; ?></h4>
                    <p><?php echo $desc; ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══════════ TAB: INFO ══════════ -->
    <?php elseif ($tab === 'info'): ?>

    <div class="card">
        <div class="card-head"><i class="fas fa-circle-info"></i> Información</div>
        <div class="card-body">
            <div class="info-item">
                <div class="info-icon gold"><i class="fas fa-location-dot"></i></div>
                <div class="info-txt">
                    <h4>Ubicación</h4>
                    <p>Av. Las Delicias, Centro Profesional, Maracay</p>
                </div>
            </div>
            <div class="info-item">
                <div class="info-icon green"><i class="fas fa-clock"></i></div>
                <div class="info-txt">
                    <h4>Horario de Atención</h4>
                    <p>Lunes a Sábado: 9:00 AM – 5:00 PM</p>
                </div>
            </div>
            <div class="info-item">
                <div class="info-icon blue"><i class="fas fa-phone"></i></div>
                <div class="info-txt">
                    <h4>Contacto</h4>
                    <p><?php echo htmlspecialchars($config['banco_telefono'] ?? 'No disponible'); ?></p>
                </div>
            </div>
            <div class="info-item">
                <div class="info-icon red"><i class="fas fa-triangle-exclamation"></i></div>
                <div class="info-txt">
                    <h4>Política de Reservas</h4>
                    <p>Tienes 15 minutos para confirmar tu pago digital. Pasado ese tiempo, el turno puede liberarse.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><i class="fas fa-credit-card"></i> Métodos de Pago</div>
        <div class="card-body">
            <?php if (($config['estado_pago_movil'] ?? '0') === '1'): ?>
            <div class="info-item">
                <div class="info-icon blue"><i class="fas fa-mobile-alt"></i></div>
                <div class="info-txt">
                    <h4>Pago Móvil</h4>
                    <p><?php echo htmlspecialchars($config['banco_nombre'] ?? ''); ?> · <?php echo htmlspecialchars($config['banco_telefono'] ?? ''); ?> · <?php echo htmlspecialchars($config['banco_ci'] ?? ''); ?></p>
                </div>
            </div>
            <?php endif; ?>
            <?php if (($config['estado_zelle'] ?? '0') === '1'): ?>
            <div class="info-item">
                <div class="info-icon" style="background:#f3e8ff; color:#7c3aed;"><i class="fas fa-dollar-sign"></i></div>
                <div class="info-txt">
                    <h4>Zelle</h4>
                    <p><?php echo htmlspecialchars($config['zelle_email'] ?? ''); ?></p>
                </div>
            </div>
            <?php endif; ?>
            <?php if (($config['estado_efectivo'] ?? '0') === '1'): ?>
            <div class="info-item">
                <div class="info-icon green"><i class="fas fa-money-bill-wave"></i></div>
                <div class="info-txt">
                    <h4>Efectivo</h4>
                    <p>Pago directo en local al momento de la visita.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

</div>
</main>

<script>
    // ── Selector de servicios ──
    function selectServicio(card, nombre) {
        document.querySelectorAll('.servicio-card').forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
        document.getElementById('servicio_val').value = nombre;
    }

    // ── Selector de barbero ──
    function selectBarbero(btn, id) {
        document.querySelectorAll('.barbero-btn').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        document.getElementById('barbero_val').value = id;
    }

    // ── Método de pago ──
    function onMetodoPago() {
        const v = document.getElementById('metodo_pago').value;
        document.getElementById('info_pm').style.display    = v === 'Pago Móvil' ? 'block' : 'none';
        document.getElementById('info_zelle').style.display = v === 'Zelle'      ? 'block' : 'none';
        const ref = document.getElementById('campo_ref');
        const inp = document.getElementById('referencia_pago');
        if (v === 'Pago Móvil' || v === 'Zelle') {
            ref.style.display = 'block';
            inp.required = true;
        } else {
            ref.style.display = 'none';
            inp.required = false;
            inp.value = '';
        }
    }

    // ── Validación antes de enviar ──
    document.getElementById('formReserva')?.addEventListener('submit', function(e) {
        if (!document.getElementById('servicio_val').value) {
            e.preventDefault();
            alert('Por favor selecciona un servicio.');
            return;
        }
        if (!document.getElementById('barbero_val').value) {
            e.preventDefault();
            alert('Por favor selecciona un especialista.');
            return;
        }
    });

    // ── Consulta VIP ──
    function consultarVIP() {
        const tel = document.getElementById('vip_phone').value.trim();
        const box = document.getElementById('vipResult');
        if (!tel) { alert('Ingresa tu número de teléfono.'); return; }

        box.className = 'vip-result-box show';
        box.innerHTML = '<span class="spinner"></span> Buscando...';

        fetch(`../backend/api/club.php?telefono=${encodeURIComponent(tel)}`)
            .then(r => r.json())
            .then(data => {
                if (data.encontrado) {
                    const pts  = data.puntos;
                    const pct  = Math.min(pts / 10 * 100, 100);
                    const next = pts >= 10 ? '¡Tienes un corte gratis!' : `Faltan ${10 - pts} para corte gratis`;

                    // Activar estrellas
                    for (let i = 0; i < 10; i++) {
                        const s = document.getElementById('star' + i);
                        if (s) s.className = i < pts ? 'star filled' : 'star';
                    }

                    box.className = 'vip-result-box show found';
                    box.innerHTML = `
                        <div class="vip-result-name">⭐ ${data.nombre}</div>
                        <div class="vip-result-pts"><span>${pts}</span> / 10 estrellas</div>
                        <div class="progress-wrap">
                            <div class="progress-bar"><div class="progress-fill" style="width:${pct}%"></div></div>
                            <div class="progress-label">${next}</div>
                        </div>
                        <div style="font-size:11px; color:#9ca3af; margin-top:8px;">Última visita: ${data.ultima_visita}</div>
                    `;
                } else {
                    for (let i = 0; i < 10; i++) {
                        const s = document.getElementById('star' + i);
                        if (s) s.className = 'star';
                    }
                    box.className = 'vip-result-box show';
                    box.innerHTML = '<i class="fas fa-circle-xmark" style="color:#ef4444;margin-right:6px;"></i> Número no registrado en el Club VIP.';
                }
            })
            .catch(() => {
                box.innerHTML = 'Error de conexión. Intenta de nuevo.';
            });
    }

    // Permitir consultar con Enter
    document.getElementById('vip_phone')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); consultarVIP(); }
    });
</script>
</body>
</html>


