<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../backend/config/config.php';

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'superadmin') {
    header("Location: ../");
    exit;
}

$page = $_GET['page'] ?? 'dashboard';
$msg  = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';

// === KPIs MACRO ===
$total_citas      = $conn->query("SELECT COUNT(*) as t FROM citas")->fetch_assoc()['t'];
$total_sucursales = ($conn->query("SELECT COUNT(*) as t FROM sucursales")->fetch_assoc()['t']) + 1;
$total_barberos   = $conn->query("SELECT COUNT(*) as t FROM barberos")->fetch_assoc()['t'];

// === GRÁFICA DASHBOARD ===
$dias_labels = []; $citas_valores = [];
$res = $conn->query("SELECT fecha, COUNT(*) as c FROM citas GROUP BY fecha ORDER BY fecha DESC LIMIT 7");
if ($res) { while ($r = $res->fetch_assoc()) { $dias_labels[] = date('d M', strtotime($r['fecha'])); $citas_valores[] = $r['c']; } }
$dias_labels = array_reverse($dias_labels); $citas_valores = array_reverse($citas_valores);

// === VERIFICAR TABLAS NUEVAS ===
$has_planes = $conn->query("SHOW TABLES LIKE 'planes'")->num_rows > 0;
$has_pagos  = $conn->query("SHOW TABLES LIKE 'pagos_suscripcion'")->num_rows > 0;
$has_subs   = $conn->query("SHOW TABLES LIKE 'suscripciones'")->num_rows > 0;
$setup_ok   = $has_planes && $has_pagos && $has_subs;

// === PLANES ===
$planes_arr = [];
if ($has_planes) {
    $r = $conn->query("SELECT * FROM planes ORDER BY precio_mensual ASC");
    while ($p = $r->fetch_assoc()) $planes_arr[] = $p;
}

