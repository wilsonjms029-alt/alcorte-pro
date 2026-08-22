<?php
require_once '../backend/config/config.php';

$msg      = "";
$msg_type = "success";
$tab   = $_GET['tab'] ?? 'reservar';
$token = preg_replace('/[^a-f0-9]/', '', strtolower($_GET['t'] ?? ''));

if (strlen($token) !== 32) {
    http_response_code(404);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>No encontrado</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;color:#64748b"><h2>Enlace no válido</h2><p>El enlace de reservas que usaste no es válido.<br>Solicita el enlace correcto a tu barbería.</p></body></html>');
}

$suc_row = $conn->prepare("SELECT id, nombre FROM sucursales WHERE token = ? AND activo = 1 LIMIT 1");
$suc_row->bind_param("s", $token);
$suc_row->execute();
$suc_row = $suc_row->get_result()->fetch_assoc();

if (!$suc_row) {
    http_response_code(404);
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>No encontrado</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;color:#64748b"><h2>Barbería no encontrada</h2><p>El enlace de reservas no corresponde a ninguna barbería activa.</p></body></html>');
}

$sucursal_id     = $suc_row['id'];
$nombre_sucursal = $suc_row['nombre'];

// ── PLAN DE LA SUCURSAL ──
$plan_activo = get_plan_sucursal($conn, $sucursal_id);
$has_productos  = $plan_activo ? $plan_activo['has_productos'] : false;
if (!$has_productos && $tab === 'productos') $tab = 'reservar';

// ── LOAD SERVICES (DB first, static fallback) ──
$servicios_list = [];
try {
    $res_svc = $conn->prepare("SELECT * FROM servicios WHERE activo = 1 AND sucursal_id = ? ORDER BY orden ASC, id ASC");
    $res_svc->bind_param("i", $sucursal_id);
    $res_svc->execute();
    $res_svc = $res_svc->get_result();
    if ($res_svc) while ($s = $res_svc->fetch_assoc()) $servicios_list[] = $s;
} catch (\Throwable $e) {
    error_log('Error cargando servicios: ' . $e->getMessage());
}
$servicios_validos = array_column($servicios_list, 'nombre');

// ── TIPO DE CAMBIO BCV ──
$bcv_tasa   = null;
$bcv_at     = '';
$bcv_cache  = __DIR__ . '/../backend/cache/bcv.json';
$bcv_ttl    = 4 * 3600; // 4 horas
if (file_exists($bcv_cache) && (time() - filemtime($bcv_cache)) < $bcv_ttl) {
    $c = json_decode(file_get_contents($bcv_cache), true);
    $bcv_tasa = $c['tasa'] ?? null;
    $bcv_at   = $c['at']   ?? '';
} else {
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'AlCortePro/1.0']]);
    $raw = @file_get_contents('https://ve.dolarapi.com/v1/dolares/oficial', false, $ctx);
    if ($raw) {
        $d = json_decode($raw, true);
        if (!empty($d['promedio'])) {
            $bcv_tasa = round((float)$d['promedio'], 2);
            $date = new DateTime("now", new DateTimeZone('America/Caracas'));
            $bcv_at   = $date->format('d/m/Y H:i');
            @mkdir(dirname($bcv_cache), 0775, true);
            file_put_contents($bcv_cache, json_encode(['tasa' => $bcv_tasa, 'at' => $bcv_at]));
        }
    }
}

// ── LOAD CONFIG (before POST so it's available for WhatsApp link) ──
$config = [];
$res_conf = $conn->prepare("SELECT clave, valor FROM configuracion WHERE sucursal_id = ?");
$res_conf->bind_param("i", $sucursal_id);
$res_conf->execute();
$res_conf = $res_conf->get_result();
if ($res_conf) while ($row = $res_conf->fetch_assoc()) $config[$row['clave']] = $row['valor'];

// ── LOAD BARBERS ──
$barberos = [];
$res_b = $conn->prepare("SELECT id, nombre, foto_url FROM barberos WHERE activo = 1 AND sucursal_id = ? ORDER BY nombre");
$res_b->bind_param("i", $sucursal_id);
$res_b->execute();
$res_b = $res_b->get_result();
if ($res_b) while ($b = $res_b->fetch_assoc()) $barberos[] = $b;

