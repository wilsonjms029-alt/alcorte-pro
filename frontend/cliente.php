<?php
require_once '../backend/config/config.php';

$msg      = "";
$msg_type = "success";
$tab      = $_GET['tab'] ?? 'reservar';

// ── LOAD SERVICES (DB first, static fallback) ──
$servicios_list = [];
try {
    $res_svc = $conn->query("SELECT * FROM servicios WHERE activo = 1 ORDER BY orden ASC, id ASC");
    if ($res_svc) while ($s = $res_svc->fetch_assoc()) $servicios_list[] = $s;
} catch (\Throwable $e) {
    // tabla aún no creada — se usa la lista estática de abajo
}
if (empty($servicios_list)) {
    $servicios_list = [
        ['nombre' => 'Corte Clásico',     'duracion' => '30 min', 'precio' => 'Bs 8',  'icono' => 'fas fa-cut'],
        ['nombre' => 'Barba Premium',     'duracion' => '20 min', 'precio' => 'Bs 6',  'icono' => 'fas fa-user-tie'],
        ['nombre' => 'Combo AlCorte Pro', 'duracion' => '50 min', 'precio' => 'Bs 12', 'icono' => 'fas fa-star'],
        ['nombre' => 'Corte + Cejas',     'duracion' => '40 min', 'precio' => 'Bs 10', 'icono' => 'fas fa-cut'],
    ];
}
$servicios_validos = array_column($servicios_list, 'nombre');

// ── PROCESS BOOKING ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'agendar') {
    if (!csrf_validate()) {
        $msg = "Error de seguridad. Recarga la página.";
        $msg_type = "error";
    } else {
        csrf_regenerate();
        $nombre     = trim($_POST['cliente_nombre']   ?? '');
        $telefono   = trim($_POST['cliente_telefono'] ?? '');
        $barbero    = intval($_POST['barbero_id']     ?? 0);
        $servicio   = trim($_POST['servicio']         ?? '');
        $fecha      = trim($_POST['fecha']            ?? '');
        $hora       = trim($_POST['hora']             ?? '');
        $metodo     = trim($_POST['metodo_pago']      ?? '');
        $referencia = trim($_POST['referencia_pago']  ?? '');
        $tel_digitos = preg_replace('/\D/', '', $telefono);

        $error_val = null;
        if (!$nombre || !$telefono || !$fecha || !$hora || !$metodo || !$servicio || !$barbero) {
            $error_val = "Por favor, completa todos los campos requeridos.";
        } elseif (strlen($tel_digitos) < 7 || strlen($tel_digitos) > 15) {
            $error_val = "El número de teléfono no parece válido.";
        } elseif (!in_array($servicio, $servicios_validos)) {
            $error_val = "El servicio seleccionado no es válido.";
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha < date('Y-m-d')) {
            $error_val = "La fecha no puede ser anterior a hoy.";
        } else {
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
                    $error_val = "Ese horario está fuera del turno de " . htmlspecialchars($bdata['nombre']) . " (" . date('h:i A', $ini) . " – " . date('h:i A', $fin) . ").";
                } elseif ($t >= $almi && $t < $almf) {
                    $error_val = htmlspecialchars($bdata['nombre']) . " está en almuerzo a esa hora. Elige otro horario.";
                } elseif ($fecha === $hoy && $t < strtotime(date('H:i'))) {
                    $error_val = "Esa hora ya pasó. Elige un horario futuro.";
                } else {
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
                $msg = "¡Turno solicitado! Te esperamos el " . date('d/m/Y', strtotime($fecha)) . " a las " . date('h:i A', strtotime($hora)) . ".";
                $msg_type = "success";
                $_POST = [];
            } else {
                $msg = "Ese cupo ya fue reservado. Por favor elige otra hora.";
                $msg_type = "error";
            }
            $stmt->close();
        }
    }
}

// ── LOAD CONFIG ──
$config = [];
$res_conf = $conn->query("SELECT clave, valor FROM configuracion");
if ($res_conf) while ($row = $res_conf->fetch_assoc()) $config[$row['clave']] = $row['valor'];

// ── LOAD BARBERS ──
$barberos = [];
$res_b = $conn->query("SELECT id, nombre, foto_url FROM barberos WHERE activo = 1 ORDER BY nombre");
if ($res_b) while ($b = $res_b->fetch_assoc()) $barberos[] = $b;