// === PAGOS ===
$pagos_arr = []; $total_ingresos = 0; $ingresos_mes = 0;
if ($has_pagos) {
    $r = $conn->query("SELECT p.*, s.nombre as suc_nombre, pl.nombre as plan_nombre
        FROM pagos_suscripcion p
        LEFT JOIN sucursales s ON p.sucursal_id = s.id
        LEFT JOIN planes pl ON p.plan_id = pl.id
        ORDER BY p.fecha_pago DESC LIMIT 100");
    if ($r) while ($row = $r->fetch_assoc()) $pagos_arr[] = $row;
    $total_ingresos = $conn->query("SELECT COALESCE(SUM(monto),0) as t FROM pagos_suscripcion")->fetch_assoc()['t'];
    $ingresos_mes   = $conn->query("SELECT COALESCE(SUM(monto),0) as t FROM pagos_suscripcion WHERE MONTH(fecha_pago)=MONTH(NOW()) AND YEAR(fecha_pago)=YEAR(NOW())")->fetch_assoc()['t'];
}

// === ESTADÍSTICAS ===
$subs_activas = 0; $subs_vencidas = 0; $subs_arr = [];
$ingresos_por_mes_labels = []; $ingresos_por_mes_vals = [];
if ($has_subs && $has_pagos) {
    $subs_activas  = $conn->query("SELECT COUNT(*) as t FROM suscripciones WHERE estado='activo' AND fecha_vencimiento >= CURDATE()")->fetch_assoc()['t'];
    $subs_vencidas = $conn->query("SELECT COUNT(*) as t FROM suscripciones WHERE fecha_vencimiento < CURDATE() OR estado='vencido'")->fetch_assoc()['t'];
    $r = $conn->query("SELECT s.*, suc.nombre as suc_nombre, pl.nombre as plan_nombre, pl.precio_mensual
        FROM suscripciones s
        JOIN sucursales suc ON s.sucursal_id = suc.id
        JOIN planes pl ON s.plan_id = pl.id
        ORDER BY s.fecha_vencimiento ASC");
    if ($r) while ($row = $r->fetch_assoc()) $subs_arr[] = $row;
    $r2 = $conn->query("SELECT DATE_FORMAT(fecha_pago,'%b %Y') as mes, SUM(monto) as total
        FROM pagos_suscripcion WHERE fecha_pago >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(fecha_pago,'%Y-%m') ORDER BY fecha_pago ASC");
    if ($r2) while ($row = $r2->fetch_assoc()) {
        $ingresos_por_mes_labels[] = $row['mes'];
        $ingresos_por_mes_vals[]   = (float)$row['total'];
    }
}

// Recaudación por sucursal
$recaudacion_suc = [];
if ($has_pagos) {
    $r = $conn->query("SELECT s.id, s.nombre, COALESCE(SUM(p.monto),0) as total
        FROM sucursales s LEFT JOIN pagos_suscripcion p ON p.sucursal_id = s.id
        GROUP BY s.id, s.nombre");
    if ($r) while ($row = $r->fetch_assoc()) $recaudacion_suc[$row['id']] = $row;
}

// === CONFIG ===
$config = [];
$r = $conn->query("SELECT * FROM configuracion");
if ($r) while ($row = $r->fetch_assoc()) $config[$row['clave']] = $row['valor'];

// === SUCURSALES ===
$sucursales_arr = [];
$r = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
if ($r) while ($row = $r->fetch_assoc()) $sucursales_arr[] = $row;

// Plan colores
$plan_colors = [
    0 => ['bg'=>'#eff6ff','color'=>'#1d4ed8','border'=>'#bfdbfe'],
    1 => ['bg'=>'#fdf4ff','color'=>'#7e22ce','border'=>'#e9d5ff'],
    2 => ['bg'=>'#fffbeb','color'=>'#92400e','border'=>'#fde68a'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Torre de Control — AlCorte</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        html,body{height:100%;font-family:'Inter',-apple-system,sans-serif;background:#f8fafc;color:#0f172a}
        .layout{display:flex;min-height:100vh}

        /* ── SIDEBAR ── */
        .sidebar{width:248px;background:#fff;border-right:1px solid #e2e8f0;display:flex;flex-direction:column;position:fixed;height:100vh;left:0;top:0;overflow-y:auto;z-index:100}
        .sidebar-brand{padding:22px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:10px}
        .sidebar-brand i{font-size:20px;color:#b49363}
        .brand-name{font-size:13px;font-weight:900;letter-spacing:.05em;color:#0f172a}
        .brand-sub{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.2em;color:#94a3b8;margin-top:1px}
        .sidebar-nav{flex:1;padding:14px 12px}
        .nav-section{font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.2em;color:#94a3b8;padding:14px 10px 5px}
        .nav-item{display:flex;align-items:center;gap:10px;padding:10px 12px;margin-bottom:2px;border-radius:.75rem;font-size:13px;font-weight:700;text-decoration:none;color:#64748b;transition:all .15s}
        .nav-item:hover{background:#f8fafc;color:#0f172a}
        .nav-item.active{background:rgba(180,147,99,.08);color:#0f172a;font-weight:900;border-left:3px solid #b49363;padding-left:9px}
        .nav-item i{width:18px;font-size:13px;flex-shrink:0}
        .sidebar-user{padding:14px;border-top:1px solid #f1f5f9}
        .user-row{display:flex;align-items:center;gap:10px;margin-bottom:10px}
        .user-avatar{width:36px;height:36px;border-radius:50%;background:#0f172a;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:11px;flex-shrink:0;letter-spacing:.05em}
        .user-name{font-size:13px;font-weight:800;color:#0f172a}
        .user-role{font-size:9px;color:#94a3b8;text-transform:uppercase;font-weight:900;letter-spacing:.15em}
        .logout-btn{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;padding:9px;background:none;border:1px solid #e2e8f0;border-radius:.75rem;color:#64748b;cursor:pointer;font-size:12px;font-weight:700;text-decoration:none;transition:all .15s;text-transform:uppercase;letter-spacing:.05em}
        .logout-btn:hover{color:#ef4444;border-color:#fecaca;background:#fef2f2}

        /* ── MAIN ── */
        .main{flex:1;margin-left:248px;display:flex;flex-direction:column;min-height:100vh}
        .topnav{height:62px;background:#fff;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:50}
        .breadcrumb{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.2em;color:#94a3b8;display:flex;align-items:center;gap:8px}
        .breadcrumb span{color:#0f172a}
        .topnav-right{display:flex;align-items:center;gap:10px}
        .msg-pill{font-size:11px;font-weight:800;padding:6px 14px;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;border-radius:2rem;letter-spacing:.03em}
        .setup-btn{font-size:11px;font-weight:900;padding:8px 16px;background:#7c3aed;color:white;border-radius:.75rem;text-decoration:none;text-transform:uppercase;letter-spacing:.08em;transition:all .15s}
        .setup-btn:hover{background:#6d28d9}
        .content{flex:1;padding:28px 28px 40px}

        /* ── PAGE HEADER ── */
        .page-header{margin-bottom:28px}
        .page-title{font-size:2rem;font-weight:900;color:#0f172a;font-style:italic;letter-spacing:-.03em;line-height:1.1}
        .page-title span{color:#b49363}
        .page-subtitle{font-size:13px;color:#64748b;font-weight:500;margin-top:4px}

        /* ── STAT CARDS (al-turno style: left border) ── */
        .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px;margin-bottom:28px}
        .stat-card{background:#fff;border:1px solid #e2e8f0;border-radius:.75rem;padding:20px 20px 18px;border-left:4px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.04)}
        .stat-card.gold{border-left-color:#b49363;box-shadow:0 4px 16px rgba(180,147,99,.08)}
        .stat-card.green{border-left-color:#10b981;box-shadow:0 4px 16px rgba(16,185,129,.06)}
        .stat-card.blue{border-left-color:#3b82f6;box-shadow:0 4px 16px rgba(59,130,246,.06)}
        .stat-card.purple{border-left-color:#8b5cf6;box-shadow:0 4px 16px rgba(139,92,246,.06)}
        .stat-card.rose{border-left-color:#f43f5e;box-shadow:0 4px 16px rgba(244,63,94,.06)}
        .stat-label{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.15em;color:#94a3b8;margin-bottom:8px}
        .stat-value{font-size:1.75rem;font-weight:900;color:#0f172a;line-height:1}
        .stat-value small{font-size:11px;font-weight:500;color:#94a3b8}

        /* ── CARD ── */
        .card{background:#fff;border:1px solid #e2e8f0;border-radius:1.25rem;overflow:hidden;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
        .card-header{padding:16px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between}
        .card-header h3{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.15em;color:#0f172a}
        .card-header small{font-size:11px;color:#94a3b8;font-weight:500;text-transform:none;letter-spacing:0}
        .card-body{padding:20px}

        /* ── TABLE ── */
        .table-wrap{overflow-x:auto}
        table{width:100%;border-collapse:collapse}
        thead tr{background:#f8fafc}
        th{padding:11px 16px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.15em;color:#94a3b8;text-align:left;border-bottom:2px solid #e2e8f0}
        td{padding:14px 16px;font-size:13px;color:#374151;border-bottom:1px solid #f1f5f9;vertical-align:middle}
        tr:last-child td{border-bottom:none}
        tbody tr:hover td{background:#f8fafc}

        /* ── STATUS DOT (al-turno style) ── */
        .status-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:2rem;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.1em}
        .status-pill.active{background:#f0fdf4;color:#15803d}
        .status-pill.active .dot{width:7px;height:7px;border-radius:50%;background:#22c55e;animation:pulse 1.5s infinite}
        .status-pill.expired{background:#fef2f2;color:#dc2626}
        .status-pill.expired .dot{width:7px;height:7px;border-radius:50%;background:#ef4444}
        .status-pill.suspended{background:#f8fafc;color:#64748b}
        .status-pill.suspended .dot{width:7px;height:7px;border-radius:50%;background:#94a3b8}
        @keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}

        /* ── PLAN BADGE ── */
        .plan-badge{display:inline-block;padding:3px 9px;border-radius:.5rem;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.08em;border:1px solid}

        /* ── DARK PLAN CARD (al-turno style) ── */
        .plan-dark-card{background:#0f172a;border-radius:2rem;padding:28px;color:white;position:relative;overflow:hidden}
        .plan-dark-card::before{content:'';position:absolute;top:-40px;right:-40px;width:180px;height:180px;background:radial-gradient(circle,rgba(180,147,99,.15) 0%,transparent 70%);border-radius:50%}
        .plan-dark-label{font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.2em;color:rgba(255,255,255,.4);margin-bottom:12px}
        .plan-dark-name{font-size:2.5rem;font-weight:900;font-style:italic;letter-spacing:-.03em;color:white;line-height:1}
        .plan-dark-price{font-size:1.25rem;font-weight:900;color:#b49363;margin-top:8px;font-family:Monaco,monospace}
        .plan-dark-features{margin-top:16px;padding-top:16px;border-top:1px solid rgba(255,255,255,.08)}
        .plan-dark-feature{font-size:12px;color:rgba(255,255,255,.6);padding:3px 0;display:flex;align-items:center;gap:8px}
        .plan-dark-feature i{color:#b49363;font-size:10px}
        .plan-dark-actions{position:absolute;top:16px;right:16px;display:flex;gap:6px}

        /* ── BUTTONS ── */
        .btn{padding:10px 18px;border:none;border-radius:.75rem;font-size:11px;font-weight:900;cursor:pointer;transition:all .15s;text-transform:uppercase;letter-spacing:.08em;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
        .btn:active{transform:scale(.97)}
        .btn-gold{background:#b49363;color:white}
        .btn-gold:hover{background:#9d7e54}
        .btn-dark{background:#0f172a;color:white}
        .btn-dark:hover{background:#1e293b}
        .btn-ghost{background:#f8fafc;color:#64748b;border:1px solid #e2e8f0}
        .btn-ghost:hover{background:#f1f5f9}
        .btn-indigo{background:#eef2ff;color:#4f46e5;border:1px solid #e0e7ff}
        .btn-indigo:hover{background:#4f46e5;color:white}
        .btn-danger{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
        .btn-danger:hover{background:#fecaca}
        .btn-sm{padding:6px 12px;font-size:10px}
        .btn-full{width:100%;justify-content:center}

        /* ── FORM ── */
        .form-group{margin-bottom:14px}
        .form-label{display:block;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.15em;color:#64748b;margin-bottom:5px}
        .form-input,.form-select,.form-textarea{width:100%;padding:10px 12px;border:1px solid #e2e8f0;border-radius:.625rem;font-size:13px;font-family:inherit;color:#0f172a;transition:all .15s;background:#fff}
        .form-input:focus,.form-select:focus,.form-textarea:focus{outline:none;border-color:#b49363;box-shadow:0 0 0 3px rgba(180,147,99,.1)}
        .form-textarea{resize:vertical;min-height:72px}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}

        /* ── TOTAL PAID MINI CARD ── */
        .total-card{background:#fff;border:1px solid #e2e8f0;border-radius:1.25rem;padding:20px 24px;display:flex;justify-content:space-between;align-items:center}
        .total-card-label{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.15em;color:#94a3b8}
        .total-card-val{font-size:1.5rem;font-weight:900;color:#0f172a;font-family:Monaco,monospace}

        /* ── GRID ── */
        .grid-2{display:grid;grid-template-columns:1fr 2fr;gap:24px}
        .grid-equal{display:grid;grid-template-columns:1fr 1fr;gap:24px}
        .grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}

        /* ── ACTION BUTTON (al-turno table action style) ── */
        .action-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;background:#f0f5ff;color:#4f46e5;border:1px solid #e0e7ff;border-radius:.625rem;cursor:pointer;transition:all .15s;text-decoration:none}
        .action-btn:hover{background:#4f46e5;color:white}

        /* ── DIVIDER ── */
        .divider{height:1px;background:#f1f5f9;margin:20px 0}

        /* ── SETUP BANNER ── */
        .setup-banner{margin:0 0 24px;padding:14px 18px;background:#fffbeb;border:1px solid #fde68a;border-radius:.75rem;font-size:13px;color:#92400e;display:flex;align-items:center;gap:10px;font-weight:600}

        @media(max-width:768px){
            .sidebar{width:210px}.main{margin-left:210px}
            .grid-2,.grid-equal,.grid-3{grid-template-columns:1fr}
            .form-row{grid-template-columns:1fr}
            .content{padding:16px}
        }
        @media(max-width:640px){
            .sidebar{position:fixed;left:-100%;transition:left .3s}
            .sidebar.open{left:0}
            .main{margin-left:0}
        }
    </style>
</head>
<body>
<div class="layout">

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <i class="fas fa-scissors"></i>
        <div>
            <div class="brand-name">AlCorte</div>
            <div class="brand-sub">Torre de Control</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section">Sistema</div>
        <a href="superadmin.php?page=dashboard" class="nav-item <?php echo $page=='dashboard'?'active':''; ?>">
            <i class="fas fa-chart-pie"></i><span>Dashboard</span>
        </a>
        <a href="superadmin.php?page=estadisticas" class="nav-item <?php echo $page=='estadisticas'?'active':''; ?>">
            <i class="fas fa-chart-bar"></i><span>Estadísticas</span>
        </a>
        <div class="nav-section">Negocios</div>
        <a href="superadmin.php?page=barbershops" class="nav-item <?php echo $page=='barbershops'?'active':''; ?>">
            <i class="fas fa-store"></i><span>Sucursales</span>
        </a>
        <a href="superadmin.php?page=planes" class="nav-item <?php echo $page=='planes'?'active':''; ?>">
            <i class="fas fa-layer-group"></i><span>Planes</span>
        </a>
        <a href="superadmin.php?page=pagos" class="nav-item <?php echo $page=='pagos'?'active':''; ?>">
            <i class="fas fa-credit-card"></i><span>Pagos</span>
        </a>
        <div class="nav-section">Admin</div>
        <a href="usuarios.php" class="nav-item">
            <i class="fas fa-users"></i><span>Usuarios</span>
        </a>
        <a href="superadmin.php?page=settings" class="nav-item <?php echo $page=='settings'?'active':''; ?>">
            <i class="fas fa-sliders-h"></i><span>Configuración</span>
        </a>
    </nav>
    <div class="sidebar-user">
        <div class="user-row">
            <div class="user-avatar">SA</div>
            <div>
                <div class="user-name"><?php echo htmlspecialchars(substr($_SESSION['nombre'],0,14)); ?></div>
                <div class="user-role">SuperAdmin</div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Salir</a>
    </div>
</aside>

<!-- ══ MAIN ══ -->
<div class="main">
    <nav class="topnav">
        <div class="breadcrumb">
            Torre de Control <i class="fas fa-chevron-right" style="font-size:8px;color:#cbd5e1"></i>
            <span><?php echo strtoupper(str_replace('_',' ',$page)); ?></span>
        </div>
        <div class="topnav-right">
            <?php if($msg): ?><span class="msg-pill">✓ <?php echo $msg; ?></span><?php endif; ?>
            <?php if(!$setup_ok): ?>
                <a href="../backend/database/setup.php" class="setup-btn"><i class="fas fa-database"></i> Inicializar BD</a>
            <?php endif; ?>
        </div>
    </nav>

    <div class="content">

        <?php if(!$setup_ok && in_array($page,['pagos','planes','estadisticas'])): ?>
        <div class="setup-banner">
            <i class="fas fa-exclamation-triangle"></i>
            Las tablas de <strong>planes</strong>, <strong>pagos</strong> y <strong>suscripciones</strong> no existen aún.
            <a href="../backend/database/setup.php" style="color:#92400e;text-decoration:underline;font-weight:800;margin-left:4px">Créalas aquí →</a>
        </div>
        <?php endif; ?>

        <!-- ══════════════ DASHBOARD ══════════════ -->
        <?php if($page == 'dashboard'): ?>

        <div class="page-header">
            <h1 class="page-title">Torre de <span>Control</span></h1>
            <p class="page-subtitle">Monitoreo global de sucursales, rendimiento y facturación.</p>
        </div>

        <div class="kpi-grid">
            <div class="stat-card blue">
                <div class="stat-label">Total Citas</div>
                <div class="stat-value"><?php echo number_format($total_citas); ?></div>
            </div>
            <div class="stat-card gold">
                <div class="stat-label">Sucursales</div>
                <div class="stat-value"><?php echo $total_sucursales; ?></div>
            </div>
            <div class="stat-card purple">
                <div class="stat-label">Barberos</div>
                <div class="stat-value"><?php echo $total_barberos; ?></div>
            </div>
            <div class="stat-card green">
                <div class="stat-label">Ingresos Totales</div>
                <div class="stat-value">$<?php echo number_format($total_ingresos,2); ?></div>
            </div>
            <div class="stat-card rose">
                <div class="stat-label">Este Mes</div>
                <div class="stat-value">$<?php echo number_format($ingresos_mes,2); ?></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3>Volumen de Citas — Últimos 7 días</h3></div>
            <div class="card-body">
                <div style="height:280px"><canvas id="chartDash"></canvas></div>
            </div>
        </div>
        <script>
        new Chart(document.getElementById('chartDash').getContext('2d'),{
            type:'line',
            data:{labels:<?php echo json_encode($dias_labels); ?>,datasets:[{
                label:'Citas',data:<?php echo json_encode($citas_valores); ?>,
                borderColor:'#b49363',backgroundColor:'rgba(180,147,99,.05)',
                borderWidth:2.5,fill:true,tension:.3,
                pointRadius:5,pointBackgroundColor:'#b49363',pointBorderColor:'#fff',pointBorderWidth:2
            }]},
            options:{responsive:true,maintainAspectRatio:false,
                plugins:{legend:{display:false}},
                scales:{y:{beginAtZero:true,grid:{color:'#f1f5f9'},ticks:{color:'#94a3b8'}},
                        x:{grid:{display:false},ticks:{color:'#94a3b8'}}}}
        });
        </script>

        <!-- ══════════════ ESTADÍSTICAS ══════════════ -->
        <?php elseif($page == 'estadisticas'): ?>

        <div class="page-header">
            <h1 class="page-title">Estado de <span>Suscripciones</span></h1>
            <p class="page-subtitle">Seguimiento de planes activos, vencimientos y recaudación por negocio.</p>
        </div>

        <div class="kpi-grid">
            <div class="stat-card green">
                <div class="stat-label">Suscripciones Activas</div>
                <div class="stat-value"><?php echo $subs_activas; ?></div>
            </div>
            <div class="stat-card rose">
                <div class="stat-label">Suscripciones Vencidas</div>
                <div class="stat-value"><?php echo $subs_vencidas; ?></div>
            </div>
            <div class="stat-card gold">
                <div class="stat-label">Ingresos Totales</div>
                <div class="stat-value">$<?php echo number_format($total_ingresos,2); ?></div>
            </div>
            <div class="stat-card blue">
                <div class="stat-label">Este Mes</div>
                <div class="stat-value">$<?php echo number_format($ingresos_mes,2); ?></div>
            </div>
        </div>

        <div class="grid-equal" style="margin-bottom:24px">
            <div class="card" style="margin-bottom:0">
                <div class="card-header"><h3>Ingresos por Mes</h3></div>
                <div class="card-body"><div style="height:220px"><canvas id="chartBar"></canvas></div></div>
            </div>
            <div class="card" style="margin-bottom:0">
                <div class="card-header"><h3>Asignar Plan a Sucursal</h3></div>
                <div class="card-body">
                    <form action="../backend/processing/superadmin.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                        <input type="hidden" name="action" value="assign_plan">
                        <div class="form-group">
                            <label class="form-label">Sucursal</label>
                            <select name="sub_sucursal" class="form-select" required>
                                <option value="">— Seleccionar sucursal —</option>
                                <?php foreach($sucursales_arr as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Plan</label>
                            <select name="sub_plan" class="form-select" required>
                                <option value="">— Seleccionar plan —</option>
                                <?php foreach($planes_arr as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nombre']); ?> — $<?php echo number_format($p['precio_mensual'],2); ?>/mes</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Desde</label>
                                <input type="date" name="sub_inicio" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Hasta</label>
                                <input type="date" name="sub_fin" class="form-input" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-gold btn-full">Asignar Plan</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3>Listado de Negocios</h3>
                <small>Estado de planes y subdominios</small>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <th>Negocio / Sede</th>
                        <th>Plan Activo</th>
                        <th>Recaudación</th>
                        <th>Vencimiento</th>
                        <th>Estado</th>
                        <th style="text-align:right">Acción</th>
                    </tr></thead>
                    <tbody>
                    <?php
                    $all_suc = $conn->query("SELECT s.*, sub.plan_id, sub.fecha_vencimiento, sub.estado as sub_estado, pl.nombre as plan_nombre, pl.precio_mensual
                        FROM sucursales s
                        LEFT JOIN suscripciones sub ON sub.sucursal_id = s.id
                        LEFT JOIN planes pl ON pl.id = sub.plan_id
                        ORDER BY s.nombre");
                    if ($all_suc && $all_suc->num_rows > 0):
                        $pci = 0;
                        while ($suc = $all_suc->fetch_assoc()):
                            $rec = $recaudacion_suc[$suc['id']]['total'] ?? 0;
                            $hoy = new DateTime();
                            $vence = $suc['fecha_vencimiento'] ? new DateTime($suc['fecha_vencimiento']) : null;
                            $vencida = $vence && $vence < $hoy;
                            $proxima = $vence && !$vencida && $hoy->diff($vence)->days <= 7;
                            $pc = $plan_colors[$pci % 3];
                            $pci++;
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight:800;color:#0f172a;font-size:13px"><?php echo htmlspecialchars($suc['nombre']); ?></div>
                        </td>
                        <td>
                            <?php if($suc['plan_nombre']): ?>
                            <span class="plan-badge" style="background:<?php echo $pc['bg']; ?>;color:<?php echo $pc['color']; ?>;border-color:<?php echo $pc['border']; ?>">
                                <?php echo htmlspecialchars($suc['plan_nombre']); ?>
                            </span>
                            <?php else: ?>
                            <span style="font-size:11px;color:#94a3b8;font-style:italic">Sin plan</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-family:Monaco,monospace;font-weight:800;color:#0f172a">
                            $<?php echo number_format($rec, 2); ?>
                        </td>
                        <td style="font-size:12px">
                            <?php if($vence): ?>
                                <?php echo $vence->format('d/m/Y'); ?>
                                <?php if($proxima): ?><span style="font-size:10px;color:#d97706;font-weight:800;margin-left:4px">⚠ Vence pronto</span><?php endif; ?>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-style:italic">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if(!$suc['plan_nombre']): ?>
                                <span class="status-pill suspended"><span class="dot"></span>Sin plan</span>
                            <?php elseif($vencida): ?>
                                <span class="status-pill expired"><span class="dot"></span>Vencida</span>
                            <?php else: ?>
                                <span class="status-pill active"><span class="dot"></span>Activa</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right">
                            <a href="superadmin.php?page=pagos" class="action-btn">
                                <i class="fas fa-eye"></i> Gestionar
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8">Sin sucursales registradas</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        new Chart(document.getElementById('chartBar').getContext('2d'),{
            type:'bar',
            data:{labels:<?php echo json_encode($ingresos_por_mes_labels); ?>,datasets:[{
                label:'$',data:<?php echo json_encode($ingresos_por_mes_vals); ?>,
                backgroundColor:'rgba(180,147,99,.75)',borderColor:'#b49363',borderWidth:1,borderRadius:8
            }]},
            options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
                scales:{y:{beginAtZero:true,grid:{color:'#f1f5f9'},ticks:{color:'#94a3b8',callback:v=>'$'+v}},
                        x:{grid:{display:false},ticks:{color:'#94a3b8'}}}}
        });
        </script>

        <!-- ══════════════ SUCURSALES ══════════════ -->
        <?php elseif($page == 'barbershops'): ?>

        <div class="page-header">
            <h1 class="page-title">Gestión de <span>Sucursales</span></h1>
            <p class="page-subtitle">Registro y administración de sedes del sistema.</p>
        </div>

        <div class="grid-2">
            <div class="card" style="align-self:start;margin-bottom:0">
                <div class="card-header" id="suc-form-title"><h3>Registrar Sucursal</h3></div>
                <div class="card-body">
                    <form action="../backend/processing/superadmin.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                        <input type="hidden" id="shop_action" name="action" value="add_sucursal">
                        <input type="hidden" id="shop_id" name="id" value="">
                        <div class="form-group">
                            <label class="form-label">Nombre Comercial</label>
                            <input type="text" id="shop_name" name="shop_name" class="form-input" placeholder="Ej. Sede Norte" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Dirección</label>
                            <textarea id="shop_address" name="shop_address" class="form-textarea" placeholder="Ubicación detallada" required></textarea>
                        </div>
                        <div style="display:flex;gap:8px">
                            <button type="submit" class="btn btn-gold" style="flex:1;justify-content:center">Guardar</button>
                            <button type="button" onclick="resetSucForm()" class="btn btn-ghost">Cancelar</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card" style="margin-bottom:0">
                <div class="card-header"><h3>Sucursales en el Sistema</h3></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Nombre</th><th>Dirección</th><th style="text-align:right">Acciones</th></tr></thead>
                        <tbody>
                        <tr>
                            <td><div style="font-weight:800">Sede Central</div></td>
                            <td style="font-size:12px;color:#94a3b8">Matriz integrada</td>
                            <td style="text-align:right"><span class="plan-badge" style="background:#fffbeb;color:#92400e;border-color:#fde68a">Inmutable</span></td>
                        </tr>
                        <?php foreach($sucursales_arr as $s): ?>
                        <tr>
                            <td style="font-weight:800"><?php echo htmlspecialchars($s['nombre']); ?></td>
                            <td style="font-size:12px;color:#64748b"><?php echo htmlspecialchars($s['direccion'] ?? '—'); ?></td>
                            <td style="text-align:right">
                                <div style="display:flex;justify-content:flex-end;gap:6px">
                                    <button onclick="editSuc(<?php echo $s['id']; ?>,'<?php echo htmlspecialchars($s['nombre'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($s['direccion']??'',ENT_QUOTES); ?>')" class="btn btn-ghost btn-sm">
                                        <i class="fas fa-pen"></i> Editar
                                    </button>
                                    <form action="../backend/processing/superadmin.php" method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar esta sucursal?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                        <input type="hidden" name="action" value="delete_sucursal">
                                        <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <script>
        function editSuc(id,nombre,dir){
            document.getElementById('shop_id').value=id;
            document.getElementById('shop_name').value=nombre;
            document.getElementById('shop_address').value=dir;
            document.getElementById('shop_action').value='edit_sucursal';
            document.querySelector('#suc-form-title h3').textContent='Editar Sucursal';
            document.getElementById('shop_name').focus();
        }
        function resetSucForm(){
            document.getElementById('shop_id').value='';
            document.getElementById('shop_name').value='';
            document.getElementById('shop_address').value='';
            document.getElementById('shop_action').value='add_sucursal';
            document.querySelector('#suc-form-title h3').textContent='Registrar Sucursal';
        }
        </script>

        <!-- ══════════════ PLANES ══════════════ -->
        <?php elseif($page == 'planes'): ?>

        <div class="page-header">
            <h1 class="page-title">Plan de <span>Facturación</span></h1>
            <p class="page-subtitle">Define los planes de suscripción disponibles para los negocios.</p>
        </div>

        <div class="grid-2">
            <div class="card" style="align-self:start;margin-bottom:0">
                <div class="card-header" id="plan-form-title"><h3>Crear Plan</h3></div>
                <div class="card-body">
                    <form action="../backend/processing/superadmin.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                        <input type="hidden" id="plan_action" name="action" value="add_plan">
                        <input type="hidden" id="plan_id" name="plan_id" value="">
                        <div class="form-group">
                            <label class="form-label">Nombre del Plan</label>
                            <input type="text" id="plan_nombre" name="plan_nombre" class="form-input" placeholder="Ej. Profesional" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Precio Mensual ($)</label>
                            <input type="number" id="plan_precio" name="plan_precio" class="form-input" placeholder="0.00" step="0.01" min="0" required>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Máx. Barberos</label>
                                <input type="number" id="plan_max_barberos" name="plan_max_barberos" class="form-input" value="3" min="1" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Máx. Citas/mes</label>
                                <input type="number" id="plan_max_citas" name="plan_max_citas" class="form-input" value="100" min="1" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Descripción</label>
                            <textarea id="plan_descripcion" name="plan_descripcion" class="form-textarea" placeholder="Descripción breve del plan"></textarea>
                        </div>
                        <div style="display:flex;gap:8px">
                            <button type="submit" class="btn btn-gold" style="flex:1;justify-content:center">Guardar Plan</button>
                            <button type="button" onclick="resetPlan()" class="btn btn-ghost">Cancelar</button>
                        </div>
                    </form>
                </div>
            </div>

            <div>
                <?php if(empty($planes_arr)): ?>
                    <div style="text-align:center;padding:60px;color:#94a3b8">
                        <i class="fas fa-layer-group" style="font-size:40px;margin-bottom:16px;display:block"></i>
                        <p style="font-weight:700">Sin planes registrados.</p>
                        <p style="font-size:13px;margin-top:4px">Crea el primero con el formulario.</p>
                    </div>
                <?php else: ?>
                <?php $pi=0; foreach($planes_arr as $plan): $pc=$plan_colors[$pi%3]; $pi++; ?>
                <!-- Dark plan card al-turno style -->
                <div class="plan-dark-card" style="margin-bottom:16px">
                    <div class="plan-dark-actions">
                        <button onclick="editPlan(<?php echo htmlspecialchars(json_encode($plan),ENT_QUOTES); ?>)" class="btn btn-ghost btn-sm" style="background:rgba(255,255,255,.1);color:white;border-color:rgba(255,255,255,.15)">
                            <i class="fas fa-pen"></i>
                        </button>
                        <form action="../backend/processing/superadmin.php" method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar plan?')">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                            <input type="hidden" name="action" value="delete_plan">
                            <input type="hidden" name="id" value="<?php echo $plan['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm" style="background:rgba(239,68,68,.15);color:#fca5a5;border-color:rgba(239,68,68,.2)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                    <div class="plan-dark-label">Plan de Suscripción</div>
                    <div class="plan-dark-name"><?php echo htmlspecialchars($plan['nombre']); ?></div>
                    <div class="plan-dark-price">$<?php echo number_format($plan['precio_mensual'],2); ?><span style="font-size:13px;color:#94a3b8;font-family:Inter,sans-serif;font-weight:500">/mes</span></div>
                    <div class="plan-dark-features">
                        <div class="plan-dark-feature"><i class="fas fa-check-circle"></i> Hasta <?php echo $plan['max_barberos']; ?> barberos activos</div>
                        <div class="plan-dark-feature"><i class="fas fa-check-circle"></i> <?php echo number_format($plan['max_citas_mes']); ?> citas por mes</div>
                        <?php if($plan['descripcion']): ?>
                        <div class="plan-dark-feature" style="margin-top:4px;color:rgba(255,255,255,.4)"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($plan['descripcion']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <script>
        function editPlan(p){
            document.getElementById('plan_id').value=p.id;
            document.getElementById('plan_nombre').value=p.nombre;
            document.getElementById('plan_precio').value=p.precio_mensual;
            document.getElementById('plan_max_barberos').value=p.max_barberos;
            document.getElementById('plan_max_citas').value=p.max_citas_mes;
            document.getElementById('plan_descripcion').value=p.descripcion||'';
            document.getElementById('plan_action').value='edit_plan';
            document.querySelector('#plan-form-title h3').textContent='Editar Plan';
            window.scrollTo({top:0,behavior:'smooth'});
        }
        function resetPlan(){
            document.getElementById('plan_id').value='';
            document.getElementById('plan_nombre').value='';
            document.getElementById('plan_precio').value='';
            document.getElementById('plan_max_barberos').value='3';
            document.getElementById('plan_max_citas').value='100';
            document.getElementById('plan_descripcion').value='';
            document.getElementById('plan_action').value='add_plan';
            document.querySelector('#plan-form-title h3').textContent='Crear Plan';
        }
        </script>

        <!-- ══════════════ PAGOS ══════════════ -->
        <?php elseif($page == 'pagos'): ?>

        <div class="page-header">
            <h1 class="page-title">Gestión de <span>Pagos</span></h1>
            <p class="page-subtitle">Registro de facturación y recaudación por negocio.</p>
        </div>

        <!-- TOTALES RÁPIDOS -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px">
            <div class="total-card">
                <div>
                    <div class="total-card-label">Recaudación Total</div>
                    <div class="total-card-val">$<?php echo number_format($total_ingresos,2); ?></div>
                </div>
                <div style="width:44px;height:44px;background:#f0fdf4;border-radius:.75rem;display:flex;align-items:center;justify-content:center;color:#16a34a;font-size:18px">
                    <i class="fas fa-dollar-sign"></i>
                </div>
            </div>
            <div class="total-card">
                <div>
                    <div class="total-card-label">Este Mes</div>
                    <div class="total-card-val">$<?php echo number_format($ingresos_mes,2); ?></div>
                </div>
                <div style="width:44px;height:44px;background:#eff6ff;border-radius:.75rem;display:flex;align-items:center;justify-content:center;color:#2563eb;font-size:18px">
                    <i class="fas fa-calendar"></i>
                </div>
            </div>
            <div class="total-card">
                <div>
                    <div class="total-card-label">Total Pagos</div>
                    <div class="total-card-val"><?php echo count($pagos_arr); ?></div>
                </div>
                <div style="width:44px;height:44px;background:#fdf4ff;border-radius:.75rem;display:flex;align-items:center;justify-content:center;color:#7e22ce;font-size:18px">
                    <i class="fas fa-receipt"></i>
                </div>
            </div>
            <div class="total-card">
                <div>
                    <div class="total-card-label">Prom. por Pago</div>
                    <div class="total-card-val">$<?php echo count($pagos_arr)>0?number_format($total_ingresos/count($pagos_arr),2):'0.00'; ?></div>
                </div>
                <div style="width:44px;height:44px;background:#fffbeb;border-radius:.75rem;display:flex;align-items:center;justify-content:center;color:#b49363;font-size:18px">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
        </div>

        <!-- RECAUDACIÓN POR NEGOCIO -->
        <div class="card">
            <div class="card-header">
                <h3>Expediente por Negocio</h3>
                <small>Recaudación total e historial por sucursal</small>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <th>Negocio / Sede</th>
                        <th>Plan Activo</th>
                        <th>Precio/mes</th>
                        <th>Recaudación Total</th>
                        <th>Estado</th>
                        <th style="text-align:right">Acción</th>
                    </tr></thead>
                    <tbody>
                    <?php
                    $exp = $conn->query("SELECT s.*, sub.plan_id, sub.fecha_vencimiento, sub.estado as sub_estado,
                        pl.nombre as plan_nombre, pl.precio_mensual,
                        COALESCE(SUM(p.monto),0) as total_pagado
                        FROM sucursales s
                        LEFT JOIN suscripciones sub ON sub.sucursal_id = s.id
                        LEFT JOIN planes pl ON pl.id = sub.plan_id
                        LEFT JOIN pagos_suscripcion p ON p.sucursal_id = s.id
                        GROUP BY s.id
                        ORDER BY s.nombre");
                    if ($exp && $exp->num_rows > 0):
                        $pi2=0;
                        while ($e = $exp->fetch_assoc()):
                            $hoy = new DateTime();
                            $vence = $e['fecha_vencimiento'] ? new DateTime($e['fecha_vencimiento']) : null;
                            $vencida = $vence && $vence < $hoy;
                            $pc = $plan_colors[$pi2%3]; $pi2++;
                    ?>
                    <tr>
                        <td>
                            <div style="font-weight:900;font-size:13px;color:#0f172a"><?php echo htmlspecialchars($e['nombre']); ?></div>
                        </td>
                        <td>
                            <?php if($e['plan_nombre']): ?>
                            <span class="plan-badge" style="background:<?php echo $pc['bg']; ?>;color:<?php echo $pc['color']; ?>;border-color:<?php echo $pc['border']; ?>">
                                <?php echo htmlspecialchars($e['plan_nombre']); ?>
                            </span>
                            <?php else: ?>
                            <span style="font-size:11px;color:#94a3b8;font-style:italic">Sin plan</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-family:Monaco,monospace;font-size:13px">
                            <?php echo $e['precio_mensual'] ? '$'.number_format($e['precio_mensual'],2) : '—'; ?>
                        </td>
                        <td style="font-family:Monaco,monospace;font-weight:900;color:#0f172a;font-size:14px">
                            $<?php echo number_format($e['total_pagado'],2); ?>
                        </td>
                        <td>
                            <?php if(!$e['plan_nombre']): ?>
                                <span class="status-pill suspended"><span class="dot"></span>Sin plan</span>
                            <?php elseif($vencida): ?>
                                <span class="status-pill expired"><span class="dot"></span>Vencida</span>
                            <?php else: ?>
                                <span class="status-pill active"><span class="dot"></span>Activa</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right">
                            <a href="superadmin.php?page=estadisticas" class="action-btn">
                                <i class="fas fa-eye"></i> Ver detalle
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8">Sin sucursales registradas</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- REGISTRAR PAGO + HISTORIAL -->
        <div class="grid-2" style="margin-top:24px">
            <div class="card" style="align-self:start;margin-bottom:0">
                <div class="card-header"><h3>Registrar Pago</h3></div>
                <div class="card-body">
                    <form action="../backend/processing/superadmin.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                        <input type="hidden" name="action" value="add_pago">
                        <div class="form-group">
                            <label class="form-label">Negocio / Sucursal</label>
                            <select name="pago_sucursal" class="form-select" required>
                                <option value="">— Seleccionar —</option>
                                <?php foreach($sucursales_arr as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Plan (opcional)</label>
                            <select name="pago_plan" class="form-select">
                                <option value="">— Sin plan específico —</option>
                                <?php foreach($planes_arr as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nombre']); ?> — $<?php echo number_format($p['precio_mensual'],2); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Monto ($)</label>
                                <input type="number" name="pago_monto" class="form-input" placeholder="0.00" step="0.01" min="0" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Fecha</label>
                                <input type="date" name="pago_fecha" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Método de Pago</label>
                            <select name="pago_metodo" class="form-select" required>
                                <option>Zelle</option>
                                <option>Efectivo</option>
                                <option>Transferencia</option>
                                <option>PayPal</option>
                                <option>Otro</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Referencia</label>
                            <input type="text" name="pago_referencia" class="form-input" placeholder="N° de transacción">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Notas</label>
                            <textarea name="pago_notas" class="form-textarea" placeholder="Observaciones adicionales" style="min-height:56px"></textarea>
                        </div>
                        <button type="submit" class="btn btn-dark btn-full">
                            <i class="fas fa-plus"></i> Registrar Pago
                        </button>
                    </form>
                </div>
            </div>

            <div class="card" style="margin-bottom:0">
                <div class="card-header">
                    <h3>Historial de Pagos</h3>
                    <small><?php echo count($pagos_arr); ?> registros</small>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr>
                            <th>Fecha</th><th>Negocio</th><th>Plan</th>
                            <th>Monto</th><th>Método</th><th></th>
                        </tr></thead>
                        <tbody>
                        <?php if(empty($pagos_arr)): ?>
                        <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8">Sin pagos registrados</td></tr>
                        <?php else: foreach($pagos_arr as $pg):
                            $metodos = ['Zelle'=>['#eff6ff','#1d4ed8'],'Efectivo'=>['#f0fdf4','#15803d'],'Transferencia'=>['#fdf4ff','#7e22ce'],'PayPal'=>['#eff6ff','#1d4ed8'],'Otro'=>['#f8fafc','#64748b']];
                            $mc = $metodos[$pg['metodo']] ?? ['#f8fafc','#64748b'];
                        ?>
                        <tr>
                            <td style="font-size:12px;white-space:nowrap"><?php echo date('d/m/Y', strtotime($pg['fecha_pago'])); ?></td>
                            <td style="font-weight:800;font-size:12px"><?php echo htmlspecialchars($pg['suc_nombre']??'—'); ?></td>
                            <td style="font-size:11px;color:#64748b"><?php echo htmlspecialchars($pg['plan_nombre']??'—'); ?></td>
                            <td style="font-family:Monaco,monospace;font-weight:900;color:#166534;font-size:13px">$<?php echo number_format($pg['monto'],2); ?></td>
                            <td>
                                <span style="background:<?php echo $mc[0]; ?>;color:<?php echo $mc[1]; ?>;padding:3px 8px;border-radius:.5rem;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.05em">
                                    <?php echo htmlspecialchars($pg['metodo']); ?>
                                </span>
                            </td>
                            <td>
                                <form action="../backend/processing/superadmin.php" method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar este pago?')">
                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                    <input type="hidden" name="action" value="delete_pago">
                                    <input type="hidden" name="id" value="<?php echo $pg['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ══════════════ SETTINGS ══════════════ -->
        <?php elseif($page == 'settings'): ?>

        <div class="page-header">
            <h1 class="page-title">Configuración <span>Global</span></h1>
            <p class="page-subtitle">Parámetros globales del sistema.</p>
        </div>

        <div class="card" style="max-width:640px">
            <div class="card-header"><h3>Ajustes del Sistema</h3></div>
            <div class="card-body">
                <form action="../backend/processing/superadmin.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                    <input type="hidden" name="action" value="save_settings">
                    <div class="form-group">
                        <label class="form-label">Nombre del Negocio</label>
                        <input type="text" name="nombre_negocio" class="form-input" value="<?php echo htmlspecialchars($config['nombre_negocio'] ?? 'AlCorte Pro'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email Zelle Global</label>
                        <input type="email" name="zelle_email" class="form-input" value="<?php echo htmlspecialchars($config['zelle_email'] ?? ''); ?>" placeholder="correo@ejemplo.com">
                    </div>
                    <div style="margin-top:20px">
                        <button type="submit" class="btn btn-dark"><i class="fas fa-save"></i> Guardar Configuración</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /content -->
</div><!-- /main -->
</div><!-- /layout -->

<script>
const btn = document.getElementById('menuBtn');
if(btn && window.innerWidth<=640) btn.style.display='block';
</script>
</body>
</html>