$nombre_negocio = $config['nombre_negocio'] ?? 'AlCorte Pro';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title><?= htmlspecialchars($nombre_sucursal) ?> — Reservas</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        <?php
        $color_acento = $config['color_acento'] ?? '#D4AF37';
        list($ar, $ag, $ab) = sscanf($color_acento, "#%02x%02x%02x");
        $color_primario = $config['color_primario'] ?? '#1a3461';
        list($pr, $pg, $pb) = sscanf($color_primario, "#%02x%02x%02x");
        ?>
        :root {
            --gold:       <?= $color_acento ?>;
            --gold-dark:  rgba(<?= $ar ?>, <?= $ag ?>, <?= $ab ?>, 0.8);
            --gold-glow:  rgba(<?= $ar ?>, <?= $ag ?>, <?= $ab ?>, 0.25);
            --gold-lt:    rgba(<?= $ar ?>, <?= $ag ?>, <?= $ab ?>, 0.12);
            --border-gold: rgba(<?= $ar ?>, <?= $ag ?>, <?= $ab ?>, 0.3);
            
            --primary:      <?= $color_primario ?>;
            --primary-glow: rgba(<?= $pr ?>, <?= $pg ?>, <?= $pb ?>, 0.25);
            --primary-lt:   rgba(<?= $pr ?>, <?= $pg ?>, <?= $pb ?>, 0.12);
            
            --dark-bg:    #070709;
            --card-bg:    #111115;
            --surface:    #18181f;
            --surface-hover: #22222a;
            --border:     rgba(255, 255, 255, 0.08);
            --txt:        #FFFFFF;
            --muted:      #94a3b8;
            --faint:      #64748b;
            --succ-bg:    rgba(16, 185, 129, 0.12); --succ-tx: #34d399; --succ-bd: rgba(16, 185, 129, 0.3);
            --err-bg:     rgba(239, 68, 68, 0.12);  --err-tx:  #f87171; --err-bd:  rgba(239, 68, 68, 0.3);
        }

        body {
            font-family: 'Outfit', 'Inter', system-ui, sans-serif;
            background-color: var(--dark-bg);
            background-image: 
                radial-gradient(circle at 50% 0%, var(--primary-glow) 0%, transparent 60%),
                radial-gradient(circle at 100% 100%, var(--gold-lt) 0%, transparent 50%);
            background-attachment: fixed;
            color: var(--txt);
            font-size: 14px;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 16px 12px 32px;
        }

        .page-card {
            width: 100%;
            max-width: 480px;
            background: var(--card-bg);
            border-radius: 24px;
            border: 1px solid var(--border-gold);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.8), 0 0 30px var(--primary-glow);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            isolation: isolate;
        }

        /* ── APP HEADER ── */
        .app-header {
            background: linear-gradient(180deg, #181820 0%, #111115 100%);
            display: flex;
            flex-direction: column;
            border-bottom: 1px solid var(--border);
            border-radius: 24px 24px 0 0;
        }
        .hdr-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px 10px;
        }
        .hdr-logo {
            font-size: 15px;
            font-weight: 800;
            color: var(--gold);
            letter-spacing: 0.5px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 8px;
            filter: drop-shadow(0 2px 8px var(--gold-glow));
        }
        .hdr-logo i { font-size: 14px; }
        .hdr-sucursal {
            font-size: 12px;
            font-weight: 500;
            color: var(--muted);
            background: rgba(255, 255, 255, 0.05);
            padding: 4px 10px;
            border-radius: 20px;
            border: 1px solid var(--border);
        }
        .hdr-tabs {
            display: flex;
            width: 100%;
            padding: 0 6px;
        }
        .hdr-tab {
            flex: 1;
            text-align: center;
            padding: 12px 0;
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--muted);
            border-bottom: 2px solid transparent;
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .hdr-tab i { font-size: 12px; transition: transform 0.25s; }
        .hdr-tab:hover { color: var(--txt); }
        .hdr-tab.act {
            color: var(--gold);
            border-bottom-color: var(--gold);
            background: rgba(212, 175, 55, 0.05);
        }
        .hdr-tab.act i { transform: scale(1.1); }

        /* ── MAIN ── */
        .main { max-width: 500px; margin: 0 auto; padding-bottom: 12px; }

        /* ── SECTION TITLES ── */
        .sec-title {
            padding: 18px 18px 10px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: var(--txt);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sec-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }
        .step-num {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-dark) 100%);
            color: #000;
            font-size: 11px; font-weight: 800;
            flex-shrink: 0;
            box-shadow: 0 2px 8px var(--gold-glow);
        }

        /* ── SEARCH ── */
        .search-wrap { padding: 0 16px 8px; }
        .search-inp {
            width: 100%;
            padding: 12px 14px 12px 40px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 13px;
            font-family: inherit;
            background: var(--surface) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E") no-repeat 14px center;
            color: var(--txt);
            transition: all 0.25s ease;
        }
        .search-inp:focus { outline: none; border-color: var(--gold); box-shadow: 0 0 0 3px var(--gold-glow); }
        .search-inp::placeholder { color: var(--faint); }

        /* ── SERVICE CARDS ── */
        .svc-list,
        .spec-track {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            touch-action: pan-x;
            scroll-snap-type: x proximity;
            padding: 0 16px 8px;
            margin: 0;
            max-width: 100%;
        }
        .svc-list::-webkit-scrollbar,
        .spec-track::-webkit-scrollbar { display: none; }
        .svc-card {
            flex: 0 0 92px;
            scroll-snap-align: start;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 12px 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
        }
        .svc-card:hover { border-color: var(--border-gold); transform: translateY(-3px); background: var(--surface-hover); }
        .svc-card.sel   { border-color: var(--gold); background: var(--gold-lt); transform: translateY(-3px); box-shadow: 0 6px 16px var(--gold-glow); }
        .svc-photo {
            width: 48px; height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--border);
            transition: all 0.3s ease;
        }
        .svc-card.sel .svc-photo { border-color: var(--gold); }
        .svc-icon {
            width: 48px; height: 48px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            color: var(--gold);
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
            transition: all 0.3s ease;
            border: 1px solid var(--border);
        }
        .svc-card.sel .svc-icon { background: var(--gold); color: #000; border-color: var(--gold); }
        .svc-name { font-size: 11px; font-weight: 700; line-height: 1.2; color: var(--txt); }
        .svc-meta { font-size: 11px; color: var(--gold); font-weight: 700; }
        .svc-card.sel .svc-meta { color: var(--gold); }
        .svc-bs   { font-size: 9px; color: var(--muted); margin-top: -3px; }

        /* ── TOOLTIP INFO ── */
        .svc-info-btn {
            position: absolute;
            top: 6px; right: 6px;
            width: 18px; height: 18px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            color: var(--muted);
            display: flex; align-items: center; justify-content: center;
            font-size: 9px;
            cursor: default;
            z-index: 2;
        }
        .svc-tooltip {
            display: none;
            position: absolute;
            bottom: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%);
            background: #22222a;
            color: #f1f5f9;
            font-size: 11px;
            font-weight: 500;
            line-height: 1.4;
            padding: 8px 12px;
            border-radius: 10px;
            border: 1px solid var(--border-gold);
            width: max-content;
            max-width: 200px;
            text-align: center;
            z-index: 10;
            pointer-events: none;
            box-shadow: 0 8px 20px rgba(0,0,0,.6);
        }
        .svc-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: #22222a;
        }
        .svc-info-btn:hover + .svc-tooltip,
        .svc-info-btn:focus + .svc-tooltip {
            display: block;
        }
        .bcv-badge {
            display: flex; align-items: center; gap: 6px;
            font-size: 10px; color: var(--muted);
            padding: 6px 18px 0;
        }
        .bcv-badge i { font-size: 7px; }

        /* ── CALENDAR ── */
        .cal-wrap { padding: 0 16px; }
        .cal-head {
            padding: 8px 0 8px;
        }
        .cal-head strong {
            font-size: 15px; font-weight: 700; color: var(--txt);
        }
        .week-row {
            display: flex; align-items: center; gap: 6px;
        }
        .week-row .week-strip { flex: 1; }
        .cal-nav {
            width: 34px; height: 34px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--surface);
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            color: var(--muted);
            font-size: 12px;
            transition: all 0.2s ease;
        }
        .cal-nav:hover { border-color: var(--gold); color: var(--gold); background: var(--surface-hover); }

        /* Week strip */
        .week-strip {
            display: flex;
            gap: 4px;
        }
        .week-day {
            flex: 1;
            display: flex; flex-direction: column;
            align-items: center; gap: 6px;
            padding: 8px 2px 10px;
            border-radius: 14px;
            background: var(--surface);
            border: 1px solid var(--border);
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .week-day:not(.dis):hover { border-color: var(--border-gold); background: var(--surface-hover); }
        .week-day-label {
            font-size: 10px; font-weight: 600;
            color: var(--muted); text-transform: uppercase;
            letter-spacing: .3px;
        }
        .week-day.today .week-day-label { color: var(--gold); }
        .week-day-num {
            width: 32px; height: 32px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 600;
            color: var(--txt);
            transition: all 0.25s ease;
        }
        .week-day.sel { background: var(--gold-lt); border-color: var(--gold); }
        .week-day.sel .week-day-num { background: linear-gradient(135deg, var(--gold) 0%, var(--gold-dark) 100%); color: #000; font-weight: 800; box-shadow: 0 4px 12px var(--gold-glow); }
        .week-day.dis { opacity: .25; pointer-events: none; }

        /* Selected date title */
        .date-title {
            padding: 12px 16px 4px;
            font-size: 14px; font-weight: 700;
            color: var(--gold);
        }

        /* Time groups */
        .time-groups { padding: 0 16px; display: flex; flex-direction: column; gap: 12px; margin-top: 8px; }
        .tg-label {
            font-size: 11px; font-weight: 700;
            color: var(--muted); margin-bottom: 6px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .tg-slots { display: flex; flex-wrap: wrap; gap: 8px; }
        .t-slot {
            padding: 8px 14px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            font-size: 13px; font-weight: 500;
            color: var(--txt);
            cursor: pointer;
            transition: all 0.25s ease;
            white-space: nowrap;
        }
        .t-slot:not(.t-dis):hover { border-color: var(--gold); color: var(--gold); transform: translateY(-2px); }
        .t-slot.sel { background: linear-gradient(135deg, var(--gold) 0%, var(--gold-dark) 100%); border-color: var(--gold); color: #000; font-weight: 700; transform: translateY(-2px); box-shadow: 0 4px 12px var(--gold-glow); }
        .t-slot.t-dis { opacity: .25; pointer-events: none; }

        /* ── SPECIALIST CARDS ── */
        .spec-card {
            flex: 0 0 94px;
            scroll-snap-align: start;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 12px 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .spec-card:hover { border-color: var(--border-gold); transform: translateY(-3px); background: var(--surface-hover); }
        .spec-card.sel   { border-color: var(--gold); background: var(--gold-lt); transform: translateY(-3px); box-shadow: 0 6px 16px var(--gold-glow); }
        .spec-photo {
            width: 48px; height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--border);
            transition: all 0.3s ease;
        }
        .spec-card.sel .spec-photo { border-color: var(--gold); }
        .spec-name { font-size: 11px; font-weight: 700; text-align: center; line-height: 1.2; color: var(--txt); }
        .spec-role { font-size: 9px; color: var(--muted); text-align: center; }
        .spec-star { font-size: 10px; color: var(--gold); font-weight: 600; }

        /* ── CLIENT FIELDS ── */
        .fields { padding: 0 16px; display: flex; flex-direction: column; gap: 12px; }
        .fld label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
            color: var(--muted);
            margin-bottom: 6px;
        }
        .fld input, .fld select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 14px;
            font-family: inherit;
            color: var(--txt);
            background: var(--surface);
            appearance: none;
            -webkit-appearance: none;
            transition: all 0.25s ease;
        }
        .fld input:focus, .fld select:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 0 3px var(--gold-glow);
            background: var(--surface-hover);
        }
        .fld input::placeholder { color: var(--faint); }

        /* ── PAYMENT INFO ── */
        .pay-box {
            margin: 10px 16px 0;
            padding: 14px 16px;
            border-radius: 14px;
            font-size: 13px;
            line-height: 1.6;
            display: none;
            animation: slideDown .3s ease;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .pay-box.blue   { background: rgba(37, 99, 235, 0.1); border: 1px solid rgba(37, 99, 235, 0.3); color: #93c5fd; }
        .pay-box.purple { background: rgba(139, 92, 246, 0.1); border: 1px solid rgba(139, 92, 246, 0.3); color: #c4b5fd; }
        .pay-box.gold   { background: rgba(243, 186, 47, 0.1); border: 1px solid rgba(243, 186, 47, 0.3); color: #fef08a; }
        .pay-box.paypal { background: rgba(0, 48, 135, 0.1); border: 1px solid rgba(0, 48, 135, 0.3); color: #93c5fd; }
        .pb-title {
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 8px;
            display: flex; align-items: center; gap: 6px;
            color: var(--txt);
        }
        .pb-row { display: flex; justify-content: space-between; padding: 3px 0; }
        .pb-mono {
            font-family: monospace;
            background: rgba(0, 0, 0, 0.4);
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 13px;
            letter-spacing: .5px;
            margin-top: 6px;
            display: inline-block;
            border: 1px solid var(--border);
            color: var(--gold);
        }

        /* ── SUMMARY CARD ── */
        .sum-wrap { padding: 14px 16px 0; }
        .sum-card {
            background: var(--surface);
            border: 1px solid var(--border-gold);
            border-radius: 16px;
            overflow: hidden;
            animation: slideDown .3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }
        .sum-head {
            padding: 10px 16px;
            background: rgba(212, 175, 55, 0.08);
            border-bottom: 1px solid var(--border-gold);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--gold);
        }
        .sum-body { padding: 12px 16px; display: flex; flex-direction: column; gap: 8px; }
        .sum-row { display: flex; align-items: flex-start; gap: 8px; font-size: 13px; }
        .sum-lbl { color: var(--muted); width: 85px; flex-shrink: 0; font-size: 12px; }
        .sum-val { font-weight: 600; flex: 1; color: var(--txt); }

        /* ── ALERT ── */
        .alert {
            margin: 12px 16px 0;
            padding: 14px 16px;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid;
            display: flex; align-items: flex-start; gap: 10px;
            line-height: 1.4;
            animation: slideDown .3s ease;
        }
        .alert i { margin-top: 2px; flex-shrink: 0; font-size: 16px; }
        .alert.success { background: var(--succ-bg); color: var(--succ-tx); border-color: var(--succ-bd); }
        .alert.error   { background: var(--err-bg);  color: var(--err-tx);  border-color: var(--err-bd); }

        /* ── SECTION DIVIDER ── */
        .sec-div {
            height: 1px;
            background: var(--border);
            margin: 14px 16px;
        }

        /* ── VIP / INFO styles ── */
        .simple-card {
            margin: 16px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }
        .simple-card-head {
            padding: 14px 18px;
            background: rgba(255, 255, 255, 0.02);
            border-bottom: 1px solid var(--border);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
            color: var(--txt);
            display: flex; align-items: center; gap: 8px;
        }
        .simple-card-head i { color: var(--gold); }
        .simple-card-body { padding: 20px; }
        .info-item {
            display: flex; align-items: flex-start; gap: 14px;
            padding: 14px;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border);
            border-radius: 12px;
            margin-bottom: 12px;
        }
        .info-item:last-child { margin-bottom: 0; }
        .info-ic {
            width: 40px; height: 40px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .ic-gold   { background: var(--gold-lt); color: var(--gold); border: 1px solid var(--border-gold); }
        .ic-green  { background: rgba(34, 197, 94, 0.12); color: #4ade80; }
        .ic-blue   { background: rgba(59, 130, 246, 0.12); color: #60a5fa; }
        .ic-red    { background: rgba(239, 68, 68, 0.12); color: #f87171; }
        .info-ic + div h4 { font-size: 14px; font-weight: 700; margin-bottom: 4px; color: var(--txt); }
        .info-ic + div p  { font-size: 12px; color: var(--muted); line-height: 1.5; }
        .spinner {
            display: inline-block; width: 16px; height: 16px;
            border: 2px solid rgba(255,255,255,.2); border-top-color: var(--gold);
            border-radius: 50%; animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .btn-outline {
            width: 100%; padding: 14px;
            border: 1px solid var(--border); border-radius: 12px;
            background: var(--surface); color: var(--txt);
            font-size: 14px; font-weight: 700; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all .25s ease;
        }
        .btn-outline:hover { border-color: var(--gold); color: var(--gold); background: var(--surface-hover); }
        .btn-gold {
            width: 100%; padding: 14px;
            border: none; border-radius: 12px;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-dark) 100%); color: #000;
            font-size: 14px; font-weight: 800; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all .25s ease;
            box-shadow: 0 4px 15px var(--gold-glow);
        }
        .btn-gold:hover { transform: translateY(-2px); box-shadow: 0 6px 20px var(--gold-glow); }

        /* ── BOOK BAR ── */
        .book-bar {
            background: var(--card-bg);
            padding: 14px 18px 18px;
            border-top: 1px solid var(--border);
        }
        .book-btn {
            width: 100%;
            padding: 16px 20px;
            border: none;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--gold) 0%, var(--gold-dark) 100%);
            color: #000000;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 12px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px var(--gold-glow);
            position: relative;
            overflow: hidden;
        }
        .book-btn::before {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            transition: left 0.5s ease;
        }
        .book-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(212, 175, 55, 0.4); }
        .book-btn:hover::before { left: 100%; }
        .book-btn:active { transform: translateY(1px); }
        .book-badge {
            background: #000000;
            color: var(--gold);
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            display: none;
        }
    </style>
    <meta name="alcorte-base" content="<?php echo htmlspecialchars(project_base_url()); ?>">
    <script src="assets/api.js" defer></script>
</head>
<body>
<div class="page-card">

<!-- APP HEADER -->
<header class="app-header">
    <div class="hdr-top">
        <div class="hdr-logo"><i class="fas fa-cut"></i> AlCorte</div>
        <div class="hdr-sucursal"><?= htmlspecialchars($nombre_sucursal) ?></div>
    </div>
    <nav class="hdr-tabs">
        <a href="?t=<?= htmlspecialchars($token) ?>&tab=reservar" class="hdr-tab <?= $tab==='reservar'?'act':'' ?>">
            <i class="fas fa-calendar-check"></i>Reservar
        </a>
        <?php if ($has_productos): ?>
        <a href="?t=<?= htmlspecialchars($token) ?>&tab=productos" class="hdr-tab <?= $tab==='productos'?'act':'' ?>">
            <i class="fas fa-box-open"></i>Productos
        </a>
        <?php endif; ?>
        <a href="?t=<?= htmlspecialchars($token) ?>&tab=citas" class="hdr-tab <?= $tab==='citas'?'act':'' ?>">
            <i class="fas fa-list"></i>Mis Citas
        </a>
    </nav>
</header>

<?php if ($tab === 'reservar'): ?>


<div class="main">

    <?php if (!empty($msg)): ?>
    <div class="alert <?= $msg_type ?>">
        <i class="fas fa-<?= $msg_type === 'success' ? 'circle-check' : 'circle-exclamation' ?>"></i>
        <span><?= htmlspecialchars($msg) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" action="?t=<?= htmlspecialchars($token) ?>&tab=reservar" id="formReserva">
        <input type="hidden" name="csrf_token"  value="<?= csrf_generate() ?>">
        <input type="hidden" name="action"       value="agendar">
        <input type="hidden" name="servicio"     id="srv_val">
        <input type="hidden" name="barbero_id"   id="brb_val">
        <input type="hidden" name="fecha"        id="fec_val">
        <input type="hidden" name="hora"         id="hor_val">

        <!-- ═══ PASO 1: SERVICIOS ═══ -->
        <div class="sec-title"><span class="step-num">1</span> Servicio</div>
        <div class="svc-list" id="svcList">
            <?php if (empty($servicios_list)): ?>
            <p style="padding:0 4px 8px;color:var(--muted);font-size:13px">Sin servicios disponibles.</p>
            <?php endif; ?>
            <?php foreach ($servicios_list as $sv): ?>
            <?php $p = $sv['precio'] ?? ''; if ($p !== '' && $p[0] !== '$') $p = '$'.$p; ?>
            <div class="svc-card"
                 onclick="selSvc(this,'<?= htmlspecialchars($sv['nombre'],ENT_QUOTES) ?>','<?= htmlspecialchars($p,ENT_QUOTES) ?>')"
                 data-q="<?= strtolower(htmlspecialchars($sv['nombre'])) ?>">
                <?php if (!empty(trim($sv['descripcion'] ?? ''))): ?>
                    <span class="svc-info-btn" onclick="event.stopPropagation()"><i class="fas fa-info"></i></span>
                    <span class="svc-tooltip"><?= htmlspecialchars($sv['descripcion']) ?></span>
                <?php endif; ?>
                <?php if (!empty($sv['imagen_url'])): ?>
                    <img class="svc-photo"
                         src="<?= htmlspecialchars($sv['imagen_url']) ?>"
                         alt="<?= htmlspecialchars($sv['nombre']) ?>"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="svc-icon" style="display:none"><i class="<?= htmlspecialchars($sv['icono']??'fas fa-cut') ?>"></i></div>
                <?php else: ?>
                    <div class="svc-icon"><i class="<?= htmlspecialchars($sv['icono']??'fas fa-cut') ?>"></i></div>
                <?php endif; ?>
                <div class="svc-name"><?= htmlspecialchars($sv['nombre']) ?></div>
                <div class="svc-meta"><?= htmlspecialchars($p) ?></div>
                <div class="svc-bs" data-usd="<?= htmlspecialchars(preg_replace('/[^0-9.]/', '', $p)) ?>"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($bcv_tasa): ?>
        <div class="bcv-badge">
            <i class="fas fa-circle" style="color:#22c55e"></i>
            BCV Bs.&nbsp;<?= number_format($bcv_tasa, 2, ',', '.') ?>&nbsp;/&nbsp;USD
            <?php if ($bcv_at): ?><span style="opacity:.6">· <?= htmlspecialchars($bcv_at) ?></span><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="sec-div"></div>

        <!-- ═══ PASO 2: FECHA Y HORA ═══ -->
        <div class="sec-title"><span class="step-num">2</span> Fecha y hora</div>

        <div class="cal-wrap">
            <div class="cal-head">
                <strong id="calLabel"></strong>
            </div>
            <div class="week-row">
                <button type="button" class="cal-nav" onclick="shiftWeek(-1)"><i class="fas fa-chevron-left"></i></button>
                <div class="week-strip" id="weekStrip"></div>
                <button type="button" class="cal-nav" onclick="shiftWeek(1)"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>

        <div id="dateTitle" class="date-title" style="display:none"></div>
        <div class="time-groups" id="timeGroups"></div>

        <div class="sec-div"></div>

        <!-- ═══ PASO 3: ESPECIALISTA ═══ -->
        <div class="sec-title"><span class="step-num">3</span> Especialista</div>

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
        <div class="sec-title"><span class="step-num">4</span> Datos y pago</div>

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
                    <?php if (($config['estado_binance']??'0')==='1'): ?>
                        <option value="Binance">Binance Pay (USDT)</option>
                    <?php endif; ?>
                    <?php if (($config['estado_paypal']??'0')==='1'): ?>
                        <option value="PayPal">PayPal ($)</option>
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
        <div class="pay-box gold" id="info_binance">
            <div class="pb-title"><i class="fas fa-coins" style="color:#f3ba2f;"></i> Pago Binance Pay</div>
            <div class="pb-mono"><?= htmlspecialchars($config['binance_pay_id']??'—') ?></div>
        </div>
        <div class="pay-box paypal" id="info_paypal">
            <div class="pb-title"><i class="fab fa-paypal" style="color:#003087;"></i> Cuenta PayPal</div>
            <div class="pb-mono"><?= htmlspecialchars($config['paypal_email']??'—') ?></div>
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


<script>
// ── BCV ──
const BCV_TASA = <?= $bcv_tasa ? json_encode((float)$bcv_tasa) : 'null' ?>;
function fmtBs(usdStr) {
    if (!BCV_TASA || !usdStr) return '';
    const n = parseFloat(usdStr);
    if (!n || isNaN(n)) return '';
    return 'Bs. ' + new Intl.NumberFormat('es-VE', {maximumFractionDigits:0}).format(n * BCV_TASA);
}

// ── STATE ──
let ST = { svc:'', precio:'', bid:0, bnom:'', fecha:'', hora:'' };
const STORE_TOKEN = <?= json_encode($token) ?>;
const MN  = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
const MNA = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
const DNA = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
const DNS = ['Do','Lu','Ma','Mi','Ju','Vi','Sá'];
const SLOTS = ['09:00','09:30','10:00','10:30','11:00','11:30','12:30','13:00','13:30','14:00','14:30','15:00','15:30','16:00','16:30','17:00'];

// ── TIMEZONE (Venezuela America/Caracas) ──
function getVETime() {
    const now = new Date();
    try {
        const formatter = new Intl.DateTimeFormat('en-US', {
            timeZone: 'America/Caracas',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        });
        const parts = formatter.formatToParts(now);
        const p = {};
        parts.forEach(item => { p[item.type] = item.value; });
        let hour = parseInt(p.hour, 10);
        if (hour === 24) hour = 0;
        return new Date(parseInt(p.year, 10), parseInt(p.month, 10) - 1, parseInt(p.day, 10), hour, parseInt(p.minute, 10), parseInt(p.second, 10));
    } catch(e) {
        const utcMs = now.getTime() + (now.getTimezoneOffset() * 60000);
        return new Date(utcMs - (4 * 3600000));
    }
}

function getWeekStart(d) {
    const dt = new Date(d); dt.setHours(0,0,0,0);
    dt.setDate(dt.getDate() - dt.getDay());
    return dt;
}
let calWeekStart = getWeekStart(getVETime());

// Poblar precios Bs. en las cards al cargar
document.querySelectorAll('.svc-bs[data-usd]').forEach(el => {
    const bs = fmtBs(el.dataset.usd);
    if (bs) el.textContent = bs;
});

// ── SERVICE ──
function selSvc(card, name, precio) {
    document.querySelectorAll('.svc-card').forEach(c => c.classList.remove('sel'));
    card.classList.add('sel');
    ST.svc = name; ST.precio = precio;
    document.getElementById('srv_val').value = name;
    syncSummary(); updateBadge();
}
function filterSvc(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.svc-card').forEach(c =>
        c.style.display = c.dataset.q.includes(q) ? '' : 'none');
}

// ── CALENDAR (week strip) ──
function renderWeek() {
    const now = getVETime(); now.setHours(0,0,0,0);
    const mid = new Date(calWeekStart); mid.setDate(mid.getDate() + 3);
    document.getElementById('calLabel').textContent = MN[mid.getMonth()] + ' ' + mid.getFullYear();
    const strip = document.getElementById('weekStrip');
    strip.innerHTML = '';
    for (let i = 0; i < 7; i++) {
        const dt = new Date(calWeekStart); dt.setDate(dt.getDate() + i);
        const ds = fmtDate(dt);
        const el = document.createElement('div');
        el.className = 'week-day';
        if (dt < now) el.classList.add('dis');
        if (ds === fmtDate(now)) el.classList.add('today');
        if (ds === ST.fecha) el.classList.add('sel');
        el.innerHTML = `<span class="week-day-label">${DNS[dt.getDay()]}</span><span class="week-day-num">${dt.getDate()}</span>`;
        el.onclick = () => pickDate(ds);
        strip.appendChild(el);
    }
}
function shiftWeek(dir) {
    calWeekStart.setDate(calWeekStart.getDate() + dir * 7);
    const floor = getWeekStart(getVETime());
    if (calWeekStart < floor) calWeekStart = floor;
    renderWeek();
}
function pickDate(ds) {
    ST.fecha = ds; ST.hora = '';
    document.getElementById('fec_val').value = ds;
    document.getElementById('hor_val').value = '';
    renderWeek(); fetchBloqueos(); renderTimeGroups(); syncSummary();
}

// ── TIME SLOTS (grouped) ──
function fmtSlot(s) {
    const [h,m] = s.split(':');
    const hr = parseInt(h);
    const h12 = hr > 12 ? hr - 12 : (hr === 0 ? 12 : hr);
    return h12 + ':' + m + ' ' + (hr >= 12 ? 'p.m.' : 'a.m.');
}
function renderTimeGroups() {
    const container = document.getElementById('timeGroups');
    const titleEl   = document.getElementById('dateTitle');
    if (!ST.fecha) { container.innerHTML = ''; titleEl.style.display = 'none'; return; }
    const [y,mo,d] = ST.fecha.split('-').map(Number);
    const dt = new Date(y, mo - 1, d, 12, 0, 0);
    titleEl.textContent = DNA[dt.getDay()] + ', ' + d + ' de ' + MN[mo-1];
    titleEl.style.display = 'block';
    const now = getVETime();
    const todayStr = fmtDate(now);
    const groups = [
        { label: 'Mañana', slots: SLOTS.filter(s => parseInt(s) < 12) },
        { label: 'Tarde',  slots: SLOTS.filter(s => { const h=parseInt(s); return h>=12 && h<17; }) },
        { label: 'Noche',  slots: SLOTS.filter(s => parseInt(s) >= 17) },
    ];
    container.innerHTML = groups.filter(g => g.slots.length).map(g => {
        const chips = g.slots.map(s => {
            const [h,m] = s.split(':');
            let dis = '';
            if (ST.fecha === todayStr) {
                const st = getVETime(); st.setHours(parseInt(h), parseInt(m), 0, 0);
                if (st <= now) dis = ' t-dis';
            }
            if (isSlotBlocked(s)) dis = ' t-dis';
            return `<div class="t-slot${s===ST.hora?' sel':''}${dis}" onclick="pickSlot(this,'${s}')">${fmtSlot(s)}</div>`;
        }).join('');
        return `<div><div class="tg-label">${g.label}</div><div class="tg-slots">${chips}</div></div>`;
    }).join('');
}
function pickSlot(el, s) {
    document.querySelectorAll('.t-slot').forEach(b => b.classList.remove('sel'));
    el.classList.add('sel');
    ST.hora = s; document.getElementById('hor_val').value = s;
    syncSummary();}

// ── SPECIALIST ──
let bloqueos_cache = [];
function selSpec(card, id, nom) {
    document.querySelectorAll('.spec-card').forEach(c => c.classList.remove('sel'));
    card.classList.add('sel');
    ST.bid = id; ST.bnom = nom;
    document.getElementById('brb_val').value = id;
    fetchBloqueos();
    syncSummary();
}

function fetchBloqueos() {
    if (!ST.bid || !ST.fecha) { bloqueos_cache = []; return; }
    fetch(`../api/v1/bloqueos?barbero_id=${ST.bid}&fecha=${ST.fecha}&t=${encodeURIComponent(STORE_TOKEN)}`)
        .then(r => r.json())
        .then(data => { bloqueos_cache = (data.data && data.data.bloqueos) || data.bloqueos || []; renderTimeGroups(); })
        .catch(() => { bloqueos_cache = []; });
}

function isSlotBlocked(slot) {
    for (const b of bloqueos_cache) {
        if (b.dia_completo == 1) return true;
        if (b.hora_inicio && b.hora_fin) {
            const s = slot + ':00';
            if (s >= b.hora_inicio && s < b.hora_fin) return true;
        }
    }
    return false;
}

// ── SUMMARY ──
function syncSummary() {
    const wrap = document.getElementById('sumWrap');
    const body = document.getElementById('sumBody');
    if (!ST.svc && !ST.fecha && !ST.bid) { wrap.style.display='none'; return; }
    wrap.style.display = 'block';
    let html = '';
    if (ST.svc) {
        const pUsd = ST.precio ? (ST.precio[0]==='$' ? ST.precio : '$'+ST.precio) : '';
        const usdNum = pUsd.replace(/[^0-9.]/g,'');
        const pBs  = fmtBs(usdNum);
        const pStr = pUsd ? ` <span style="color:var(--gold);font-size:11px">(${pUsd}${pBs ? ' · '+pBs : ''})</span>` : '';
        html += row('Servicio', ST.svc + pStr);
    }
    if (ST.fecha) {
        const [y,mo,d] = ST.fecha.split('-').map(Number);
        const dt = new Date(y, mo - 1, d, 12, 0, 0);
        html += row('Fecha', DNA[dt.getDay()]+' '+d+' de '+MNA[mo-1]);
    }
    if (ST.hora) html += row('Hora', fmtSlot(ST.hora));
    if (ST.bnom)  html += row('Especialista', ST.bnom);
    body.innerHTML = html;
}
function row(lbl,val) { return `<div class="sum-row"><span class="sum-lbl">${lbl}</span><span class="sum-val">${val}</span></div>`; }

function updateBadge() {
    const b = document.getElementById('bookBadge');
    if (ST.precio) {
        const usd = ST.precio[0]==='$' ? ST.precio : '$'+ST.precio;
        const bs  = fmtBs(ST.precio.replace(/[^0-9.]/g,''));
        b.textContent = bs ? usd + ' · ' + bs : usd;
        b.style.display = 'inline-block';
    } else b.style.display = 'none';
}


// ── PAYMENT ──
function onPago() {
    const v = document.getElementById('metodo_pago').value;
    document.getElementById('info_pm').style.display      = v==='Pago Móvil' ? 'block':'none';
    document.getElementById('info_zelle').style.display   = v==='Zelle'      ? 'block':'none';
    document.getElementById('info_binance').style.display = v==='Binance'    ? 'block':'none';
    document.getElementById('info_paypal').style.display  = v==='PayPal'     ? 'block':'none';
    const rw = document.getElementById('refWrap');
    const ri = document.getElementById('referencia_pago');
    if (v==='Pago Móvil'||v==='Zelle'||v==='Binance'||v==='PayPal') { rw.style.display='block'; ri.required=true; }
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
    const fd = new FormData(form);
    const payload = Object.fromEntries(fd.entries());
    payload.t = STORE_TOKEN;
    if (!window.AlCorte) { form.submit(); return; }
    AlCorte.postJson('public/agendar', payload)
        .then(function (data) {
            alert(data.message || 'Reserva registrada');
            window.location.href = window.location.pathname + '?t=' + encodeURIComponent(STORE_TOKEN) + '&tab=reservar';
        })
        .catch(function (err) { alert(err.message || 'Error al reservar'); });
}

// ── UTILS ──
function pad(n) { return String(n).padStart(2,'0'); }
function fmtDate(d) { return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate()); }

// ── INIT ──
renderWeek();
</script>

<?php elseif ($tab === 'productos' && $has_productos):
    $prods = [];
    $rp = $conn->prepare("SELECT * FROM productos WHERE activo = 1 AND sucursal_id = ? ORDER BY nombre ASC");
    $rp->bind_param("i", $sucursal_id); $rp->execute();
    $rp = $rp->get_result();
    if ($rp) while ($pr = $rp->fetch_assoc()) $prods[] = $pr;
?>

<div class="main">
    <div class="sec-title" style="padding-top:16px"><i class="fas fa-box-open" style="color:var(--gold)"></i> Productos disponibles</div>

    <?php if (empty($prods)): ?>
    <div style="text-align:center;padding:48px 16px;color:var(--muted)">
        <i class="fas fa-box" style="font-size:32px;margin-bottom:12px;display:block;opacity:.4"></i>
        <p style="font-weight:600">No hay productos disponibles por ahora.</p>
    </div>
    <?php else: ?>
    <div style="padding:0 16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;padding-bottom:20px">
        <?php foreach ($prods as $pr): ?>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;overflow:hidden;display:flex;flex-direction:column">
            <div style="aspect-ratio:1/1;background:rgba(255,255,255,0.03);position:relative;overflow:hidden">
                <?php if (!empty($pr['imagen_url'])): ?>
                    <img src="<?= htmlspecialchars($pr['imagen_url']) ?>" style="width:100%;height:100%;object-fit:cover">
                <?php else: ?>
                    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center">
                        <i class="fas fa-box" style="font-size:28px;color:var(--gold);opacity:.4"></i>
                    </div>
                <?php endif; ?>
                <?php if ($pr['stock'] <= 0): ?>
                <div style="position:absolute;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center">
                    <span style="font-size:11px;font-weight:800;color:white;background:rgba(220,38,38,.9);padding:4px 10px;border-radius:2rem">Agotado</span>
                </div>
                <?php endif; ?>
            </div>
            <div style="padding:10px 12px;flex:1;display:flex;flex-direction:column;gap:3px">
                <div style="font-size:13px;font-weight:700;color:var(--txt);line-height:1.2"><?= htmlspecialchars($pr['nombre']) ?></div>
                <?php if ($pr['descripcion']): ?>
                <div style="font-size:11px;color:var(--muted);line-height:1.3"><?= htmlspecialchars($pr['descripcion']) ?></div>
                <?php endif; ?>
                <div style="margin-top:auto;padding-top:6px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px">
                    <span style="font-size:15px;font-weight:800;color:var(--gold)">$<?= number_format($pr['precio'],2) ?></span>
                    <?php if ($pr['stock'] > 0): ?>
                    <button type="button" onclick="addToCart(<?= $pr['id'] ?>, '<?= htmlspecialchars($pr['nombre'], ENT_QUOTES) ?>', <?= $pr['precio'] ?>, <?= $pr['stock'] ?>)" style="background:var(--gold); color:#000; border:none; padding:4px 8px; border-radius:6px; font-size:10px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:4px;"><i class="fas fa-plus"></i> Añadir</button>
                    <?php else: ?>
                    <span style="font-size:10px;color:#ef4444;font-weight:600">Agotado</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- FLOATING CART BUTTON -->
<div id="cart-floating-btn" onclick="toggleCartModal(true)" style="position:fixed; bottom:20px; right:20px; width:56px; height:56px; background:var(--gold); color:#000; border-radius:50%; display:none; align-items:center; justify-content:center; box-shadow:0 8px 24px rgba(0,0,0,0.3); cursor:pointer; z-index:99; transition:all 0.3s ease;">
    <i class="fas fa-shopping-bag" style="font-size:20px;"></i>
    <span id="cart-floating-badge" style="position:absolute; top:-2px; right:-2px; background:#ef4444; color:white; font-size:10px; font-weight:800; padding:2px 6px; border-radius:50%; border:2px solid var(--gold);">0</span>
</div>

<!-- CART MODAL -->
<div id="cartModal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.6);backdrop-filter:blur(3px);align-items:center;justify-content:center">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:1rem;padding:16px;width:92%;max-width:400px;margin:12px;box-shadow:0 20px 60px rgba(0,0,0,.4);position:relative;max-height:92vh;overflow-y:auto;display:flex;flex-direction:column;gap:12px;">
        <div style="display:flex;align-items:center;justify-content:space-between;">
            <h3 style="font-size:15px;font-weight:800;color:var(--txt);display:flex;align-items:center;gap:6px;"><i class="fas fa-shopping-cart" style="color:var(--gold)"></i> Mi Carrito</h3>
            <button onclick="toggleCartModal(false)" style="background:none;border:none;cursor:pointer;color:var(--muted);font-size:18px;line-height:1;padding:4px"><i class="fas fa-times"></i></button>
        </div>
        
        <!-- Cart Items List -->
        <div id="cart-items-list" style="display:flex; flex-direction:column; gap:8px; max-height:160px; overflow-y:auto; padding-right:4px;">
            <!-- Rendered by JS -->
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid var(--border); padding-top:10px;">
            <span style="font-weight:700; color:var(--txt); font-size:13px;">Total:</span>
            <span id="cart-total-amount" style="font-weight:800; color:var(--gold); font-size:16px;">$0.00</span>
        </div>

        <!-- Checkout Form -->
        <form method="POST" id="formCheckoutPedido" onsubmit="return submitPedido(event)" style="border-top:1px solid var(--border); padding-top:10px; display:flex; flex-direction:column; gap:8px;">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
            <input type="hidden" name="action" value="crear_pedido">
            <input type="hidden" name="cart_items" id="checkout-cart-items">

            <div class="fld" style="margin-bottom:0;">
                <label style="font-size:10px; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:var(--muted);">Nombre y Apellido</label>
                <input type="text" name="cliente_nombre" required placeholder="Ej. Juan Pérez" style="width:100%; box-sizing:border-box; padding:8px 10px; font-size:12px; border-radius:8px; height:34px; border:1px solid var(--border); background:var(--bg); color:var(--txt);">
            </div>
            
            <div class="fld" style="margin-bottom:0;">
                <label style="font-size:10px; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:var(--muted);">Número de Teléfono</label>
                <input type="tel" name="cliente_telefono" required placeholder="Ej. 04121234567" style="width:100%; box-sizing:border-box; padding:8px 10px; font-size:12px; border-radius:8px; height:34px; border:1px solid var(--border); background:var(--bg); color:var(--txt);">
            </div>

            <!-- PAYMENT OPTIONS -->
            <div class="fld" style="margin-bottom:0;">
                <label style="font-size:10px; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:var(--muted);">Método de Pago</label>
                <select name="metodo_pago" id="ped-metodo" onchange="showPedidoPaymentDetails(this.value)" style="width:100%; box-sizing:border-box; background:var(--bg); color:var(--txt); border:1px solid var(--border); padding:6px 10px; border-radius:8px; font-size:12px; cursor:pointer; height:34px;" required>
                    <option value="" disabled selected>Selecciona método</option>
                    <?php if (($config['estado_pago_movil'] ?? '0') === '1'): ?>
                        <option value="Pago Móvil">Pago Móvil</option>
                    <?php endif; ?>
                    <?php if (($config['estado_zelle'] ?? '0') === '1'): ?>
                        <option value="Zelle">Zelle</option>
                    <?php endif; ?>
                    <?php if (($config['estado_binance'] ?? '0') === '1'): ?>
                        <option value="Binance Pay">Binance Pay</option>
                    <?php endif; ?>
                    <?php if (($config['estado_paypal'] ?? '0') === '1'): ?>
                        <option value="PayPal">PayPal</option>
                    <?php endif; ?>
                    <?php if (($config['estado_efectivo'] ?? '0') === '1'): ?>
                        <option value="Efectivo">Efectivo (En local)</option>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Payment instructions boxes -->
            <div id="ped-pay-details" style="display:none; font-size:10px; padding:8px; border-radius:6px; background:rgba(255,255,255,0.02); border:1px dashed var(--border); color:var(--muted); line-height:1.3;">
                <!-- Filled dynamically by JS -->
            </div>

            <div class="fld" id="ped-ref-wrap" style="display:none; margin-bottom:0;">
                <label style="font-size:10px; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:var(--muted);">Código de Referencia / Captura</label>
                <input type="text" name="referencia_pago" id="ped-referencia" placeholder="Ej. 12345678" style="width:100%; box-sizing:border-box; padding:8px 10px; font-size:12px; border-radius:8px; height:34px; border:1px solid var(--border); background:var(--bg); color:var(--txt);">
            </div>

            <button type="submit" style="background:var(--gold); color:#000; border:none; padding:8px; border-radius:8px; font-weight:700; width:100%; font-size:12px; cursor:pointer; margin-top:2px; height:36px; display:flex; align-items:center; justify-content:center; gap:6px;">
                <i class="fas fa-check-circle"></i> Confirmar Pedido
            </button>
        </form>
    </div>
</div>

<script>
const SUC_ID = <?= $sucursal_id ?>;
const STORE_TOKEN = <?= json_encode($token) ?>;
const CART_KEY = 'alcorte_cart_' + SUC_ID;

// Payment instructions configs
const PAY_INFO = {
    'Pago Móvil': '<strong>Pago Móvil:</strong><br>Banco: <?= htmlspecialchars($config['banco_nombre'] ?? '') ?><br>Teléfono: <?= htmlspecialchars($config['banco_telefono'] ?? '') ?><br>Cédula/RIF: <?= htmlspecialchars($config['banco_ci'] ?? '') ?>',
    'Zelle': '<strong>Zelle:</strong><br>Email: <?= htmlspecialchars($config['zelle_email'] ?? '') ?>',
    'Binance Pay': '<strong>Binance Pay:</strong><br>Dirección: <?= htmlspecialchars($config['binance_pay_id'] ?? '') ?>',
    'PayPal': '<strong>PayPal:</strong><br>Email: <?= htmlspecialchars($config['paypal_email'] ?? '') ?>',
    'Efectivo': 'Pagarás en efectivo al retirar tu pedido en la tienda.'
};

let cart = JSON.parse(localStorage.getItem(CART_KEY)) || [];

function saveCart() {
    localStorage.setItem(CART_KEY, JSON.stringify(cart));
    updateCartUI();
}

function addToCart(id, nombre, precio, maxStock) {
    let item = cart.find(x => x.id === id);
    if (item) {
        if (item.qty >= maxStock) {
            alert('Lo sentimos, no contamos con más stock disponible para este producto.');
            return;
        }
        item.qty++;
    } else {
        cart.push({ id, nombre, precio, qty: 1, maxStock });
    }
    saveCart();
}

function updateQty(id, qty) {
    let item = cart.find(x => x.id === id);
    if (!item) return;
    qty = parseInt(qty);
    if (qty <= 0) {
        cart = cart.filter(x => x.id !== id);
    } else if (qty > item.maxStock) {
        alert('Stock máximo alcanzado.');
        item.qty = item.maxStock;
    } else {
        item.qty = qty;
    }
    saveCart();
}

function updateCartUI() {
    const badge = document.getElementById('cart-floating-badge');
    const floatBtn = document.getElementById('cart-floating-btn');
    const list = document.getElementById('cart-items-list');
    const totalAmount = document.getElementById('cart-total-amount');
    
    if (!badge || !floatBtn) return;
    
    const totalItems = cart.reduce((sum, x) => sum + x.qty, 0);
    const totalPrice = cart.reduce((sum, x) => sum + (x.precio * x.qty), 0);
    
    badge.textContent = totalItems;
    floatBtn.style.display = totalItems > 0 ? 'flex' : 'none';
    
    if (totalAmount) totalAmount.textContent = '$' + totalPrice.toFixed(2);
    
    if (list) {
        if (cart.length === 0) {
            list.innerHTML = '<div style="text-align:center;color:var(--muted);padding:16px;font-size:12px;">El carrito está vacío.</div>';
        } else {
            list.innerHTML = cart.map(item => `
                <div style="display:flex; justify-content:space-between; align-items:center; background:rgba(255,255,255,0.02); padding:8px 10px; border-radius:8px; border:1px solid var(--border);">
                    <div style="flex:1; min-width:0; padding-right:8px;">
                        <div style="font-size:12px; font-weight:700; color:var(--txt); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHTML(item.nombre)}</div>
                        <div style="font-size:11px; color:var(--gold); font-weight:800; margin-top:2px;">$${item.precio.toFixed(2)}</div>
                    </div>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <button type="button" onclick="updateQty(${item.id}, ${item.qty - 1})" style="width:24px; height:24px; border:1px solid var(--border); background:transparent; color:var(--txt); border-radius:4px; font-size:10px; cursor:pointer;">-</button>
                        <span style="font-size:12px; font-weight:700; width:16px; text-align:center; color:var(--txt);">${item.qty}</span>
                        <button type="button" onclick="updateQty(${item.id}, ${item.qty + 1})" style="width:24px; height:24px; border:1px solid var(--border); background:transparent; color:var(--txt); border-radius:4px; font-size:10px; cursor:pointer;">+</button>
                    </div>
                </div>
            `).join('');
        }
    }
}

function escapeHTML(str) {
    return str.replace(/[&<>'"]/g, 
        tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
    );
}

function toggleCartModal(show) {
    const m = document.getElementById('cartModal');
    if (m) m.style.display = show ? 'flex' : 'none';
}

function showPedidoPaymentDetails(val) {
    const details = document.getElementById('ped-pay-details');
    const refWrap = document.getElementById('ped-ref-wrap');
    const refInput = document.getElementById('ped-referencia');
    
    if (!details || !refWrap || !refInput) return;
    
    if (PAY_INFO[val]) {
        details.innerHTML = PAY_INFO[val];
        details.style.display = 'block';
        if (val === 'Efectivo') {
            refWrap.style.display = 'none';
            refInput.removeAttribute('required');
        } else {
            refWrap.style.display = 'block';
            refInput.setAttribute('required', 'true');
        }
    } else {
        details.style.display = 'none';
        refWrap.style.display = 'none';
        refInput.removeAttribute('required');
    }
}

function submitPedido(e) {
    e.preventDefault();
    if (cart.length === 0) {
        alert('El carrito está vacío.');
        return false;
    }
    const form = document.getElementById('formCheckoutPedido');
    const fd = new FormData(form);
    fd.set('cart_items', JSON.stringify(cart));
    fd.set('action', 'crear_pedido');
    fd.set('t', STORE_TOKEN);
    if (!window.AlCorte) return true;
    AlCorte.postForm('public/pedido', fd)
        .then(function (data) {
            alert(data.message || 'Pedido registrado');
            if (data.data && data.data.clear_cart) {
                localStorage.removeItem('alcorte_cart_' + SUC_ID);
                cart = [];
                updateCartUI();
            }
            toggleCartModal(false);
        })
        .catch(function (err) { alert(err.message || 'Error al procesar el pedido'); });
    return false;
}

window.renderCart = updateCartUI;
document.addEventListener('DOMContentLoaded', updateCartUI);
</script>

<?php elseif ($tab === 'citas'): ?>

<div class="main">

    <div class="simple-card" style="margin-top:12px">
        <div class="simple-card-head"><i class="fas fa-calendar-alt" style="color:var(--gold)"></i> Consultar mis citas</div>
        <div class="simple-card-body">
            <p style="font-size:12px;color:var(--muted);margin-bottom:12px;line-height:1.5">Ingresa el número de teléfono con el que reservaste para ver tus citas.</p>
            <div class="fld" style="margin-bottom:10px">
                <label>Tu número de teléfono</label>
                <input type="tel" id="citasPhone" placeholder="Ej. 04121234567" style="text-align:center;letter-spacing:1px">
            </div>
            <button class="btn-outline" onclick="buscarCitas()" id="btnBuscar"><i class="fas fa-search"></i> Buscar mis citas</button>
        </div>
    </div>

    <div id="citasResult"></div>

</div>

<script>
const SUC_ID = <?= json_encode($sucursal_id) ?>;
const STORE_TOKEN = <?= json_encode($token) ?>;
const MN_C = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

function fmtFecha(f) {
    const [y,m,d] = f.split('-');
    return parseInt(d)+' '+MN_C[parseInt(m)-1]+' '+y;
}
function fmtHora(h) {
    const [hr,mn] = h.split(':');
    const n = parseInt(hr);
    return (n>12?n-12:(n||12))+':'+mn+' '+(n>=12?'p.m.':'a.m.');
}
function estadoBadge(estado, estado_pago) {
    const map = {
        'pendiente':  ['Pendiente','#f59e0b','#fffbeb'],
        'confirmada': ['Confirmada','#22c55e','#ecfdf5'],
        'completada': ['Completada','#2563eb','#eff6ff'],
        'cancelada':  ['Cancelada','#ef4444','#fef2f2'],
    };
    const [txt,color,bg] = map[estado] || ['—','#94a3b8','#f8fafc'];
    let html = `<span style="font-size:10px;font-weight:700;padding:3px 8px;border-radius:99px;background:${bg};color:${color}">${txt}</span>`;
    if (estado !== 'cancelada' && estado_pago === 'pendiente') {
        html += ` <span style="font-size:10px;font-weight:600;padding:3px 8px;border-radius:99px;background:#fef3c7;color:#92400e">Pago pendiente</span>`;
    }
    return html;
}

function buscarCitas() {
    const tel = document.getElementById('citasPhone').value.trim().replace(/\D/g,'');
    const box = document.getElementById('citasResult');
    if (!tel || tel.length < 7) { alert('Ingresa un número de teléfono válido.'); return; }

    const btn = document.getElementById('btnBuscar');
    btn.innerHTML = '<span class="spinner"></span> Buscando...';
    btn.disabled = true;

    fetch(`../api/v1/mis_citas?telefono=${encodeURIComponent(tel)}&t=${encodeURIComponent(STORE_TOKEN)}`)
        .then(r => r.text())
        .then(text => {
            let data;
            try {
                data = JSON.parse(text);
            } catch(e) {
                console.error("Error parseando JSON. La respuesta fue:", text);
                throw e;
            }

            btn.innerHTML = '<i class="fas fa-search"></i> Buscar mis citas';
            btn.disabled = false;

            const payload = data.data || data;
            const hasCitas = payload.citas && Array.isArray(payload.citas) && payload.citas.length > 0;
            const hasPedidos = payload.pedidos && Array.isArray(payload.pedidos) && payload.pedidos.length > 0;

            if (!hasCitas && !hasPedidos) {
                box.innerHTML = `
                    <div class="simple-card">
                        <div class="simple-card-body" style="text-align:center;padding:32px 16px">
                            <i class="fas fa-search" style="font-size:28px;color:var(--faint);margin-bottom:10px;display:block"></i>
                            <p style="font-weight:700;color:var(--txt);margin-bottom:4px">No se encontraron registros</p>
                            <p style="font-size:12px;color:var(--muted)">No hay citas ni pedidos registrados con este número.</p>
                        </div>
                    </div>`;
                return;
            }

            let html = '';

            // Próximas Citas
            if (hasCitas) {
                const hoy = getVETime(); hoy.setHours(0,0,0,0);
                const prox = [], pasadas = [];
                payload.citas.forEach(c => {
                    const [cy, cm, cd] = c.fecha.split('-').map(Number);
                    const fc = new Date(cy, cm - 1, cd, 0, 0, 0);
                    if (fc >= hoy && c.estado !== 'cancelada' && c.estado !== 'completada') prox.push(c);
                    else pasadas.push(c);
                });

                if (prox.length) {
                    html += `<div class="simple-card">
                        <div class="simple-card-head"><i class="fas fa-clock" style="color:#2563eb"></i> Próximas citas</div>
                        <div class="simple-card-body" style="padding:12px">`;
                    prox.forEach(c => { html += citaCard(c); });
                    html += `</div></div>`;
                }

                if (pasadas.length) {
                    html += `<div class="simple-card">
                        <div class="simple-card-head"><i class="fas fa-history" style="color:var(--faint)"></i> Historial de citas</div>
                        <div class="simple-card-body" style="padding:12px">`;
                    pasadas.forEach(c => { html += citaCard(c); });
                    html += `</div></div>`;
                }
            }

            // Pedidos de Tienda
            if (hasPedidos) {
                html += `<div class="simple-card" style="margin-top:14px;">
                    <div class="simple-card-head"><i class="fas fa-shopping-bag" style="color:var(--gold)"></i> Mis Pedidos / Compras</div>
                    <div class="simple-card-body" style="padding:12px">`;
                payload.pedidos.forEach(p => { html += pedidoCard(p); });
                html += `</div></div>`;
            }

            box.innerHTML = html;
        })
        .catch(() => {
            btn.innerHTML = '<i class="fas fa-search"></i> Buscar mis citas';
            btn.disabled = false;
            box.innerHTML = `<div class="alert error" style="margin:12px 0"><i class="fas fa-circle-exclamation"></i><span>Error de conexión. Intenta de nuevo.</span></div>`;
        });
}

function pedidoCard(p) {
    const articulosHtml = p.articulos.map(art => `
        <div style="font-size:11px;color:var(--txt);">• ${escapeHTML(art.nombre_producto)} (x${art.cantidad}) - $${(art.cantidad * art.precio_unitario).toFixed(2)}</div>
    `).join('');

    const pagoBadge = p.estado_pago === 'pendiente' ? 
        `<span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:99px;background:#fef3c7;color:#d97706;margin-left:4px;display:inline-block;">Pago Pendiente</span>` :
        `<span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:99px;background:#dcfce7;color:#166534;margin-left:4px;display:inline-block;">Pago Verificado</span>`;

    const statusMap = {
        'pendiente': ['Pendiente', '#f59e0b', '#fffbeb'],
        'completado': ['Entregado', '#22c55e', '#ecfdf5'],
        'cancelado': ['Cancelado', '#ef4444', '#fef2f2']
    };
    const [statusTxt, statusColor, statusBg] = statusMap[p.estado] || ['Pendiente', '#f59e0b', '#fffbeb'];
    const estadoBadgeHtml = `<span style="font-size:10px;font-weight:700;padding:2px 6px;border-radius:99px;background:${statusBg};color:${statusColor};display:inline-block;">${statusTxt}</span>`;

    const fechaDT = new Date(p.fecha.replace(/-/g, '/'));
    const fechaFmt = isNaN(fechaDT.getTime()) ? p.fecha : (fechaDT.getDate() + ' ' + MN_C[fechaDT.getMonth()] + ' ' + fechaDT.getFullYear());

    return `<div style="padding:10px;background:var(--bg);border:1px solid var(--border);border-radius:10px;margin-bottom:8px;display:flex;flex-direction:column;gap:6px;animation:slideDown .25s ease">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <span style="font-size:11px;font-weight:700;color:var(--muted);">${fechaFmt}</span>
            <span style="font-size:12px;font-weight:800;color:var(--gold);">$${parseFloat(p.total).toFixed(2)}</span>
        </div>
        <div style="border-top:1px dashed var(--border);padding-top:6px;display:flex;flex-direction:column;gap:2px;">
            ${articulosHtml}
        </div>
        <div style="border-top:1px dashed var(--border);padding-top:6px;display:flex;align-items:center;justify-content:space-between;">
            <div style="font-size:10px;color:var(--muted);">${p.metodo_pago} ${p.referencia_pago ? '#' + p.referencia_pago : ''}</div>
            <div>
                ${estadoBadgeHtml}
                ${pagoBadge}
            </div>
        </div>
    </div>`;
}

function puedeCancelar(c) {
    if (c.estado === 'cancelada' || c.estado === 'completada') return false;
    const [cy, cm, cd] = c.fecha.split('-').map(Number);
    const [ch, cmn] = (c.hora || '00:00').split(':').map(Number);
    const citaDT = new Date(cy, cm - 1, cd, ch, cmn, 0);
    const nowVE = getVETime();
    const limite = new Date(nowVE.getTime() + 2 * 3600 * 1000);
    return citaDT > limite;
}

function citaCard(c) {
    const cancelBtn = puedeCancelar(c) ?
        `<button onclick="cancelarCita(${c.id})" style="margin-top:6px;padding:5px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;font-size:11px;font-weight:700;color:#dc2626;cursor:pointer;display:flex;align-items:center;gap:4px">
            <i class="fas fa-times-circle" style="font-size:10px"></i> Cancelar cita
        </button>` : '';

    return `<div id="cita-${c.id}" style="display:flex;align-items:flex-start;gap:12px;padding:10px;background:var(--bg);border:1px solid var(--border);border-radius:10px;margin-bottom:8px;animation:slideDown .25s ease">
        <div style="width:42px;height:42px;border-radius:10px;background:var(--navy-lt);color:var(--navy);display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;font-weight:800">
            <span style="font-size:14px;line-height:1">${c.fecha.split('-')[2]}</span>
            <span style="font-size:9px;text-transform:uppercase">${MN_C[parseInt(c.fecha.split('-')[1])-1]}</span>
        </div>
        <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:700;margin-bottom:3px">${c.servicio}</div>
            <div style="font-size:11px;color:var(--muted);margin-bottom:5px">
                <i class="fas fa-clock" style="font-size:9px;margin-right:2px"></i>${fmtHora(c.hora)}
                <span style="margin:0 4px;opacity:.3">|</span>
                <i class="fas fa-user" style="font-size:9px;margin-right:2px"></i>${c.barbero}
            </div>
            <div>${estadoBadge(c.estado, c.estado_pago)}</div>
            ${cancelBtn}
        </div>
    </div>`;
}

function cancelarCita(citaId) {
    if (!confirm('¿Seguro que deseas cancelar esta cita?')) return;
    const tel = document.getElementById('citasPhone').value.trim().replace(/\D/g,'');
    fetch('../api/v1/cancelar_cita', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ cita_id: citaId, telefono: tel, t: STORE_TOKEN })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            buscarCitas();
        } else {
            alert(data.error || 'No se pudo cancelar la cita.');
        }
    })
    .catch(() => alert('Error de conexión.'));
}

document.getElementById('citasPhone')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); buscarCitas(); }
});
</script>

<?php elseif ($tab === 'info'): ?>

<div class="main">

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


<?php endif; ?>

</div><!-- .page-card -->
</body>
</html>
