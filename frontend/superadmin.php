<?php
require_once '../backend/config/config.php';

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'superadmin') {
    header("Location: ../");
    exit;
}

$page = $_GET['page'] ?? 'dashboard';
$msg  = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';

// === KPIs MACRO ===
$total_citas      = $conn->query("SELECT COUNT(*) as t FROM citas")->fetch_assoc()['t'];
$total_sucursales = $conn->query("SELECT COUNT(*) as t FROM sucursales")->fetch_assoc()['t'];
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
        GROUP BY DATE_FORMAT(fecha_pago,'%Y-%m'), DATE_FORMAT(fecha_pago,'%b %Y') ORDER BY DATE_FORMAT(fecha_pago,'%Y-%m') ASC");
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

// === USUARIOS ===
$edit_user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$edit_user = null;
if ($page == 'usuarios' && $edit_user_id) {
    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $edit_user_id);
    $stmt->execute();
    $edit_user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// === CONFIG ===
$config = [];
$r = $conn->query("SELECT * FROM configuracion");
if ($r) while ($row = $r->fetch_assoc()) $config[$row['clave']] = $row['valor'];

// === SUCURSALES ===
$sucursales_arr = [];
$r = @$conn->query("SELECT id, nombre, direccion, token FROM sucursales ORDER BY id ASC");
if (!$r) $r = $conn->query("SELECT id, nombre, direccion, '' AS token FROM sucursales ORDER BY id ASC");
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
        .mobile-menu-fab{
            position:fixed;top:14px;left:14px;z-index:900;display:none;align-items:center;justify-content:center;
            width:42px;height:42px;background:#0f172a;color:#fff;border:none;border-radius:10px;
            box-shadow:0 4px 12px rgba(0,0,0,.2);cursor:pointer;font-size:18px
        }
        .msg-pill{font-size:11px;font-weight:800;padding:6px 14px;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;border-radius:2rem;letter-spacing:.03em}
        .setup-btn{font-size:11px;font-weight:900;padding:8px 16px;background:#7c3aed;color:white;border-radius:.75rem;text-decoration:none;text-transform:uppercase;letter-spacing:.08em;transition:all .15s;display:inline-flex;align-items:center;gap:6px}
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
        .plan-dark-card{background:#0f172a;border-radius:1rem;padding:16px 18px;color:white;position:relative;overflow:hidden}
        .plan-dark-card::before{content:'';position:absolute;top:-30px;right:-30px;width:120px;height:120px;background:radial-gradient(circle,rgba(180,147,99,.15) 0%,transparent 70%);border-radius:50%}
        .plan-dark-name{font-size:1.2rem;font-weight:900;font-style:italic;letter-spacing:-.02em;color:white;line-height:1;margin-bottom:4px}
        .plan-dark-price{font-size:1.5rem;font-weight:900;color:#b49363;font-family:Monaco,monospace;line-height:1}
        .plan-dark-price span{font-size:11px;color:#64748b;font-family:Inter,sans-serif;font-weight:500}
        .plan-dark-features{margin-top:10px;padding-top:10px;border-top:1px solid rgba(255,255,255,.08)}
        .plan-dark-feature{font-size:11px;color:rgba(255,255,255,.55);padding:2px 0;display:flex;align-items:center;gap:6px}
        .plan-dark-feature i{color:#b49363;font-size:9px;flex-shrink:0}
        .plan-dark-actions{position:absolute;top:10px;right:10px;display:flex;gap:4px}
        .planes-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}

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

        .shops-layout{display:grid;grid-template-columns:320px 1fr;gap:24px;align-items:start}
        .shop-plan-stats{display:grid;grid-template-columns:1fr 1fr;gap:12px}

        @media(max-width:900px){
            .sidebar{position:fixed;left:-248px;transition:all .3s ease;z-index:200}
            .sidebar.open{left:0}
            .main{margin-left:0}
            .grid-2,.grid-equal,.grid-3,.shops-layout{grid-template-columns:1fr}
            .shop-plan-stats{grid-template-columns:1fr}
            .form-row{grid-template-columns:1fr}
            .content{padding:16px}
            .mobile-menu-fab{display:inline-flex !important}
            .sidebar-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);z-index:150}
            .sidebar-overlay.active{display:block}
        }
        .shops-cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}
    </style>
</head>
<body>
<div class="layout">
<button type="button" class="mobile-menu-fab" id="menuBtn" aria-label="Abrir menú"><i class="fas fa-bars"></i></button>

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
        <div class="nav-section">Tiendas</div>
        <a href="superadmin.php?page=barbershops" class="nav-item <?php echo $page=='barbershops'?'active':''; ?>">
            <i class="fas fa-store"></i><span>Tiendas</span>
        </a>
        <a href="superadmin.php?page=planes" class="nav-item <?php echo $page=='planes'?'active':''; ?>">
            <i class="fas fa-layer-group"></i><span>Planes</span>
        </a>
        <a href="superadmin.php?page=pagos" class="nav-item <?php echo $page=='pagos'?'active':''; ?>">
            <i class="fas fa-credit-card"></i><span>Pagos</span>
        </a>
        <div class="nav-section">Admin</div>
        <a href="superadmin.php?page=usuarios" class="nav-item <?php echo $page=='usuarios'?'active':''; ?>">
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
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ══ MAIN ══ -->
<div class="main">
    <div class="content">

        <?php if ($msg || !$setup_ok): ?>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px">
            <?php if ($msg): ?><span class="msg-pill">✓ <?php echo $msg; ?></span><?php endif; ?>
            <?php if (!$setup_ok): ?>
                <a href="../backend/database/setup.php" class="setup-btn"><i class="fas fa-database"></i> Inicializar BD</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

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
            <p class="page-subtitle">Monitoreo global de tiendas, rendimiento y facturación.</p>
        </div>

        <div class="kpi-grid">
            <div class="stat-card blue">
                <div class="stat-label">Total Citas</div>
                <div class="stat-value"><?php echo number_format($total_citas); ?></div>
            </div>
            <div class="stat-card gold">
                <div class="stat-label">Tiendas</div>
                <div class="stat-value"><?php echo $total_sucursales; ?></div>
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
            <p class="page-subtitle">Seguimiento de planes activos, vencimientos y recaudación por tienda.</p>
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

        <div class="card" style="margin-bottom:24px">
            <div class="card-header"><h3>Ingresos por Mes</h3></div>
            <div class="card-body"><div style="height:220px"><canvas id="chartBar"></canvas></div></div>
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
        <?php elseif($page == 'barbershops'):
            $admins_map = [];
            $r = $conn->query("SELECT id, nombre, usuario, telefono, sucursal_id FROM usuarios WHERE rol='admin' AND sucursal_id > 0");
            if ($r) while ($row = $r->fetch_assoc()) $admins_map[$row['sucursal_id']] = $row;

            // Data enriquecida por tienda
            $shop_data = [];
            foreach ($sucursales_arr as $s) {
                $sid = $s['id'];
                // Barberos activos
                $bc = $conn->prepare("SELECT COUNT(*) as t FROM barberos WHERE sucursal_id = ? AND activo = 1");
                $bc->bind_param("i", $sid); $bc->execute();
                $s['barberos_activos'] = $bc->get_result()->fetch_assoc()['t']; $bc->close();
                // Citas este mes
                $cc = $conn->prepare("SELECT COUNT(*) as t FROM citas WHERE sucursal_id = ? AND MONTH(fecha)=MONTH(NOW()) AND YEAR(fecha)=YEAR(NOW())");
                $cc->bind_param("i", $sid); $cc->execute();
                $s['citas_mes'] = $cc->get_result()->fetch_assoc()['t']; $cc->close();
                // Citas hoy
                $ch = $conn->prepare("SELECT COUNT(*) as t FROM citas WHERE sucursal_id = ? AND fecha = CURDATE()");
                $ch->bind_param("i", $sid); $ch->execute();
                $s['citas_hoy'] = $ch->get_result()->fetch_assoc()['t']; $ch->close();
                // Servicios activos
                $sc = $conn->prepare("SELECT COUNT(*) as t FROM servicios WHERE sucursal_id = ? AND activo = 1");
                $sc->bind_param("i", $sid); $sc->execute();
                $s['servicios_activos'] = $sc->get_result()->fetch_assoc()['t']; $sc->close();
                // Suscripción + plan
                $sub = $conn->prepare("SELECT su.*, p.nombre as plan_nombre, p.precio_mensual, p.max_barberos
                    FROM suscripciones su JOIN planes p ON su.plan_id = p.id
                    WHERE su.sucursal_id = ? ORDER BY su.id DESC LIMIT 1");
                $sub->bind_param("i", $sid); $sub->execute();
                $s['sub'] = $sub->get_result()->fetch_assoc(); $sub->close();
                $shop_data[] = $s;
            }
            $proto_sa = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $base_sa  = $proto_sa . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']);
        ?>

        <div class="page-header">
            <h1 class="page-title">Gestión de <span>Tiendas</span></h1>
            <p class="page-subtitle">Registro, suscripciones y estado operativo de cada tienda.</p>
        </div>

        <!-- KPIs TIENDAS -->
        <?php
            $total_shops = count($shop_data);
            $shops_con_plan = 0; $shops_activas = 0; $shops_vencidas = 0; $shops_sin_plan = 0;
            foreach ($shop_data as $sd) {
                if (!$sd['sub']) { $shops_sin_plan++; continue; }
                $shops_con_plan++;
                $vence = new DateTime($sd['sub']['fecha_vencimiento']);
                if ($sd['sub']['estado'] === 'activo' && $vence >= new DateTime()) $shops_activas++;
                else $shops_vencidas++;
            }
        ?>
        <div class="kpi-grid" style="margin-bottom:24px">
            <div class="stat-card gold">
                <div class="stat-label">Total Tiendas</div>
                <div class="stat-value"><?php echo $total_shops; ?></div>
            </div>
            <div class="stat-card green">
                <div class="stat-label">Plan Activo</div>
                <div class="stat-value"><?php echo $shops_activas; ?></div>
            </div>
            <div class="stat-card rose">
                <div class="stat-label">Vencidas</div>
                <div class="stat-value"><?php echo $shops_vencidas; ?></div>
            </div>
            <div class="stat-card blue">
                <div class="stat-label">Sin Plan</div>
                <div class="stat-value"><?php echo $shops_sin_plan; ?></div>
            </div>
        </div>

        <!-- BOTÓN DE CONTROL -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px">
            <h3 style="font-size:12px; font-weight:900; text-transform:uppercase; letter-spacing:.15em; color:#0f172a; margin:0">Listado de Sucursales</h3>
            <button id="toggleFormBtn" onclick="toggleShopForm()" class="btn btn-gold"><i class="fas fa-plus"></i> Nueva Tienda</button>
        </div>

        <!-- FORMULARIO COLAPSABLE (Oculto por defecto) -->
        <div id="suc-form-container" style="display:none; margin-bottom:24px; max-width: 500px;">
            <div class="card" style="margin-bottom:0">
                <div class="card-header" id="suc-form-title"><h3>Registrar Tienda</h3></div>
                <div class="card-body">
                    <form action="../backend/processing/superadmin.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                        <input type="hidden" id="shop_action" name="action" value="add_sucursal">
                        <input type="hidden" id="shop_id" name="id" value="">
                        <div class="form-group">
                            <label class="form-label">Nombre Comercial</label>
                            <input type="text" id="shop_name" name="shop_name" class="form-input" placeholder="Ej. Tienda Norte" required>
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
        </div>

        <!-- LISTA DE TIENDAS -->
        <div style="display:flex;flex-direction:column;gap:16px">
                <?php if(empty($shop_data)): ?>
                <div class="card" style="margin-bottom:0">
                    <div style="text-align:center;padding:60px;color:#94a3b8">
                        <i class="fas fa-store" style="font-size:40px;margin-bottom:12px;display:block;opacity:.4"></i>
                        <p style="font-weight:700">Sin tiendas registradas.</p>
                        <p style="font-size:13px;margin-top:4px">Crea la primera con el formulario.</p>
                    </div>
                </div>
                <?php endif; ?>

                <?php foreach($shop_data as $s):
                    $adm = $admins_map[$s['id']] ?? null;
                    $url_cliente = $base_sa . '/cliente.php?t=' . ($s['token'] ?? '');
                    $sub = $s['sub'];
                    $hoy_dt = new DateTime();

                    // Estado de suscripción
                    if (!$sub) {
                        $sub_status = 'none'; $sub_label = 'Sin plan'; $sub_color = '#64748b'; $sub_bg = '#f8fafc';
                    } elseif ($sub['estado'] === 'suspendido') {
                        $sub_status = 'suspended'; $sub_label = 'Suspendida'; $sub_color = '#d97706'; $sub_bg = '#fffbeb';
                    } elseif ($sub['estado'] !== 'activo' || new DateTime($sub['fecha_vencimiento']) < $hoy_dt) {
                        $sub_status = 'expired'; $sub_label = 'Vencida'; $sub_color = '#dc2626'; $sub_bg = '#fef2f2';
                    } else {
                        $sub_status = 'active'; $sub_label = 'Activa'; $sub_color = '#16a34a'; $sub_bg = '#f0fdf4';
                    }

                    // Días restantes
                    $dias_rest = '';
                    if ($sub && $sub_status === 'active') {
                        $diff = $hoy_dt->diff(new DateTime($sub['fecha_vencimiento']))->days;
                        $dias_rest = $diff . ' día' . ($diff!=1?'s':'');
                    }

                    // Uso de barberos
                    $barb_uso = $s['barberos_activos'];
                    $barb_max = $sub ? intval($sub['max_barberos']) : 0;
                    $barb_txt = $barb_max > 0 ? "$barb_uso / $barb_max" : ($barb_max === 0 && $sub ? "$barb_uso / ∞" : "$barb_uso");
                ?>
                <div class="card" style="margin-bottom:0;overflow:visible">
                    <!-- HEADER DE TIENDA -->
                    <div style="padding:18px 20px;display:flex;align-items:flex-start;gap:16px;border-bottom:1px solid #f1f5f9">
                        <!-- Icono -->
                        <div style="width:48px;height:48px;border-radius:14px;background:<?php echo $sub_status==='active'?'#0f172a':($sub_status==='expired'?'#fef2f2':'#f8fafc'); ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                            <i class="fas fa-store" style="font-size:18px;color:<?php echo $sub_status==='active'?'#b49363':($sub_status==='expired'?'#ef4444':'#94a3b8'); ?>"></i>
                        </div>
                        <!-- Info -->
                        <div style="flex:1;min-width:0">
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                                <span style="font-size:16px;font-weight:900;color:#0f172a"><?php echo htmlspecialchars($s['nombre']); ?></span>
                                <?php if ($sub): ?>
                                <span style="font-size:10px;font-weight:900;padding:3px 10px;border-radius:2rem;background:<?php echo $sub_bg; ?>;color:<?php echo $sub_color; ?>;text-transform:uppercase;letter-spacing:.08em;display:inline-flex;align-items:center;gap:4px">
                                    <span style="width:6px;height:6px;border-radius:50%;background:<?php echo $sub_color; ?>;<?php echo $sub_status==='active'?'animation:pulse 1.5s infinite':''; ?>"></span>
                                    <?php echo $sub_label; ?>
                                </span>
                                <?php else: ?>
                                <span style="font-size:10px;font-weight:900;padding:3px 10px;border-radius:2rem;background:#f8fafc;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em">Sin plan</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($s['direccion']): ?>
                            <div style="font-size:12px;color:#64748b;margin-top:3px"><i class="fas fa-location-dot" style="margin-right:4px;font-size:10px;color:#94a3b8"></i><?php echo htmlspecialchars($s['direccion']); ?></div>
                            <?php endif; ?>
                        </div>
                        <!-- Acciones rápidas -->
                        <div style="display:flex;gap:5px;flex-shrink:0">
                            <button onclick="editSuc(<?php echo $s['id']; ?>,'<?php echo htmlspecialchars($s['nombre'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($s['direccion']??'',ENT_QUOTES); ?>')" class="btn btn-ghost btn-sm" title="Editar tienda"><i class="fas fa-pen"></i></button>
                            <form action="../backend/processing/superadmin.php" method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar esta tienda y toda su data?')">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                <input type="hidden" name="action" value="delete_sucursal">
                                <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm" title="Eliminar"><i class="fas fa-trash"></i></button>
                            </form>
                        </div>
                    </div>

                    <!-- PLAN + STATS -->
                    <div style="padding:16px 20px" class="shop-plan-stats">
                        <!-- Plan info -->
                        <div style="display:flex;flex-direction:column;gap:10px">
                            <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.15em;color:#94a3b8">Suscripción</div>
                            <?php if ($sub): ?>
                            <div style="background:#0f172a;border-radius:.75rem;padding:12px 14px;color:white">
                                <div style="display:flex;align-items:baseline;gap:6px">
                                    <span style="font-size:15px;font-weight:900;font-style:italic"><?php echo htmlspecialchars($sub['plan_nombre']); ?></span>
                                    <span style="font-size:12px;color:#b49363;font-weight:700">$<?php echo number_format($sub['precio_mensual'],2); ?>/mes</span>
                                </div>
                                <div style="margin-top:8px;display:flex;flex-direction:column;gap:3px">
                                    <div style="font-size:11px;color:rgba(255,255,255,.5);display:flex;justify-content:space-between">
                                        <span><i class="fas fa-calendar-alt" style="margin-right:4px"></i>Inicio</span>
                                        <span style="color:rgba(255,255,255,.7)"><?php echo date('d/m/Y', strtotime($sub['fecha_inicio'])); ?></span>
                                    </div>
                                    <div style="font-size:11px;color:rgba(255,255,255,.5);display:flex;justify-content:space-between">
                                        <span><i class="fas fa-clock" style="margin-right:4px"></i>Vence</span>
                                        <span style="color:<?php echo $sub_status==='active'?'rgba(255,255,255,.7)':'#fca5a5'; ?>"><?php echo date('d/m/Y', strtotime($sub['fecha_vencimiento'])); ?></span>
                                    </div>
                                    <?php if ($dias_rest): ?>
                                    <div style="font-size:11px;color:#b49363;font-weight:700;text-align:right;margin-top:2px"><?php echo $dias_rest; ?> restantes</div>
                                    <?php endif; ?>
                                </div>
                                <button onclick="openPlanForm(<?php echo $s['id']; ?>,'<?php echo htmlspecialchars($s['nombre'],ENT_QUOTES); ?>',<?php echo $sub['plan_id']; ?>,'<?php echo htmlspecialchars($sub['plan_nombre'],ENT_QUOTES); ?>')" style="width:100%;margin-top:8px;padding:6px;background:rgba(180,147,99,.2);border:none;border-radius:.5rem;font-size:10px;font-weight:800;color:#b49363;cursor:pointer;text-transform:uppercase;letter-spacing:.08em">
                                    <i class="fas fa-sync-alt" style="margin-right:4px"></i> Renovar / Cambiar
                                </button>
                            </div>
                            <?php else: ?>
                            <div style="background:#f8fafc;border:1.5px dashed #e2e8f0;border-radius:.75rem;padding:14px;text-align:center;color:#94a3b8">
                                <i class="fas fa-layer-group" style="font-size:18px;margin-bottom:6px;display:block;opacity:.5"></i>
                                <div style="font-size:12px;font-weight:700">Sin suscripción</div>
                                <button onclick="openPlanForm(<?php echo $s['id']; ?>,'<?php echo htmlspecialchars($s['nombre'],ENT_QUOTES); ?>',0,'')" class="btn btn-dark btn-sm" style="margin-top:8px;width:100%;justify-content:center"><i class="fas fa-plus"></i> Asignar plan</button>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Stats grid -->
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:.625rem;padding:10px 12px;text-align:center">
                                <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8">Barberos</div>
                                <div style="font-size:18px;font-weight:900;color:#0f172a;margin-top:2px"><?php echo $barb_txt; ?></div>
                                <?php if ($barb_max > 0 && $barb_uso >= $barb_max): ?>
                                <div style="font-size:9px;color:#dc2626;font-weight:700;margin-top:2px">Límite</div>
                                <?php endif; ?>
                            </div>
                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:.625rem;padding:10px 12px;text-align:center">
                                <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8">Servicios</div>
                                <div style="font-size:18px;font-weight:900;color:#0f172a;margin-top:2px"><?php echo $s['servicios_activos']; ?></div>
                            </div>
                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:.625rem;padding:10px 12px;text-align:center">
                                <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8">Citas Hoy</div>
                                <div style="font-size:18px;font-weight:900;color:#0f172a;margin-top:2px"><?php echo $s['citas_hoy']; ?></div>
                            </div>
                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:.625rem;padding:10px 12px;text-align:center">
                                <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8">Citas/Mes</div>
                                <div style="font-size:18px;font-weight:900;color:#0f172a;margin-top:2px"><?php echo $s['citas_mes']; ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- ADMIN + ENLACE -->
                    <div style="padding:0 20px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                        <!-- Admin -->
                        <div style="display:flex;align-items:center;gap:8px;flex:1;min-width:160px">
                            <?php if ($adm): ?>
                            <div style="width:32px;height:32px;border-radius:50%;background:#e0e7ff;color:#4f46e5;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0">
                                <?php echo strtoupper(mb_substr($adm['nombre'],0,2)); ?>
                            </div>
                            <div>
                                <div style="font-size:12px;font-weight:800;color:#0f172a"><?php echo htmlspecialchars($adm['nombre']); ?></div>
                                <div style="font-size:10px;color:#94a3b8;font-family:monospace">@<?php echo htmlspecialchars($adm['usuario']); ?></div>
                            </div>
                            <?php else: ?>
                            <div style="width:32px;height:32px;border-radius:50%;background:#f1f5f9;color:#94a3b8;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0">
                                <i class="fas fa-user-slash"></i>
                            </div>
                            <span style="font-size:12px;color:#94a3b8;font-style:italic">Sin admin asignado</span>
                            <?php endif; ?>
                        </div>

                        <!-- Botones admin -->
                        <button onclick="openAdmForm(<?php echo $s['id']; ?>,'<?php echo htmlspecialchars($s['nombre'],ENT_QUOTES); ?>',<?php echo $adm ? $adm['id'] : 0; ?>,'<?php echo htmlspecialchars($adm['nombre']??'',ENT_QUOTES); ?>','<?php echo htmlspecialchars($adm['usuario']??'',ENT_QUOTES); ?>','<?php echo htmlspecialchars($adm['telefono']??'',ENT_QUOTES); ?>')"
                            class="btn btn-sm" style="background:<?php echo $adm?'#e0e7ff':'#f0fdf4'; ?>;color:<?php echo $adm?'#4f46e5':'#16a34a'; ?>;border:none;cursor:pointer">
                            <i class="fas fa-<?php echo $adm?'user-pen':'user-plus'; ?>"></i> <?php echo $adm?'Editar admin':'Asignar admin'; ?>
                        </button>
                        <?php if ($adm): ?>
                        <form action="../backend/processing/superadmin.php" method="POST" style="display:inline" onsubmit="return confirm('¿Quitar el admin?')">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                            <input type="hidden" name="action" value="delete_admin">
                            <input type="hidden" name="adm_id" value="<?php echo $adm['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm" title="Quitar admin"><i class="fas fa-user-xmark"></i></button>
                        </form>
                        <?php endif; ?>

                        <!-- Enlaces -->
                        <div style="margin-left:auto;display:flex;align-items:center;gap:5px">
                            <a href="admin.php?suc=<?php echo $s['id']; ?>" class="btn btn-sm" style="background:#b49363;color:white;border:none;text-decoration:none" title="Ver panel admin de esta tienda">
                                <i class="fas fa-columns"></i> Panel
                            </a>
                            <?php if (!empty($s['token'])): ?>
                            <button onclick="copyUrl('<?php echo htmlspecialchars($url_cliente, ENT_QUOTES); ?>', this)" title="Copiar enlace de reservas"
                                style="background:#0f172a;border:none;border-radius:6px;padding:5px 10px;cursor:pointer;font-size:10px;font-weight:800;color:white;display:flex;align-items:center;gap:4px">
                                <i class="fas fa-link"></i> Reservas
                            </button>
                            <a href="<?php echo htmlspecialchars($url_cliente); ?>" target="_blank"
                                style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:5px 8px;font-size:10px;font-weight:700;color:#2563eb;text-decoration:none;display:flex;align-items:center">
                                <i class="fas fa-external-link-alt"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        <!-- FORMULARIO ADMIN (aparece al pulsar + Admin o Editar admin) -->
        <div id="admFormWrap" style="display:none;margin-top:24px;">
            <div class="card" style="max-width:480px">
                <div class="card-header"><h3 id="admFormTitle">Asignar Administrador</h3></div>
                <div class="card-body">
                    <form action="../backend/processing/superadmin.php" method="POST">
                        <input type="hidden" name="csrf_token"   value="<?php echo csrf_generate(); ?>">
                        <input type="hidden" id="af_action"      name="action"       value="add_admin">
                        <input type="hidden" id="af_id"          name="adm_id"       value="">
                        <input type="hidden" id="af_sucursal"    name="adm_sucursal" value="">
                        <div class="form-group">
                            <label class="form-label">Tienda</label>
                            <input type="text" id="af_tienda_lbl" class="form-input" disabled style="background:#f9fafb;color:#6b7280">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Nombre completo</label>
                            <input type="text" id="af_nombre" name="adm_nombre" class="form-input" placeholder="Ej. Juan Pérez" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Usuario</label>
                            <input type="text" id="af_usuario" name="adm_usuario" class="form-input" placeholder="Ej. juanp" autocomplete="off" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" id="af_pass_lbl">Contraseña</label>
                            <input type="password" id="af_password" name="adm_password" class="form-input" placeholder="Mín. 8 caracteres" autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Teléfono</label>
                            <input type="text" id="af_telefono" name="adm_telefono" class="form-input" placeholder="Opcional">
                        </div>
                        <div style="display:flex;gap:8px">
                            <button type="submit" class="btn btn-gold" style="flex:1;justify-content:center"><i class="fas fa-save"></i> Guardar</button>
                            <button type="button" onclick="closeAdmForm()" class="btn btn-ghost">Cancelar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- MODAL ASIGNAR/RENOVAR PLAN -->
        <div id="planFormWrap" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);align-items:center;justify-content:center">
            <div style="background:#fff;border-radius:1rem;padding:28px;width:100%;max-width:420px;margin:16px;box-shadow:0 20px 60px rgba(0,0,0,.2);position:relative;max-height:90vh;overflow-y:auto">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
                    <h3 id="planFormTitle" style="font-size:15px;font-weight:800;color:#0f172a">Asignar Plan</h3>
                    <button onclick="closePlanForm()" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:18px;padding:4px"><i class="fas fa-times"></i></button>
                </div>
                <form action="../backend/processing/superadmin.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                    <input type="hidden" name="action" value="assign_plan">
                    <input type="hidden" id="pf_sucursal" name="sub_sucursal" value="">
                    <div class="form-group">
                        <label class="form-label">Tienda</label>
                        <input type="text" id="pf_tienda_lbl" class="form-input" disabled style="background:#f9fafb;color:#6b7280">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Plan</label>
                        <select id="pf_plan" name="sub_plan" class="form-select" required>
                            <option value="">— Seleccionar plan —</option>
                            <?php foreach($planes_arr as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nombre']); ?> — $<?php echo number_format($p['precio_mensual'],2); ?>/mes</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Fecha inicio</label>
                            <input type="date" name="sub_inicio" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Vencimiento</label>
                            <input type="date" name="sub_fin" class="form-input" value="<?php echo date('Y-m-d', strtotime('+1 month')); ?>" required>
                        </div>
                    </div>
                    <div style="border-top:1px solid #f1f5f9;margin-top:8px;padding-top:14px">
                        <div style="font-size:9px;font-weight:900;text-transform:uppercase;letter-spacing:.15em;color:#94a3b8;margin-bottom:10px"><i class="fas fa-credit-card" style="margin-right:4px"></i> Datos del pago</div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Método</label>
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
                                <input type="text" name="pago_referencia" class="form-input" placeholder="N° transacción">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Notas (opcional)</label>
                            <input type="text" name="pago_notas" class="form-input" placeholder="Observaciones">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-gold" style="width:100%;justify-content:center;margin-top:8px"><i class="fas fa-check"></i> Activar y registrar pago</button>
                </form>
            </div>
        </div>

        <script>
        function openPlanForm(suc_id, suc_nombre, plan_id, plan_nombre) {
            document.getElementById('pf_sucursal').value = suc_id;
            document.getElementById('pf_tienda_lbl').value = suc_nombre;
            document.getElementById('pf_plan').value = plan_id || '';
            document.getElementById('planFormTitle').textContent = plan_id ? 'Renovar Plan — ' + suc_nombre : 'Asignar Plan — ' + suc_nombre;
            document.getElementById('planFormWrap').style.display = 'flex';
        }
        function closePlanForm() { document.getElementById('planFormWrap').style.display = 'none'; }
        document.getElementById('planFormWrap').addEventListener('click', function(e) { if (e.target === this) closePlanForm(); });

        function openAdmForm(suc_id, suc_nombre, adm_id, adm_nombre, adm_usuario, adm_telefono) {
            document.getElementById('af_sucursal').value   = suc_id;
            document.getElementById('af_tienda_lbl').value = suc_nombre;
            document.getElementById('af_nombre').value     = adm_nombre;
            document.getElementById('af_usuario').value    = adm_usuario;
            document.getElementById('af_telefono').value   = adm_telefono;
            document.getElementById('af_password').value   = '';
            if (adm_id > 0) {
                document.getElementById('af_id').value = adm_id;
                document.getElementById('af_action').value = 'edit_admin';
                document.getElementById('admFormTitle').textContent = 'Editar Admin — ' + suc_nombre;
                document.getElementById('af_pass_lbl').textContent  = 'Nueva contraseña (vacío = sin cambios)';
                document.getElementById('af_password').removeAttribute('required');
            } else {
                document.getElementById('af_id').value = '';
                document.getElementById('af_action').value = 'add_admin';
                document.getElementById('admFormTitle').textContent = 'Asignar Admin — ' + suc_nombre;
                document.getElementById('af_pass_lbl').textContent  = 'Contraseña';
                document.getElementById('af_password').setAttribute('required','');
            }
            document.getElementById('admFormWrap').style.display = 'block';
            document.getElementById('admFormWrap').scrollIntoView({ behavior:'smooth', block:'start' });
        }
        function closeAdmForm() {
            document.getElementById('admFormWrap').style.display = 'none';
        }
        function copyUrl(url, btn) {
            navigator.clipboard.writeText(url).then(() => {
                const orig = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copiado';
                btn.style.background = '#16a34a';
                setTimeout(() => { btn.innerHTML = orig; btn.style.background = '#0f172a'; }, 2000);
            });
        }
        function toggleShopForm() {
            const container = document.getElementById('suc-form-container');
            const btn = document.getElementById('toggleFormBtn');
            if (container.style.display === 'none' || container.style.display === '') {
                container.style.display = 'block';
                btn.innerHTML = '<i class="fas fa-times"></i> Cerrar Formulario';
                btn.style.background = '#64748b';
            } else {
                container.style.display = 'none';
                btn.innerHTML = '<i class="fas fa-plus"></i> Nueva Tienda';
                btn.style.background = '#b49363';
                resetSucForm();
            }
        }
        function editSuc(id,nombre,dir){
            document.getElementById('shop_id').value=id;
            document.getElementById('shop_name').value=nombre;
            document.getElementById('shop_address').value=dir;
            document.getElementById('shop_action').value='edit_sucursal';
            document.querySelector('#suc-form-title h3').textContent='Editar Tienda';
            
            const container = document.getElementById('suc-form-container');
            const btn = document.getElementById('toggleFormBtn');
            container.style.display = 'block';
            btn.innerHTML = '<i class="fas fa-times"></i> Cerrar Formulario';
            btn.style.background = '#64748b';
            
            document.getElementById('shop_name').focus();
            window.scrollTo({top: container.offsetTop - 100, behavior: 'smooth'});
        }
        function resetSucForm(){
            document.getElementById('shop_id').value='';
            document.getElementById('shop_name').value='';
            document.getElementById('shop_address').value='';
            document.getElementById('shop_action').value='add_sucursal';
            document.querySelector('#suc-form-title h3').textContent='Registrar Tienda';
            
            const container = document.getElementById('suc-form-container');
            const btn = document.getElementById('toggleFormBtn');
            container.style.display = 'none';
            btn.innerHTML = '<i class="fas fa-plus"></i> Nueva Tienda';
            btn.style.background = '#b49363';
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
                        <div class="form-group">
                            <label class="form-label">Máx. Barberos</label>
                            <input type="number" id="plan_max_barberos" name="plan_max_barberos" class="form-input" value="1" min="0" required>
                            <small style="color:#94a3b8;font-size:10px">0 = ilimitado · Planes con 2+ barberos incluyen Club VIP, colores e imágenes</small>
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
                <div class="planes-grid">
                <?php foreach($planes_arr as $plan): ?>
                <div class="plan-dark-card">
                    <div class="plan-dark-actions">
                        <button onclick="editPlan(<?php echo htmlspecialchars(json_encode($plan),ENT_QUOTES); ?>)" class="btn btn-ghost btn-sm" style="background:rgba(255,255,255,.1);color:white;border-color:rgba(255,255,255,.15);padding:3px 7px">
                            <i class="fas fa-pen" style="font-size:10px"></i>
                        </button>
                        <form action="../backend/processing/superadmin.php" method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar plan?')">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                            <input type="hidden" name="action" value="delete_plan">
                            <input type="hidden" name="id" value="<?php echo $plan['id']; ?>">
                            <button type="submit" class="btn btn-sm" style="background:rgba(239,68,68,.15);color:#fca5a5;border:none;padding:3px 7px;cursor:pointer;border-radius:6px">
                                <i class="fas fa-trash" style="font-size:10px"></i>
                            </button>
                        </form>
                    </div>
                    <div class="plan-dark-name"><?php echo htmlspecialchars($plan['nombre']); ?></div>
                    <div class="plan-dark-price">$<?php echo number_format($plan['precio_mensual'],2); ?><span>/mes</span></div>
                    <div class="plan-dark-features">
                        <div class="plan-dark-feature"><i class="fas fa-scissors"></i> <?php echo $plan['max_barberos'] > 0 ? $plan['max_barberos'] . ' barbero' . ($plan['max_barberos']>1?'s':'') : 'Barberos ilimitados'; ?></div>
                        <?php
                            $nivel = intval($plan['nivel'] ?? 1);
                            $nivel_labels = [1=>'Básico', 2=>'Profesional', 3=>'Pro'];
                            $nivel_features = $nivel >= 2 ? 'VIP · Colores · Imágenes' : 'Funciones esenciales';
                        ?>
                        <div class="plan-dark-feature"><i class="fas fa-star"></i> Nivel <?php echo $nivel; ?> — <?php echo $nivel_labels[$nivel] ?? 'Básico'; ?></div>
                        <div class="plan-dark-feature"><i class="fas fa-check-circle"></i> <?php echo $nivel_features; ?></div>
                        <?php if($plan['descripcion']): ?>
                        <div class="plan-dark-feature" style="color:rgba(255,255,255,.35)"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($plan['descripcion']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <script>
        function editPlan(p){
            document.getElementById('plan_id').value=p.id;
            document.getElementById('plan_nombre').value=p.nombre;
            document.getElementById('plan_precio').value=p.precio_mensual;
            document.getElementById('plan_max_barberos').value=p.max_barberos;
            document.getElementById('plan_descripcion').value=p.descripcion||'';
            document.getElementById('plan_action').value='edit_plan';
            document.querySelector('#plan-form-title h3').textContent='Editar Plan';
            window.scrollTo({top:0,behavior:'smooth'});
        }
        function resetPlan(){
            document.getElementById('plan_id').value='';
            document.getElementById('plan_nombre').value='';
            document.getElementById('plan_precio').value='';
            document.getElementById('plan_max_barberos').value='1';
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
                <h3>Expediente por Tienda</h3>
                <small>Recaudación total e historial por sucursal</small>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <th>Tienda</th>
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
                        (SELECT COALESCE(SUM(p.monto),0) FROM pagos_suscripcion p WHERE p.sucursal_id = s.id) as total_pagado
                        FROM sucursales s
                        LEFT JOIN suscripciones sub ON sub.sucursal_id = s.id
                        LEFT JOIN planes pl ON pl.id = sub.plan_id
                        WHERE s.id > 1
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
                    <tr><td colspan="6" style="text-align:center;padding:40px;color:#94a3b8">Sin tiendas registradas</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- HISTORIAL DE PAGOS -->
        <div style="margin-top:24px">
            <div class="card" style="margin-bottom:0">
                <div class="card-header">
                    <h3>Historial de Pagos</h3>
                    <small><?php echo count($pagos_arr); ?> registros</small>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr>
                            <th>Fecha</th><th>Tienda</th><th>Plan</th>
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
        <!-- ══════════════ USUARIOS ══════════════ -->
        <?php elseif($page == 'usuarios'): ?>

        <div class="page-header">
            <h1 class="page-title">Gestión de <span>Usuarios</span></h1>
            <p class="page-subtitle">Administra los usuarios del sistema.</p>
        </div>

        <?php if($edit_user): ?>
        <div class="card" style="max-width:640px">
            <div class="card-header"><h3>Editar Usuario</h3></div>
            <div class="card-body">
                <form action="../backend/processing/usuarios.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                    <input type="hidden" name="action" value="editar_usuario">
                    <input type="hidden" name="id" value="<?php echo $edit_user['id']; ?>">
                    <div class="form-group">
                        <label class="form-label">Nombre Completo *</label>
                        <input type="text" name="nombre" value="<?php echo htmlspecialchars($edit_user['nombre']); ?>" required class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nombre de Usuario</label>
                        <input type="text" value="<?php echo htmlspecialchars($edit_user['usuario']); ?>" readonly class="form-input" style="background:#f8fafc;color:#94a3b8">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Contraseña (dejar en blanco para mantener)</label>
                        <input type="password" name="password" class="form-input">
                        <small style="color:#94a3b8;font-size:11px">Mínimo 8 caracteres</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Teléfono</label>
                        <input type="tel" name="telefono" value="<?php echo htmlspecialchars($edit_user['telefono'] ?? ''); ?>" class="form-input">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Rol *</label>
                        <select name="rol" id="urol" onchange="toggleSucursal()" required class="form-select">
                            <option value="superadmin" <?php echo $edit_user['rol']=='superadmin'?'selected':''; ?>>SuperAdmin</option>
                            <option value="admin" <?php echo $edit_user['rol']=='admin'?'selected':''; ?>>Admin</option>
                            <option value="gerente" <?php echo $edit_user['rol']=='gerente'?'selected':''; ?>>Gerente de Tienda</option>
                            <option value="barbero" <?php echo $edit_user['rol']=='barbero'?'selected':''; ?>>Barbero</option>
                            <option value="cliente" <?php echo $edit_user['rol']=='cliente'?'selected':''; ?>>Cliente</option>
                        </select>
                    </div>
                    <div id="field_sucursal_u" class="form-group" style="display:none;">
                        <label class="form-label">Tienda *</label>
                        <select name="sucursal_id" class="form-select">
                            <option value="">Selecciona tienda</option>
                            <?php foreach($sucursales_arr as $suc):
                                $sel = ($edit_user['sucursal_id'] == $suc['id']) ? 'selected' : '';
                            ?>
                            <option value="<?php echo $suc['id']; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($suc['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:flex;gap:8px;margin-top:20px">
                        <button type="submit" class="btn btn-gold"><i class="fas fa-save"></i> Actualizar</button>
                        <a href="superadmin.php?page=usuarios" class="btn btn-ghost">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
        <script>
        function toggleSucursal(){
            const r=document.getElementById('urol').value;
            document.getElementById('field_sucursal_u').style.display=['admin','gerente','barbero'].includes(r)?'block':'none';
        }
        toggleSucursal();
        </script>

        <?php else: ?>
        <div class="card">
            <div class="card-header"><h3>Listado de Usuarios</h3></div>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <th>Nombre</th>
                        <th>Usuario</th>
                        <th>Rol</th>
                        <th>Tienda</th>
                        <th style="text-align:right">Acciones</th>
                    </tr></thead>
                    <tbody>
                    <?php
                    $users = $conn->query("SELECT u.*, COALESCE(s.nombre, sb.nombre) as sucursal_nombre FROM usuarios u LEFT JOIN sucursales s ON u.sucursal_id = s.id LEFT JOIN barberos b ON u.barbero_id = b.id LEFT JOIN sucursales sb ON b.sucursal_id = sb.id ORDER BY u.rol ASC, COALESCE(s.nombre, sb.nombre) ASC, u.nombre ASC");
                    if ($users && $users->num_rows > 0):
                        while ($u = $users->fetch_assoc()):
                    ?>
                    <tr>
                        <td style="font-weight:700"><?php echo htmlspecialchars($u['nombre']); ?></td>
                        <td style="font-family:monospace;color:#64748b"><?php echo htmlspecialchars($u['usuario']); ?></td>
                        <td>
                            <?php
                            $rol_colors = ['superadmin'=>'#7c3aed','admin'=>'#2563eb','gerente'=>'#d97706','barbero'=>'#059669','cliente'=>'#64748b'];
                            $rc = $rol_colors[$u['rol']] ?? '#64748b';
                            ?>
                            <span style="font-size:10px;font-weight:900;padding:3px 8px;border-radius:2rem;background:<?php echo $rc; ?>22;color:<?php echo $rc; ?>;text-transform:uppercase;letter-spacing:.08em"><?php echo $u['rol']; ?></span>
                        </td>
                        <td style="color:#64748b"><?php echo htmlspecialchars($u['sucursal_nombre'] ?? '—'); ?></td>
                        <td style="text-align:right">
                            <a href="superadmin.php?page=usuarios&id=<?php echo $u['id']; ?>" class="action-btn"><i class="fas fa-pen"></i> Editar</a>
                            <?php if ($u['id'] != $_SESSION['user_id']): ?>
                            <form action="../backend/processing/usuarios.php" method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar este usuario?')">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                <input type="hidden" name="action" value="eliminar_usuario">
                                <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                <button type="submit" class="action-btn" style="background:#fef2f2;color:#ef4444;border-color:#fecaca"><i class="fas fa-trash"></i> Eliminar</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="5" style="text-align:center;padding:40px;color:#94a3b8">Sin usuarios registrados</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>

    </div><!-- /content -->
</div><!-- /main -->
</div><!-- /layout -->

<script>
const menuBtn = document.getElementById('menuBtn');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');

if (menuBtn && sidebar && overlay) {
    menuBtn.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
    });

    overlay.addEventListener('click', () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 900) {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        }
    });
}
</script>
</body>
</html>