$nombre_negocio = $config['nombre_negocio'] ?? 'AlCorte Pro';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title><?= htmlspecialchars($nombre_negocio) ?> — Reservas</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --navy:       #1a3461;
            --navy-dk:    #122550;
            --navy-lt:    #eef2ff;
            --blue:       #2563eb;
            --gold:       #c49a4a;
            --gold-lt:    #fef3c7;
            --bg:         #f4f6fa;
            --white:      #ffffff;
            --border:     #e2e8f0;
            --txt:        #1a202c;
            --muted:      #64748b;
            --faint:      #94a3b8;
            --succ-bg:    #ecfdf5; --succ-tx: #047857; --succ-bd: #d1fae5;
            --err-bg:     #fef2f2; --err-tx:  #991b1b; --err-bd:  #fecaca;
        }

        html, body { height: 100%; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: var(--bg);
            color: var(--txt);
            font-size: 14px;
            min-height: 100%;
            padding-bottom: 136px;
        }

        /* ── APP HEADER ── */
        .app-header {
            background: linear-gradient(135deg, #152d58 0%, #1e3f70 100%);
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 200;
        }
        .hdr-btn {
            width: 36px; height: 36px;
            border-radius: 50%;
            border: none;
            background: rgba(255,255,255,.15);
            color: white;
            font-size: 15px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            text-decoration: none;
            transition: background .18s;
        }
        .hdr-btn:hover { background: rgba(255,255,255,.25); }
        .app-header h1 {
            color: white;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 1.8px;
            text-transform: uppercase;
        }

        /* ── STEP PROGRESS BAR ── */
        .step-bar {
            background: var(--white);
            padding: 14px 20px 10px;
            display: flex;
            align-items: flex-start;
            border-bottom: 1px solid var(--border);
        }
        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
            flex: 1;
        }
        .step-dot {
            width: 22px; height: 22px;
            border-radius: 50%;
            border: 2px solid var(--faint);
            color: var(--faint);
            font-size: 10px;
            font-weight: 700;
            display: flex; align-items: center; justify-content: center;
        }
        .step-dot.active  { background: var(--gold); border-color: var(--gold); color: white; }
        .step-dot.done    { background: var(--navy); border-color: var(--navy); color: white; }
        .step-label {
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: var(--faint);
            text-align: center;
            white-space: nowrap;
        }
        .step-label.active { color: var(--gold); }
        .step-label.done   { color: var(--navy); }
        .step-connector {
            flex: 1;
            height: 2px;
            background: var(--border);
            margin-top: 10px;
        }
        .step-connector.done { background: var(--navy); }

        /* ── MAIN ── */
        .main { max-width: 500px; margin: 0 auto; }

        /* ── SECTION TITLES ── */
        .sec-title {
            padding: 20px 16px 10px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--txt);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .sec-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        /* ── SEARCH ── */
        .search-wrap { padding: 0 16px 10px; }
        .search-inp {
            width: 100%;
            padding: 10px 14px 10px 36px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 13px;
            font-family: inherit;
            background: var(--white) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E") no-repeat 12px center;
            color: var(--txt);
            transition: border-color .18s;
        }
        .search-inp:focus { outline: none; border-color: var(--navy); }
        .search-inp::placeholder { color: var(--faint); }

        /* ── SERVICE CARDS ── */
        .svc-list { padding: 0 16px; display: flex; flex-direction: column; gap: 10px; }
        .svc-card {
            display: flex;
            align-items: center;
            gap: 14px;
            background: var(--white);
            border: 2px solid var(--border);
            border-radius: 14px;
            padding: 14px;
            cursor: pointer;
            transition: border-color .18s, background .18s;
        }
        .svc-card:hover { border-color: var(--navy); }
        .svc-card.sel   { border-color: var(--navy); background: var(--navy-lt); }
        .svc-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            background: #dbeafe;
            color: var(--blue);
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            transition: background .18s, color .18s;
        }
        .svc-card.sel .svc-icon { background: var(--navy); color: white; }
        .svc-info { flex: 1; min-width: 0; }
        .svc-name { font-weight: 700; font-size: 14px; }
        .svc-meta { font-size: 11px; color: var(--muted); margin-top: 2px; }
        .svc-card.sel .svc-meta { color: var(--navy); }
        .svc-btn {
            width: 30px; height: 30px;
            border-radius: 50%;
            border: none;
            background: var(--gold);
            color: white;
            font-size: 15px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            transition: background .18s;
        }
        .svc-card.sel .svc-btn { background: var(--navy); }
        .svc-btn .fa-check { display: none; }
        .svc-card.sel .svc-btn .fa-plus  { display: none; }
        .svc-card.sel .svc-btn .fa-check { display: inline; }

        /* ── CALENDAR ── */
        .cal-wrap { padding: 0 16px; }
        .cal-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 0 10px;
        }
        .cal-head strong { font-size: 14px; font-weight: 700; }
        .cal-nav {
            width: 30px; height: 30px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            background: var(--white);
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            color: var(--muted);
            font-size: 11px;
        }
        .cal-nav:hover { border-color: var(--navy); color: var(--navy); }
        .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }
        .cal-dow {
            text-align: center;
            font-size: 9px;
            font-weight: 700;
            color: var(--faint);
            text-transform: uppercase;
            padding: 4px 0 6px;
        }
        .cal-day {
            aspect-ratio: 1;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px;
            font-weight: 500;
            border-radius: 8px;
            cursor: pointer;
            transition: background .15s, color .15s;
        }
        .cal-day:not(.empty):not(.dis):hover { background: var(--navy-lt); color: var(--navy); }
        .cal-day.today  { font-weight: 800; color: var(--navy); }
        .cal-day.sel    { background: var(--navy); color: white; font-weight: 700; }
        .cal-day.dis    { color: var(--faint); pointer-events: none; }
        .cal-day.empty  { pointer-events: none; }

        /* ── TIME SLOTS ── */
        .time-grid {
            padding: 10px 16px 0;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        .t-slot {
            padding: 9px 4px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            background: var(--white);
            text-align: center;
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            transition: all .15s;
        }
        .t-slot:not(.t-dis):hover { border-color: var(--navy); color: var(--navy); }
        .t-slot.sel { background: var(--navy); border-color: var(--navy); color: white; }
        .t-slot.t-dis { opacity: .35; pointer-events: none; }

        /* ── SPECIALIST CARDS ── */
        .spec-track {
            padding: 0 16px;
            display: flex;
            gap: 12px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            padding-bottom: 4px;
        }
        .spec-track::-webkit-scrollbar { display: none; }
        .spec-card {
            flex: 0 0 96px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 7px;
            background: var(--white);
            border: 2px solid var(--border);
            border-radius: 14px;
            padding: 14px 8px;
            cursor: pointer;
            transition: border-color .18s, background .18s;
        }
        .spec-card:hover { border-color: var(--navy); }
        .spec-card.sel   { border-color: var(--navy); background: var(--navy-lt); }
        .spec-photo {
            width: 52px; height: 52px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--border);
            transition: border-color .18s;
        }
        .spec-card.sel .spec-photo { border-color: var(--navy); }
        .spec-name { font-size: 11px; font-weight: 700; text-align: center; line-height: 1.2; }
        .spec-role { font-size: 9px; color: var(--faint); text-align: center; }
        .spec-star { font-size: 10px; color: var(--gold); font-weight: 600; }

        /* ── CLIENT FIELDS ── */
        .fields { padding: 0 16px; display: flex; flex-direction: column; gap: 12px; }
        .fld label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--muted);
            margin-bottom: 5px;
        }
        .fld input, .fld select {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            color: var(--txt);
            background: var(--white);
            appearance: none;
            -webkit-appearance: none;
            transition: border-color .18s, box-shadow .18s;
        }
        .fld input:focus, .fld select:focus {
            outline: none;
            border-color: var(--navy);
            box-shadow: 0 0 0 3px rgba(26,52,97,.08);
        }
        .fld input::placeholder { color: var(--faint); }

        /* ── PAYMENT INFO ── */
        .pay-box {
            margin: 10px 16px 0;
            padding: 14px 16px;
            border-radius: 12px;
            font-size: 12px;
            line-height: 1.6;
            display: none;
        }
        .pay-box.blue   { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e3a8a; }
        .pay-box.purple { background: #f5f3ff; border: 1px solid #ddd6fe; color: #4c1d95; }
        .pb-title {
            font-weight: 700;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 8px;
            display: flex; align-items: center; gap: 5px;
        }
        .pb-row { display: flex; justify-content: space-between; padding: 2px 0; }
        .pb-mono {
            font-family: monospace;
            background: rgba(255,255,255,.7);
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 13px;
            letter-spacing: .5px;
            margin-top: 4px;
            display: inline-block;
        }

        /* ── SUMMARY CARD ── */
        .sum-wrap { padding: 16px 16px 0; }
        .sum-card {
            background: var(--white);
            border: 1.5px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
        }
        .sum-head {
            padding: 11px 16px;
            background: var(--bg);
            border-bottom: 1px solid var(--border);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .7px;
            color: var(--muted);
        }
        .sum-body { padding: 14px 16px; display: flex; flex-direction: column; gap: 9px; }
        .sum-row { display: flex; align-items: flex-start; gap: 10px; font-size: 13px; }
        .sum-lbl { color: var(--muted); width: 80px; flex-shrink: 0; font-size: 12px; }
        .sum-val { font-weight: 600; flex: 1; }

        /* ── ALERT ── */
        .alert {
            margin: 12px 16px 0;
            padding: 13px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid;
            display: flex; align-items: flex-start; gap: 10px;
            line-height: 1.4;
        }
        .alert i { margin-top: 1px; flex-shrink: 0; }
        .alert.success { background: var(--succ-bg); color: var(--succ-tx); border-color: var(--succ-bd); }
        .alert.error   { background: var(--err-bg);  color: var(--err-tx);  border-color: var(--err-bd); }

        /* ── SECTION DIVIDER ── */
        .sec-div {
            height: 8px;
            background: var(--bg);
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            margin-top: 16px;
        }

        /* ── VIP / INFO styles (kept simple) ── */
        .simple-card {
            margin: 16px;
            background: var(--white);
            border: 1.5px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
        }
        .simple-card-head {
            padding: 12px 16px;
            background: var(--bg);
            border-bottom: 1px solid var(--border);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--muted);
            display: flex; align-items: center; gap: 7px;
        }
        .simple-card-head i { color: var(--gold); }
        .simple-card-body { padding: 20px; }
        .info-item {
            display: flex; align-items: flex-start; gap: 14px;
            padding: 12px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            margin-bottom: 10px;
        }
        .info-item:last-child { margin-bottom: 0; }
        .info-ic {
            width: 38px; height: 38px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .ic-gold   { background: var(--gold-lt); color: var(--gold); }
        .ic-green  { background: #dcfce7; color: #16a34a; }
        .ic-blue   { background: #dbeafe; color: #2563eb; }
        .ic-red    { background: #fee2e2; color: #dc2626; }
        .info-ic + div h4 { font-size: 13px; font-weight: 700; margin-bottom: 2px; }
        .info-ic + div p  { font-size: 12px; color: var(--muted); line-height: 1.4; }
        .vip-hero { text-align: center; padding: 0 0 16px; }
        .vip-crown {
            width: 64px; height: 64px; border-radius: 50%;
            background: var(--gold-lt); border: 2px solid #fcd34d;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; color: var(--gold); margin: 0 auto 12px;
        }
        .vip-hero h3 { font-size: 17px; font-weight: 800; }
        .vip-hero p  { color: var(--muted); font-size: 13px; margin-top: 6px; line-height: 1.5; }
        .stars-row { display: flex; justify-content: center; gap: 6px; margin: 14px 0; flex-wrap: wrap; }
        .star-dot {
            width: 30px; height: 30px; border-radius: 50%;
            background: var(--bg); border: 1.5px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; color: var(--faint);
        }
        .star-dot.on { background: var(--gold-lt); border-color: #fcd34d; color: var(--gold); }
        .vip-res {
            margin-top: 14px; padding: 14px; border-radius: 10px;
            background: var(--bg); border: 1.5px solid var(--border);
            font-size: 13px; display: none;
        }
        .vip-res.show { display: block; }
        .vip-res.found { border-color: #fcd34d; background: var(--gold-lt); }
        .vip-res-name { font-size: 15px; font-weight: 800; margin-bottom: 4px; }
        .vip-res-pts  { font-size: 13px; color: var(--muted); }
        .vip-res-pts span { font-weight: 700; color: var(--gold); font-size: 18px; }
        .prog-bar { height: 7px; background: #e5e7eb; border-radius: 999px; overflow: hidden; margin: 8px 0 3px; }
        .prog-fill { height: 100%; background: linear-gradient(90deg, var(--gold), #e9c46a); border-radius: 999px; transition: width .4s; }
        .prog-lbl { font-size: 11px; color: var(--faint); text-align: right; }
        .spinner {
            display: inline-block; width: 15px; height: 15px;
            border: 2px solid rgba(0,0,0,.12); border-top-color: var(--gold);
            border-radius: 50%; animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .btn-outline {
            width: 100%; padding: 12px;
            border: 1.5px solid var(--border); border-radius: 10px;
            background: var(--bg); color: var(--muted);
            font-size: 13px; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all .18s;
        }
        .btn-outline:hover { border-color: var(--navy); color: var(--navy); background: var(--navy-lt); }
        .btn-gold {
            width: 100%; padding: 12px;
            border: none; border-radius: 10px;
            background: var(--gold); color: white;
            font-size: 13px; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: background .18s;
        }
        .btn-gold:hover { background: #a87c32; }

        /* ── BOOK BAR ── */
        .book-bar {
            position: fixed;
            bottom: 60px; left: 0; right: 0;
            background: var(--white);
            padding: 10px 16px;
            border-top: 1px solid var(--border);
            z-index: 150;
        }
        .book-btn {
            width: 100%;
            padding: 15px 20px;
            border: none;
            border-radius: 12px;
            background: var(--navy);
            color: white;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 12px;
            transition: background .18s;
        }
        .book-btn:hover { background: var(--navy-dk); }
        .book-badge {
            background: var(--gold);
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            display: none;
        }

        /* ── BOTTOM NAV ── */
        .bottom-nav {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            height: 60px;
            background: var(--white);
            border-top: 1px solid var(--border);
            display: flex;
            z-index: 200;
        }
        .nav-item {
            flex: 1;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 3px;
            text-decoration: none;
            color: var(--faint);
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            transition: color .18s;
        }
        .nav-item i { font-size: 19px; }
        .nav-item.active { color: var(--navy); }
        .nav-item:hover  { color: var(--navy); }

        @media (max-width: 380px) {
            .time-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<!-- APP HEADER -->
<header class="app-header">
    <a href="../index.php" class="hdr-btn"><i class="fas fa-chevron-left"></i></a>
    <h1><?= $tab === 'club' ? 'Club VIP' : ($tab === 'info' ? 'Información' : 'Reservar Cita') ?></h1>
    <a href="?tab=club" class="hdr-btn"><i class="fas fa-user"></i></a>
</header>

<?php if ($tab === 'reservar'): ?>

<!-- STEP BAR -->
<div class="step-bar">
    <div class="step-item">
        <div class="step-dot active" id="d1">1</div>
        <div class="step-label active" id="l1">Servicio</div>
    </div>
    <div class="step-connector" id="c1"></div>
    <div class="step-item">
        <div class="step-dot" id="d2">2</div>
        <div class="step-label" id="l2">Fecha y Hora</div>
    </div>
    <div class="step-connector" id="c2"></div>
    <div class="step-item">
        <div class="step-dot" id="d3">3</div>
        <div class="step-label" id="l3">Barbero</div>
    </div>
    <div class="step-connector" id="c3"></div>
    <div class="step-item">
        <div class="step-dot" id="d4">4</div>
        <div class="step-label" id="l4">Confirmar</div>
    </div>
</div>

<div class="main">

    <?php if (!empty($msg)): ?>
    <div class="alert <?= $msg_type ?>">
        <i class="fas fa-<?= $msg_type === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
        <span><?= htmlspecialchars($msg) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" action="?tab=reservar" id="formReserva">
        <input type="hidden" name="csrf_token"  value="<?= csrf_generate() ?>">
        <input type="hidden" name="action"       value="agendar">
        <input type="hidden" name="servicio"     id="srv_val">
        <input type="hidden" name="barbero_id"   id="brb_val">
        <input type="hidden" name="fecha"        id="fec_val">
        <input type="hidden" name="hora"         id="hor_val">

        <!-- ═══ PASO 1: SERVICIOS ═══ -->
        <div class="sec-title"><i class="fas fa-scissors" style="color:var(--gold)"></i> Selecciona tus servicios</div>

        <div class="search-wrap">
            <input type="text" class="search-inp" placeholder="Buscar..." id="svcSearch" oninput="filterSvc(this.value)">
        </div>

        <div class="svc-list" id="svcList">
            <?php foreach ($servicios_list as $sv): ?>
            <div class="svc-card"
                 onclick="selSvc(this,'<?= htmlspecialchars($sv['nombre'],ENT_QUOTES) ?>','<?= htmlspecialchars($sv['precio']??'',ENT_QUOTES) ?>')"
                 data-q="<?= strtolower(htmlspecialchars($sv['nombre'])) ?>">
                <div class="svc-icon"><i class="<?= htmlspecialchars($sv['icono']??'fas fa-cut') ?>"></i></div>
                <div class="svc-info">
                    <div class="svc-name"><?= htmlspecialchars($sv['nombre']) ?></div>
                    <div class="svc-meta"><?= htmlspecialchars(($sv['precio']??'') . ' — ' . ($sv['duracion']??'')) ?></div>
                </div>
                <button type="button" class="svc-btn">
                    <i class="fas fa-plus"></i><i class="fas fa-check" style="display:none"></i>
                </button>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="sec-div"></div>

        <!-- ═══ PASO 2: FECHA Y HORA ═══ -->
        <div class="sec-title"><i class="fas fa-calendar" style="color:var(--gold)"></i> Selecciona fecha y hora</div>

        <div class="cal-wrap">
            <div class="cal-head">
                <button type="button" class="cal-nav" onclick="shiftMonth(-1)"><i class="fas fa-chevron-left"></i></button>
                <strong id="calLabel"></strong>
                <button type="button" class="cal-nav" onclick="shiftMonth(1)"><i class="fas fa-chevron-right"></i></button>
            </div>
            <div class="cal-grid" id="calGrid"></div>
        </div>

        <div class="time-grid" id="timeGrid"></div>

        <div class="sec-div"></div>

        <!-- ═══ PASO 3: ESPECIALISTA ═══ -->
        <div class="sec-title"><i class="fas fa-user-tie" style="color:var(--gold)"></i> Selecciona tu especialista</div>

        <?php if (!empty($barberos)): ?>
        <div class="spec-track">
            <?php foreach ($barberos as $b):
                $foto = $b['foto_url'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($b['nombre']) . '&background=1a3461&color=fff&size=128&bold=true';
            ?>
            <div class="spec-card" onclick="selSpec(this,<?= $b['id'] ?>,'<?= htmlspecialchars($b['nombre'],ENT_QUOTES) ?>')">
                <img class="spec-photo"
                     src="<?= htmlspecialchars($foto) ?>"
                     alt="<?= htmlspecialchars($b['nombre']) ?>"
                     onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($b['nombre']) ?>&background=1a3461&color=fff&size=128&bold=true'">
                <div class="spec-name"><?= htmlspecialchars($b['nombre']) ?></div>
                <div class="spec-role">Barbería</div>
                <div class="spec-star"><i class="fas fa-star" style="font-size:9px"></i> 5.0</div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p style="padding:0 16px 4px;color:var(--muted);font-size:13px;">No hay especialistas disponibles.</p>
        <?php endif; ?>

        <div class="sec-div"></div>

        <!-- ═══ PASO 4: DATOS + CONFIRMACIÓN ═══ -->
        <div class="sec-title"><i class="fas fa-clipboard-check" style="color:var(--gold)"></i> Tus datos y confirmación</div>

        <div class="fields">
            <div class="fld">
                <label>Nombre completo</label>
                <input type="text" name="cliente_nombre" placeholder="Ej. Juan Pérez" required
                       value="<?= htmlspecialchars($_POST['cliente_nombre']??'') ?>">
            </div>
            <div class="fld">
                <label>Teléfono</label>
                <input type="tel" name="cliente_telefono" placeholder="Ej. 04121234567" required
                       value="<?= htmlspecialchars($_POST['cliente_telefono']??'') ?>">
            </div>
            <div class="fld">
                <label>Método de pago</label>
                <select name="metodo_pago" id="metodo_pago" onchange="onPago()" required>
                    <option value="">— Selecciona —</option>
                    <?php if (($config['estado_pago_movil']??'0')==='1'): ?>
                        <option value="Pago Móvil">Pago Móvil (Bs)</option>
                    <?php endif; ?>
                    <?php if (($config['estado_zelle']??'0')==='1'): ?>
                        <option value="Zelle">Zelle ($)</option>
                    <?php endif; ?>
                    <?php if (($config['estado_efectivo']??'0')==='1'): ?>
                        <option value="Efectivo">Efectivo en local</option>
                    <?php endif; ?>
                </select>
            </div>
        </div>

        <div class="pay-box blue" id="info_pm">
            <div class="pb-title"><i class="fas fa-university"></i> Datos de Pago Móvil</div>
            <div class="pb-row"><span>Banco</span><strong><?= htmlspecialchars($config['banco_nombre']??'—') ?></strong></div>
            <div class="pb-row"><span>Teléfono</span><strong><?= htmlspecialchars($config['banco_telefono']??'—') ?></strong></div>
            <div class="pb-row"><span>Cédula/RIF</span><strong><?= htmlspecialchars($config['banco_ci']??'—') ?></strong></div>
        </div>
        <div class="pay-box purple" id="info_zelle">
            <div class="pb-title"><i class="fas fa-dollar-sign"></i> Cuenta Zelle</div>
            <div class="pb-mono"><?= htmlspecialchars($config['zelle_email']??'—') ?></div>
        </div>

        <div id="refWrap" style="display:none;padding:0 16px;margin-top:12px;">
            <div class="fld">
                <label>Número de referencia</label>
                <input type="text" id="referencia_pago" name="referencia_pago" placeholder="Ej. 45892314">
            </div>
        </div>

        <!-- SUMMARY -->
        <div class="sum-wrap" id="sumWrap" style="display:none">
            <div class="sum-card">
                <div class="sum-head"><i class="fas fa-calendar-check" style="color:var(--gold);margin-right:6px"></i> Detalles de la cita</div>
                <div class="sum-body" id="sumBody"></div>
            </div>
        </div>

        <button type="submit" id="realSubmit" style="display:none"></button>
    </form>

</div><!-- .main -->

<!-- BOOK BAR -->
<div class="book-bar">
    <button type="button" class="book-btn" onclick="doSubmit()">
        <span>Reservar Ahora</span>
        <span class="book-badge" id="bookBadge"></span>
    </button>
</div>

<!-- BOTTOM NAV -->
<nav class="bottom-nav">
    <a href="cliente.php" class="nav-item active"><i class="fas fa-home"></i><span>Inicio</span></a>
    <a href="cliente.php" class="nav-item"><i class="fas fa-scissors"></i><span>Servicios</span></a>
    <a href="cliente.php?tab=club" class="nav-item"><i class="fas fa-calendar-check"></i><span>Citas</span></a>
    <a href="cliente.php?tab=club" class="nav-item"><i class="fas fa-user"></i><span>Perfil</span></a>
</nav>

<script>
// ── STATE ──
let ST = { svc:'', precio:'', bid:0, bnom:'', fecha:'', hora:'' };
let calY = new Date().getFullYear(), calM = new Date().getMonth();
const MN = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
const DS = ['Lu','Ma','Mi','Ju','Vi','Sá','Do'];
const MNA = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
const DNA = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
const SLOTS = ['09:00','09:30','10:00','10:30','11:00','11:30','12:30','13:00','13:30','14:00','14:30','15:00','15:30','16:00','16:30','17:00'];

// ── SERVICE ──
function selSvc(card, name, precio) {
    document.querySelectorAll('.svc-card').forEach(c => {
        c.classList.remove('sel');
        c.querySelector('.fa-check').style.display = 'none';
        c.querySelector('.fa-plus').style.display  = '';
    });
    card.classList.add('sel');
    card.querySelector('.fa-plus').style.display  = 'none';
    card.querySelector('.fa-check').style.display = '';
    ST.svc = name; ST.precio = precio;
    document.getElementById('srv_val').value = name;
    syncSummary(); updateDots(); updateBadge();
}
function filterSvc(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.svc-card').forEach(c =>
        c.style.display = c.dataset.q.includes(q) ? '' : 'none');
}

// ── CALENDAR ──
function renderCal() {
    const now   = new Date(); now.setHours(0,0,0,0);
    const label = document.getElementById('calLabel');
    label.textContent = MN[calM] + ' ' + calY;
    const grid = document.getElementById('calGrid');
    grid.innerHTML = '';
    DS.forEach(d => { const el=document.createElement('div'); el.className='cal-dow'; el.textContent=d; grid.appendChild(el); });
    const first = new Date(calY, calM, 1);
    let dow = first.getDay(); dow = dow === 0 ? 6 : dow - 1;
    for (let i=0;i<dow;i++) { const e=document.createElement('div'); e.className='cal-day empty'; grid.appendChild(e); }
    const days = new Date(calY, calM+1, 0).getDate();
    const todayStr = fmtDate(now);
    for (let d=1;d<=days;d++) {
        const ds = calY+'-'+pad(calM+1)+'-'+pad(d);
        const el = document.createElement('div');
        el.className = 'cal-day';
        el.textContent = d;
        const dt = new Date(calY, calM, d);
        if (dt < now)         el.classList.add('dis');
        if (ds === todayStr)  el.classList.add('today');
        if (ds === ST.fecha)  el.classList.add('sel');
        el.onclick = () => pickDate(ds);
        grid.appendChild(el);
    }
}
function shiftMonth(dir) {
    calM += dir;
    if (calM < 0)  { calM=11; calY--; }
    if (calM > 11) { calM=0;  calY++; }
    const now = new Date();
    if (calY < now.getFullYear() || (calY===now.getFullYear() && calM < now.getMonth())) {
        calY = now.getFullYear(); calM = now.getMonth();
    }
    renderCal();
}
function pickDate(ds) {
    ST.fecha = ds; document.getElementById('fec_val').value = ds;
    renderCal(); renderSlots(); syncSummary(); updateDots();
}

// ── TIME SLOTS ──
function renderSlots() {
    const grid = document.getElementById('timeGrid');
    grid.innerHTML = '';
    const now = new Date();
    const todayStr = fmtDate(now);
    SLOTS.forEach(s => {
        const [h,m] = s.split(':');
        const el = document.createElement('div');
        el.className = 't-slot';
        const hr = parseInt(h);
        const sfx = hr >= 12 ? 'PM' : 'AM';
        const h12 = hr>12 ? hr-12 : (hr===0?12:hr);
        el.textContent = h12+':'+m+' '+sfx;
        if (ST.fecha === todayStr) {
            const st = new Date(); st.setHours(hr,parseInt(m),0,0);
            if (st <= now) { el.classList.add('t-dis'); }
        }
        if (s === ST.hora) el.classList.add('sel');
        el.onclick = () => pickSlot(el, s);
        grid.appendChild(el);
    });
}
function pickSlot(el, s) {
    document.querySelectorAll('.t-slot').forEach(b => b.classList.remove('sel'));
    el.classList.add('sel');
    ST.hora = s; document.getElementById('hor_val').value = s;
    syncSummary(); updateDots();
}

// ── SPECIALIST ──
function selSpec(card, id, nom) {
    document.querySelectorAll('.spec-card').forEach(c => c.classList.remove('sel'));
    card.classList.add('sel');
    ST.bid = id; ST.bnom = nom;
    document.getElementById('brb_val').value = id;
    syncSummary(); updateDots();
}

// ── SUMMARY ──
function syncSummary() {
    const wrap = document.getElementById('sumWrap');
    const body = document.getElementById('sumBody');
    if (!ST.svc && !ST.fecha && !ST.bid) { wrap.style.display='none'; return; }
    wrap.style.display = 'block';
    let html = '';
    if (ST.svc)   html += row('Servicio', ST.svc + (ST.precio ? ' <span style="color:var(--gold);font-size:11px">('+ST.precio+')</span>' : ''));
    if (ST.fecha) {
        const dt = new Date(ST.fecha+'T12:00:00');
        const [y,mo,d] = ST.fecha.split('-');
        html += row('Fecha', DNA[dt.getDay()]+' '+d+' de '+MNA[parseInt(mo)-1]);
    }
    if (ST.hora) {
        const [h,m] = ST.hora.split(':');
        const hr=parseInt(h); const sfx=hr>=12?'PM':'AM'; const h12=hr>12?hr-12:(hr===0?12:hr);
        html += row('Hora', h12+':'+m+' '+sfx);
    }
    if (ST.bnom)  html += row('Especialista', ST.bnom);
    body.innerHTML = html;
}
function row(lbl,val) { return `<div class="sum-row"><span class="sum-lbl">${lbl}</span><span class="sum-val">${val}</span></div>`; }

// ── STEP DOTS ──
function updateDots() {
    const done = [!!ST.svc, !!(ST.fecha&&ST.hora), !!ST.bid, false];
    let active = 3;
    for (let i=0;i<3;i++) if (!done[i]) { active=i; break; }
    for (let i=0;i<4;i++) {
        const d=document.getElementById('d'+(i+1));
        const l=document.getElementById('l'+(i+1));
        const c=document.getElementById('c'+(i+1));
        d.classList.remove('active','done');
        l.classList.remove('active','done');
        if (done[i])    { d.classList.add('done'); d.innerHTML='<i class="fas fa-check" style="font-size:9px"></i>'; l.classList.add('done'); if(c) c.classList.add('done'); }
        else if(i===active) { d.classList.add('active'); d.textContent=i+1; l.classList.add('active'); }
        else             { d.textContent=i+1; if(c) c.classList.remove('done'); }
    }
}
function updateBadge() {
    const b = document.getElementById('bookBadge');
    if (ST.precio) { b.textContent=ST.precio; b.style.display='inline-block'; }
    else b.style.display='none';
}

// ── PAYMENT ──
function onPago() {
    const v = document.getElementById('metodo_pago').value;
    document.getElementById('info_pm').style.display    = v==='Pago Móvil' ? 'block':'none';
    document.getElementById('info_zelle').style.display = v==='Zelle'      ? 'block':'none';
    const rw = document.getElementById('refWrap');
    const ri = document.getElementById('referencia_pago');
    if (v==='Pago Móvil'||v==='Zelle') { rw.style.display='block'; ri.required=true; }
    else { rw.style.display='none'; ri.required=false; ri.value=''; }
}

// ── SUBMIT ──
function doSubmit() {
    if (!ST.svc)   { alert('Por favor selecciona un servicio.'); return; }
    if (!ST.fecha) { alert('Por favor selecciona una fecha.'); return; }
    if (!ST.hora)  { alert('Por favor selecciona un horario.'); return; }
    if (!ST.bid)   { alert('Por favor selecciona un especialista.'); return; }
    const form = document.getElementById('formReserva');
    if (!form.reportValidity()) return;
    form.submit();
}

// ── UTILS ──
function pad(n) { return String(n).padStart(2,'0'); }
function fmtDate(d) { return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate()); }

// ── INIT ──
renderCal();
renderSlots();
updateDots();
</script>

<?php elseif ($tab === 'club'): ?>

<div class="main" style="padding-bottom:80px">

    <?php if (!empty($msg)): ?>
    <div class="alert <?= $msg_type ?>" style="margin-top:12px">
        <i class="fas fa-<?= $msg_type==='success'?'circle-check':'circle-exclamation' ?>"></i>
        <span><?= htmlspecialchars($msg) ?></span>
    </div>
    <?php endif; ?>

    <div class="simple-card" style="margin-top:16px">
        <div class="simple-card-head"><i class="fas fa-crown"></i> Club VIP AlCorte</div>
        <div class="simple-card-body">
            <div class="vip-hero">
                <div class="vip-crown"><i class="fas fa-crown"></i></div>
                <h3>Acumula Estrellas</h3>
                <p>Por cada cita confirmada ganas 1 estrella.<br>Al llegar a 10, ¡tu próximo corte es gratis!</p>
            </div>
            <div class="stars-row" id="starsRow">
                <?php for ($i=0;$i<10;$i++): ?>
                <div class="star-dot" id="st<?= $i ?>"><i class="fas fa-star"></i></div>
                <?php endfor; ?>
            </div>
            <div class="fld" style="margin-bottom:10px">
                <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);display:block;margin-bottom:5px">Tu número de teléfono</label>
                <input type="tel" id="vipPhone" class="search-inp" placeholder="Ej. 04121234567" style="text-align:center;letter-spacing:1px">
            </div>
            <button class="btn-outline" onclick="checkVip()"><i class="fas fa-search"></i> Consultar mis puntos</button>
            <div class="vip-res" id="vipRes"></div>
        </div>
    </div>

    <div class="simple-card">
        <div class="simple-card-head"><i class="fas fa-gift"></i> Beneficios del Club</div>
        <div class="simple-card-body">
            <?php foreach ([
                ['5 estrellas',  'Descuento del 10% en tu próximo servicio.',      'fas fa-tag',     'ic-gold'],
                ['10 estrellas', 'Corte clásico completamente gratis.',             'fas fa-scissors','ic-green'],
                ['15 estrellas', 'Turno VIP preferencial sin espera.',              'fas fa-bolt',    'ic-blue'],
            ] as [$lvl,$desc,$ico,$cls]): ?>
            <div class="info-item">
                <div class="info-ic <?= $cls ?>"><i class="<?= $ico ?>"></i></div>
                <div><h4><?= $lvl ?></h4><p><?= $desc ?></p></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<nav class="bottom-nav">
    <a href="cliente.php" class="nav-item"><i class="fas fa-home"></i><span>Inicio</span></a>
    <a href="cliente.php" class="nav-item"><i class="fas fa-scissors"></i><span>Servicios</span></a>
    <a href="cliente.php?tab=club" class="nav-item active"><i class="fas fa-calendar-check"></i><span>Citas</span></a>
    <a href="cliente.php?tab=club" class="nav-item active"><i class="fas fa-user"></i><span>Perfil</span></a>
</nav>

<script>
function checkVip() {
    const tel = document.getElementById('vipPhone').value.trim();
    const box = document.getElementById('vipRes');
    if (!tel) { alert('Ingresa tu número de teléfono.'); return; }
    box.className = 'vip-res show';
    box.innerHTML = '<span class="spinner"></span> Buscando...';
    fetch(`../backend/api/club.php?telefono=${encodeURIComponent(tel)}`)
        .then(r=>r.json())
        .then(data => {
            if (data.encontrado) {
                const pts = data.puntos, pct = Math.min(pts/10*100,100);
                const next = pts>=10 ? '¡Tienes un corte gratis!' : `Faltan ${10-pts} para corte gratis`;
                for (let i=0;i<10;i++) {
                    const s = document.getElementById('st'+i);
                    if(s) s.className = i<pts ? 'star-dot on' : 'star-dot';
                }
                box.className = 'vip-res show found';
                box.innerHTML = `
                    <div class="vip-res-name">⭐ ${data.nombre}</div>
                    <div class="vip-res-pts"><span>${pts}</span> / 10 estrellas</div>
                    <div class="prog-bar"><div class="prog-fill" style="width:${pct}%"></div></div>
                    <div class="prog-lbl">${next}</div>
                    <div style="font-size:11px;color:var(--faint);margin-top:8px">Última visita: ${data.ultima_visita}</div>`;
            } else {
                for (let i=0;i<10;i++) { const s=document.getElementById('st'+i); if(s) s.className='star-dot'; }
                box.className = 'vip-res show';
                box.innerHTML = '<i class="fas fa-circle-xmark" style="color:#ef4444;margin-right:6px"></i> Número no registrado en el Club VIP.';
            }
        })
        .catch(() => { box.innerHTML='Error de conexión. Intenta de nuevo.'; });
}
document.getElementById('vipPhone')?.addEventListener('keydown', e => { if(e.key==='Enter'){e.preventDefault();checkVip();} });
</script>

<?php elseif ($tab === 'info'): ?>

<div class="main" style="padding-bottom:80px">

    <div class="simple-card" style="margin-top:16px">
        <div class="simple-card-head"><i class="fas fa-circle-info"></i> Información</div>
        <div class="simple-card-body">
            <div class="info-item">
                <div class="info-ic ic-gold"><i class="fas fa-location-dot"></i></div>
                <div><h4>Ubicación</h4><p><?= htmlspecialchars($config['direccion']??'Av. Las Delicias, Maracay') ?></p></div>
            </div>
            <div class="info-item">
                <div class="info-ic ic-green"><i class="fas fa-clock"></i></div>
                <div><h4>Horario de Atención</h4><p><?= htmlspecialchars($config['horario']??'Lunes a Sábado: 9:00 AM – 5:00 PM') ?></p></div>
            </div>
            <div class="info-item">
                <div class="info-ic ic-blue"><i class="fas fa-phone"></i></div>
                <div><h4>Contacto</h4><p><?= htmlspecialchars($config['contacto']??$config['banco_telefono']??'No disponible') ?></p></div>
            </div>
            <div class="info-item">
                <div class="info-ic ic-red"><i class="fas fa-triangle-exclamation"></i></div>
                <div><h4>Política de Reservas</h4><p><?= htmlspecialchars($config['politica_reserva']??'Tienes 15 minutos para confirmar tu pago digital. Pasado ese tiempo, el turno puede liberarse.') ?></p></div>
            </div>
        </div>
    </div>

    <div class="simple-card">
        <div class="simple-card-head"><i class="fas fa-credit-card"></i> Métodos de Pago</div>
        <div class="simple-card-body">
            <?php if (($config['estado_pago_movil']??'0')==='1'): ?>
            <div class="info-item">
                <div class="info-ic ic-blue"><i class="fas fa-mobile-alt"></i></div>
                <div><h4>Pago Móvil</h4><p><?= htmlspecialchars(($config['banco_nombre']??'').' · '.($config['banco_telefono']??'').' · '.($config['banco_ci']??'')) ?></p></div>
            </div>
            <?php endif; ?>
            <?php if (($config['estado_zelle']??'0')==='1'): ?>
            <div class="info-item">
                <div class="info-ic" style="background:#f3e8ff;color:#7c3aed"><i class="fas fa-dollar-sign"></i></div>
                <div><h4>Zelle</h4><p><?= htmlspecialchars($config['zelle_email']??'') ?></p></div>
            </div>
            <?php endif; ?>
            <?php if (($config['estado_efectivo']??'0')==='1'): ?>
            <div class="info-item">
                <div class="info-ic ic-green"><i class="fas fa-money-bill-wave"></i></div>
                <div><h4>Efectivo</h4><p>Pago directo en local al momento de la visita.</p></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<nav class="bottom-nav">
    <a href="cliente.php" class="nav-item"><i class="fas fa-home"></i><span>Inicio</span></a>
    <a href="cliente.php" class="nav-item"><i class="fas fa-scissors"></i><span>Servicios</span></a>
    <a href="cliente.php?tab=club" class="nav-item"><i class="fas fa-calendar-check"></i><span>Citas</span></a>
    <a href="cliente.php?tab=info" class="nav-item active"><i class="fas fa-user"></i><span>Perfil</span></a>
</nav>

<?php endif; ?>

</body>
</html>
