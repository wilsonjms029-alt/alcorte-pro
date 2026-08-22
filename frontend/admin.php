<?php
require_once '../backend/config/config.php';

$allowed_roles = ['admin', 'superadmin', 'gerente'];
if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], $allowed_roles)) {
    header("Location: ../index.php");
    exit;
}

$is_scoped = ($_SESSION['rol'] === 'gerente') ||
             ($_SESSION['rol'] === 'admin' && intval($_SESSION['sucursal_id'] ?? 0) > 0);
$scope_id  = $is_scoped ? intval($_SESSION['sucursal_id'] ?? 0) ?: null : null;

// Superadmin puede elegir tienda con ?suc=X
if (!$is_scoped && $_SESSION['rol'] === 'superadmin') {
    $suc_param = intval($_GET['suc'] ?? $_SESSION['admin_suc'] ?? 0);
    if ($suc_param > 0) {
        $_SESSION['admin_suc'] = $suc_param;
        $scope_id  = $suc_param;
        $is_scoped = true;
    }
}

$page = isset($_GET['page']) ? $_GET['page'] : 'citas';
$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';

// Cargar plan activo de la sucursal
$plan_activo = null;
if ($is_scoped && $scope_id) {
    $plan_activo = get_plan_sucursal($conn, $scope_id);
}
$has_club_vip       = $plan_activo ? $plan_activo['has_club_vip'] : true;
$has_custom_colors  = $plan_activo ? $plan_activo['has_custom_colors'] : true;
$has_service_images = $plan_activo ? $plan_activo['has_service_images'] : true;
$has_productos      = $plan_activo ? $plan_activo['has_productos'] : false;
$is_basic_plan      = $plan_activo && $plan_activo['max_barberos'] === 1;

// Plan Básico: auto-crear barbero vinculado al admin si no existe
if ($is_basic_plan && $is_scoped && $scope_id) {
    $chk = $conn->prepare("SELECT id FROM barberos WHERE sucursal_id = ? LIMIT 1");
    $chk->bind_param("i", $scope_id); $chk->execute();
    $mi_barbero = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$mi_barbero) {
        $admin_nombre = $_SESSION['nombre'];
        $auto_foto = 'https://ui-avatars.com/api/?background=333&color=fff&name=' . urlencode($admin_nombre);
        $ins = $conn->prepare("INSERT INTO barberos (nombre, foto_url, hora_inicio, hora_fin, almuerzo_inicio, almuerzo_fin, activo, sucursal_id) VALUES (?, ?, '09:00', '17:00', '12:00', '13:00', 1, ?)");
        $ins->bind_param("ssi", $admin_nombre, $auto_foto, $scope_id);
        $ins->execute();
        $mi_barbero = ['id' => $ins->insert_id];
        $ins->close();
    }
    $mi_barbero_id = intval($mi_barbero['id']);
    if ($page === 'personal') $page = 'mi_perfil';
}

// PROCESAR APROBACIÓN DE PAGO SEGURA (PREPARED STATEMENTS)
if (isset($_POST['verificar_id'])) {
    if (!csrf_validate()) {
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");
        header("Location: admin.php?page=citas&msg=Error+de+seguridad");
        exit;
    }
    csrf_regenerate();

    $id_cita = intval($_POST['verificar_id']);

    if ($is_scoped) {
        $check = $conn->prepare("SELECT id FROM citas WHERE id = ? AND sucursal_id = ?");
        $check->bind_param("ii", $id_cita, $scope_id);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            header("Cache-Control: no-cache, no-store, must-revalidate");
            header("Pragma: no-cache");
            header("Expires: 0");
            header("Location: admin.php?page=citas&msg=Acceso+denegado");
            exit;
        }
        $check->close();
    }

    // 1. Actualizar estado de la cita
    $stmt_up = $conn->prepare("UPDATE citas SET estado_pago = 'verificado' WHERE id = ?");
    $stmt_up->bind_param("i", $id_cita);
    $stmt_up->execute();
    $stmt_up->close();

    // 2. Obtener datos del cliente
    $stmt_sel = $conn->prepare("SELECT cliente_telefono, cliente_nombre, servicio, fecha, hora FROM citas WHERE id = ?");
    $stmt_sel->bind_param("i", $id_cita);
    $stmt_sel->execute();
    $res_c = $stmt_sel->get_result();

    $wa_redirect = '';
    if ($res_c && $cita_info = $res_c->fetch_assoc()) {
        $tel = $cita_info['cliente_telefono'];
        $nom = $cita_info['cliente_nombre'];

        // 3. Insertar o actualizar puntos
        $stmt_ins = $conn->prepare("INSERT INTO clientes (telefono, nombre, puntos, ultima_visita) VALUES (?, ?, 1, CURDATE()) ON DUPLICATE KEY UPDATE puntos = puntos + 1, ultima_visita = CURDATE()");
        $stmt_ins->bind_param("ss", $tel, $nom);
        $stmt_ins->execute();
        $stmt_ins->close();

        // 4. Preparar datos para notificación WhatsApp
        $wa_redirect = '&wa_tel=' . urlencode($tel)
            . '&wa_nom=' . urlencode($nom)
            . '&wa_svc=' . urlencode($cita_info['servicio'])
            . '&wa_fecha=' . urlencode(date('d/m/Y', strtotime($cita_info['fecha'])))
            . '&wa_hora=' . urlencode(date('h:i A', strtotime($cita_info['hora'])));
    }
    $stmt_sel->close();

    header("Cache-Control: no-cache, no-store, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");
    header("Location: admin.php?page=citas&msg=Pago+verificado" . $wa_redirect);
    exit;
}

// ESTADÍSTICAS OPERATIVAS (KPIS)
if ($is_scoped) {
    $hoy_citas = $conn->prepare("SELECT COUNT(*) as total FROM citas WHERE fecha = CURDATE() AND sucursal_id = ?");
    $hoy_citas->bind_param("i", $scope_id);
    $hoy_citas->execute();
    $hoy_citas = $hoy_citas->get_result()->fetch_assoc()['total'];

    $pendientes_hoy = $conn->prepare("SELECT COUNT(*) as total FROM citas WHERE estado_pago = 'pendiente' AND sucursal_id = ?");
    $pendientes_hoy->bind_param("i", $scope_id);
    $pendientes_hoy->execute();
    $pendientes_hoy = $pendientes_hoy->get_result()->fetch_assoc()['total'];
} else {
    $hoy_citas = $conn->query("SELECT COUNT(*) as total FROM citas WHERE fecha = CURDATE()")->fetch_assoc()['total'];
    $pendientes_hoy = $conn->query("SELECT COUNT(*) as total FROM citas WHERE estado_pago = 'pendiente'")->fetch_assoc()['total'];
}

if ($is_scoped && $scope_id) {
    $stmt_tot = $conn->prepare("SELECT COUNT(DISTINCT cl.telefono) as total FROM clientes cl INNER JOIN (SELECT cliente_telefono FROM citas WHERE sucursal_id = ? UNION SELECT cliente_telefono FROM pedidos WHERE sucursal_id = ?) active_clients ON cl.telefono = active_clients.cliente_telefono");
    $stmt_tot->bind_param("ii", $scope_id, $scope_id);
    $stmt_tot->execute();
    $total_clientes_club = $stmt_tot->get_result()->fetch_assoc()['total'];
    $stmt_tot->close();
} else {
    $total_clientes_club = $conn->query("SELECT COUNT(*) as total FROM clientes")->fetch_assoc()['total'];
}

// RECOPILAR CONFIGURACIÓN PARA PASARELA DE PAGOS
$config = [];
$conf_sucursal = $is_scoped ? $scope_id : 1;
$res_conf = $conn->prepare("SELECT * FROM configuracion WHERE sucursal_id = ?");
$res_conf->bind_param("i", $conf_sucursal);
$res_conf->execute();
$res_conf = $res_conf->get_result();
if ($res_conf) {
    while ($conf_row = $res_conf->fetch_assoc()) {
        $config[$conf_row['clave']] = $conf_row['valor'];
    }
}

// Nombre y token de la sucursal
$mi_token = '';
$nombre_sucursal = '';
$res_tok = $conn->prepare("SELECT nombre, token FROM sucursales WHERE id = ? LIMIT 1");
$res_tok->bind_param("i", $conf_sucursal);
$res_tok->execute();
$tok_row = $res_tok->get_result()->fetch_assoc();
if ($tok_row) {
    $mi_token = $tok_row['token'] ?? '';
    $nombre_sucursal = $tok_row['nombre'] ?? '';
}
$res_tok->close();

// Tiendas reales (excluye el ámbito Administración id=1) para asignar barberos
$tiendas_arr = [];
$res_t = $conn->query("SELECT id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre");
if ($res_t) while ($tr = $res_t->fetch_assoc()) $tiendas_arr[] = $tr;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Admin - AlCorte Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f9fafb;
            color: #111827;
        }

        .admin-container {
            display: flex;
            min-height: 100vh;
        }

        /* SIDEBAR */
        .sidebar {
            width: 240px;
            background: #0f172a;
            border-right: none;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            left: 0;
            top: 0;
            overflow-y: auto;
            z-index: 100;
        }

        .sidebar-brand {
            padding: 24px;
            border-bottom: 1px solid #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-brand i {
            font-size: 24px;
            color: #b49363;
        }

        .sidebar-brand span {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #ffffff;
        }

        .sidebar-nav {
            flex: 1;
            padding: 16px;
            space-y: 4px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            margin-bottom: 4px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            color: #94a3b8;
            transition: all 0.2s;
            cursor: pointer;
        }

        .nav-item:hover {
            background: #1e293b;
            color: #ffffff;
        }

        .nav-item.active {
            background: #1e293b;
            color: #ffffff;
            border-left: 4px solid #b49363;
            padding-left: 12px;
        }

        .nav-item i {
            width: 20px;
        }

        .sidebar-user {
            padding: 16px;
            border-top: 1px solid #1e293b;
            background: #0f172a;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e0e7ff;
            color: #4f46e5;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 12px;
        }

        .user-details p {
            font-size: 12px;
            margin: 2px 0;
        }

        .user-details p:first-child {
            font-weight: 600;
            color: #ffffff;
        }

        .user-details p:last-child {
            font-size: 10px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .logout-btn {
            width: 100%;
            padding: 8px;
            background: none;
            border: 1px solid #334155;
            border-radius: 6px;
            color: #94a3b8;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }

        .logout-btn:hover {
            color: #ef4444;
            border-color: #ef4444;
            background: rgba(239, 68, 68, 0.1);
        }

        /* MAIN */
        .main-content {
            flex: 1;
            margin-left: 240px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* HEADER */
        .header {
            height: 64px;
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 32px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .header-breadcrumb {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #9ca3af;
        }

        .success-badge {
            font-size: 12px;
            font-weight: 700;
            padding: 6px 12px;
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #d1fae5;
            border-radius: 6px;
        }

        /* CONTENT */
        .content {
            flex: 1;
            padding: 32px;
            overflow-y: auto;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .kpi-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-left: 4px solid #111827;
            border-radius: 12px;
            padding: 12px 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            border-left-color: #b49363;
        }

        .kpi-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #9ca3af;
            margin-bottom: 2px;
        }

        .kpi-value {
            font-size: 22px;
            font-weight: 700;
            color: #111827;
            font-family: 'Monaco', monospace;
        }

        .kpi-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            font-size: 16px;
        }

        /* TABLES */
        .card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-top: 4px solid #111827;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        .card-header {
            padding: 16px;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            border-left: 4px solid #b49363;
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-content {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th {
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            padding: 12px 16px;
            text-align: left;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
        }

        td {
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
            color: #111827;
        }

        tr:hover {
            background: #fafbfc;
        }

        /* PAGINACIÓN */
        .pagination-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            background: #ffffff;
            border-top: 1px solid #e5e7eb;
            font-size: 13px;
            color: #4b5563;
        }
        .pagination-buttons {
            display: flex;
            gap: 4px;
        }
        .pagination-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            padding: 0 8px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: #ffffff;
            color: #374151;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
        }
        .pagination-btn:hover {
            border-color: #d1d5db;
            background: #f9fafb;
            color: #111827;
        }
        .pagination-btn.active {
            border-color: #b49363;
            background: #fef3f2;
            color: #b49363;
            font-weight: 600;
        }
        .pagination-btn.disabled {
            border-color: #f3f4f6;
            background: #f9fafb;
            color: #9ca3af;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* BADGES */
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
        }

        .badge-pending {
            background: #fef3c7;
            color: #78350f;
        }

        .badge-verified {
            background: #dcfce7;
            color: #166534;
        }

        /* BUTTONS */
        .btn {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #10b981;
            color: white;
        }

        .btn-primary:hover {
            background: #059669;
        }

        /* FORMS */
        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            margin-bottom: 6px;
        }

        .form-input, .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            color: #111827;
            transition: all 0.2s;
        }

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #b49363;
            box-shadow: 0 0 0 3px rgba(180, 147, 99, 0.1);
        }

        .toggle-switch {
            position: relative;
            display: inline-flex;
            width: 44px;
            height: 24px;
            cursor: pointer;
        }

        .toggle-switch input {
            display: none;
        }

        .toggle-slider {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: #d1d5db;
            border-radius: 12px;
            transition: all 0.3s;
        }

        .toggle-slider::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            background: white;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: all 0.3s;
        }

        .toggle-switch input:checked + .toggle-slider {
            background: #10b981;
        }

        .toggle-switch input:checked + .toggle-slider::after {
            transform: translateX(20px);
        }

        /* RESPONSIVE */
        @media (max-width: 992px) {
            .sidebar {
                position: fixed;
                left: -100%;
                width: 280px;
                transition: left 0.3s ease;
                z-index: 1000;
                box-shadow: 4px 0 10px rgba(0,0,0,0.25);
            }
            .sidebar.open {
                left: 0;
            }
            .main-content {
                margin-left: 0;
            }
            .header {
                padding: 0 16px;
            }
            .content {
                padding: 16px;
            }
            .kpi-grid {
                grid-template-columns: 1fr;
            }
            .mobile-toggle {
                display: inline-flex !important;
                align-items: center;
                justify-content: center;
            }
            .mobile-close {
                display: inline-block !important;
            }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="sidebar-brand" style="flex-direction:column;align-items:flex-start;gap:2px">
                <div style="display:flex;align-items:center;justify-content:space-between;width:100%">
                    <div style="display:flex;align-items:center;gap:10px">
                        <?php if (!empty($config['logo_url'])): ?>
                            <img src="<?php echo htmlspecialchars($config['logo_url']); ?>" alt="Logo" style="height:32px;max-width:130px;object-fit:contain;border-radius:4px">
                        <?php else: ?>
                            <i class="fas fa-store" style="color:#b49363"></i>
                        <?php endif; ?>
                        <span><?php echo $nombre_sucursal ? htmlspecialchars($nombre_sucursal) : 'AlCorte'; ?></span>
                    </div>
                    <button type="button" id="sidebar-close" style="background:none; border:none; color:#ffffff; font-size:18px; cursor:pointer; display:none; padding:4px;" class="mobile-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php if ($nombre_sucursal): ?>
                <div style="font-size:9px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;padding-left:<?= !empty($config['logo_url']) ? '0px' : '24px' ?>">Panel de Gestión</div>
                <?php endif; ?>
            </div>

            <?php if ($_SESSION['rol'] === 'superadmin'): ?>
            <?php
                $todas_suc = [];
                $r_suc = $conn->query("SELECT id, nombre FROM sucursales ORDER BY id ASC");
                if ($r_suc) while ($rs = $r_suc->fetch_assoc()) $todas_suc[] = $rs;
            ?>
            <div style="padding:0 12px 12px">
                <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px">Gestionando tienda</div>
                <form method="GET" action="">
                    <input type="hidden" name="page" value="<?= htmlspecialchars($page) ?>">
                    <select name="suc" onchange="this.form.submit()"
                        style="width:100%;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);border-radius:8px;color:white;font-size:12px;font-weight:600;padding:7px 10px;cursor:pointer;appearance:none">
                        <option value="0" <?= !$is_scoped ? 'selected' : '' ?> style="color:#111">-- Selecciona tienda --</option>
                        <?php foreach ($todas_suc as $ts): ?>
                        <option value="<?= $ts['id'] ?>" <?= ($scope_id == $ts['id']) ? 'selected' : '' ?> style="color:#111">
                            <?= htmlspecialchars($ts['nombre']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <?php endif; ?>

            <nav class="sidebar-nav">
                <a href="?page=citas" class="nav-item <?php echo $page == 'citas' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check"></i>
                    <span>Control de Citas</span>
                </a>
                <?php if ($has_club_vip): ?>
                <a href="?page=clientes" class="nav-item <?php echo $page == 'clientes' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span>Clientes</span>
                </a>
                <?php endif; ?>
                <?php if ($is_basic_plan): ?>
                <a href="?page=mi_perfil" class="nav-item <?php echo $page == 'mi_perfil' ? 'active' : ''; ?>">
                    <i class="fas fa-user"></i>
                    <span>Mi Perfil</span>
                </a>
                <?php else: ?>
                <a href="?page=personal" class="nav-item <?php echo $page == 'personal' ? 'active' : ''; ?>">
                    <i class="fas fa-user-tie"></i>
                    <span>Equipo</span>
                </a>
                <?php endif; ?>
                <a href="?page=servicios" class="nav-item <?php echo $page == 'servicios' ? 'active' : ''; ?>">
                    <i class="fas fa-list-check"></i>
                    <span>Servicios</span>
                </a>
                <?php if ($has_productos): ?>
                <a href="?page=productos" class="nav-item <?php echo $page == 'productos' ? 'active' : ''; ?>">
                    <i class="fas fa-box-open"></i>
                    <span>Productos</span>
                </a>
                <a href="?page=pedidos" class="nav-item <?php echo $page == 'pedidos' ? 'active' : ''; ?>">
                    <i class="fas fa-receipt"></i>
                    <span>Pedidos</span>
                </a>
                <?php endif; ?>
                <a href="?page=ajustes" class="nav-item <?php echo $page == 'ajustes' ? 'active' : ''; ?>">
                    <i class="fas fa-sliders-h"></i>
                    <span>Configuración</span>
                </a>
            </nav>

            <div class="sidebar-user">
                <div class="user-info">
                    <div class="user-avatar" style="overflow: hidden; background: #ffffff; display: flex; align-items: center; justify-content: center;">
                        <?php if (!empty($config['logo_url'])): ?>
                            <img src="<?php echo htmlspecialchars($config['logo_url']); ?>" style="width: 100%; height: 100%; object-fit: contain;">
                        <?php else: ?>
                            <?php echo strtoupper(mb_substr($_SESSION['nombre'],0,2)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <p><?php echo htmlspecialchars(substr($_SESSION['nombre'], 0, 12)); ?></p>
                        <p><?php echo $is_basic_plan ? 'Barbero Independiente' : ($_SESSION['rol'] == 'gerente' ? 'Gerente' : 'Admin'); ?></p>
                    </div>
                </div>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt mr-1"></i> Salir
                </a>
            </div>
        </aside>

        <!-- MAIN -->
        <div class="main-content">
            <!-- HEADER -->
            <header class="header" style="display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <button type="button" id="sidebar-toggle" style="background:none; border:none; color:#111827; font-size:20px; cursor:pointer; display:none; padding:8px; border-radius:6px;" class="mobile-toggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                </div>
                <?php if (!empty($msg)): ?>
                    <span class="success-badge">✓ <?php echo $msg; ?></span>
                <?php endif; ?>
            </header>

            <!-- CONTENT -->
            <div class="content">


                <!-- PAGE CONTENT -->
                <?php if ($page == 'citas'): ?>

                    <?php if (!empty($_GET['wa_tel'])):
                        $wa_t = preg_replace('/\D/', '', $_GET['wa_tel']);
                        if ($wa_t && substr($wa_t, 0, 1) === '0') $wa_t = '58' . substr($wa_t, 1);
                        $wa_tpl = $config['wa_plantilla'] ?? '';
                        if (!$wa_tpl) $wa_tpl = "Hola {nombre}, tu cita en {sucursal} ha sido confirmada.\n\nServicio: {servicio}\nFecha: {fecha}\nHora: {hora}\n\n¡Te esperamos!";
                        $wa_text = urlencode(str_replace(
                            ['{nombre}', '{servicio}', '{fecha}', '{hora}', '{sucursal}'],
                            [$_GET['wa_nom'] ?? '', $_GET['wa_svc'] ?? '', $_GET['wa_fecha'] ?? '', $_GET['wa_hora'] ?? '', $nombre_sucursal],
                            $wa_tpl
                        ));
                    ?>
                    <div style="display:flex;align-items:center;gap:12px;padding:14px 18px;background:#ecfdf5;border:1px solid #bbf7d0;border-radius:10px;margin-bottom:16px">
                        <i class="fas fa-circle-check" style="color:#22c55e;font-size:18px;flex-shrink:0"></i>
                        <div style="flex:1">
                            <div style="font-size:13px;font-weight:700;color:#166534">Pago verificado — <?= htmlspecialchars($_GET['wa_nom'] ?? '') ?></div>
                            <div style="font-size:12px;color:#15803d"><?= htmlspecialchars($_GET['wa_svc'] ?? '') ?> · <?= htmlspecialchars($_GET['wa_fecha'] ?? '') ?> a las <?= htmlspecialchars($_GET['wa_hora'] ?? '') ?></div>
                        </div>
                        <a href="https://wa.me/<?= $wa_t ?>?text=<?= $wa_text ?>" target="_blank" rel="noopener"
                           style="display:flex;align-items:center;gap:6px;padding:8px 16px;background:#25d366;color:white;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap;flex-shrink:0">
                            <i class="fab fa-whatsapp" style="font-size:16px"></i> Notificar al cliente
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- KPI CARDS -->
                    <div class="kpi-grid">
                        <div class="kpi-card">
                            <div>
                                <div class="kpi-label">Turnos Agendados (Hoy)</div>
                                <div class="kpi-value"><?php echo $hoy_citas; ?></div>
                            </div>
                            <div class="kpi-icon" style="background: #fef3c7; color: #f59e0b;">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                        </div>
                        <div class="kpi-card">
                            <div>
                                <div class="kpi-label">Por Validar</div>
                                <div class="kpi-value"><?php echo $pendientes_hoy; ?></div>
                            </div>
                            <div class="kpi-icon" style="background: #fef08a; color: #eab308;">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                        </div>
                        <div class="kpi-card">
                            <div>
                                <div class="kpi-label">Clientes Registrados</div>
                                <div class="kpi-value"><?php echo $total_clientes_club; ?></div>
                            </div>
                            <div class="kpi-icon" style="background: #dcfce7; color: #22c55e;">
                                <i class="fas fa-users"></i>
                            </div>
                        </div>
                    </div>

                    <?php
                    // Obtener lista de barberos para el filtro de la sucursal actual
                    $barberos_filter_query = $conn->prepare("SELECT id, nombre FROM barberos WHERE sucursal_id = ? ORDER BY nombre");
                    $barberos_filter_query->bind_param("i", $scope_id);
                    $barberos_filter_query->execute();
                    $barberos_list_res = $barberos_filter_query->get_result();
                    $barberos_filter = [];
                    while ($b_row = $barberos_list_res->fetch_assoc()) {
                        $barberos_filter[] = $b_row;
                    }
                    $barberos_filter_query->close();

                    $f_estado  = $_GET['f_estado'] ?? 'todos';
                    $f_fecha   = $_GET['f_fecha'] ?? '';
                    $f_barbero = $_GET['f_barbero'] ?? 'todos';
                    $f_q       = $_GET['f_q'] ?? '';
                    ?>

                    <!-- FILTRO DE CITAS -->
                    <form method="GET" style="display:flex; flex-wrap:wrap; gap:12px; margin-bottom:20px; align-items:flex-end; background:#ffffff; border:1px solid #e5e7eb; border-radius:10px; padding:16px;">
                        <input type="hidden" name="page" value="citas">
                        
                        <div style="flex: 1; min-width: 180px;">
                            <label style="font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:4px; display:block;">Cliente (Nombre/Teléfono)</label>
                            <input type="text" name="f_q" placeholder="Buscar cliente..." value="<?php echo htmlspecialchars($f_q); ?>" style="font-size:12px; padding:8px 12px; height:38px; border:1px solid #e5e7eb; border-radius:6px; width: 100%;">
                        </div>
                        
                        <div style="width: 140px;">
                            <label style="font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:4px; display:block;">Estado</label>
                            <select name="f_estado" style="font-size:12px; padding:8px 12px; height:38px; border:1px solid #e5e7eb; border-radius: 6px; width: 100%; background: #fff; cursor: pointer;">
                                <option value="todos" <?php echo $f_estado === 'todos' ? 'selected' : ''; ?>>Todos</option>
                                <option value="programada" <?php echo $f_estado === 'programada' ? 'selected' : ''; ?>>Programadas</option>
                                <option value="completada" <?php echo $f_estado === 'completada' ? 'selected' : ''; ?>>Completadas</option>
                                <option value="cancelada" <?php echo $f_estado === 'cancelada' ? 'selected' : ''; ?>>Canceladas</option>
                            </select>
                        </div>

                        <div style="width: 160px;">
                            <label style="font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:4px; display:block;">Especialista</label>
                            <select name="f_barbero" style="font-size:12px; padding:8px 12px; height:38px; border:1px solid #e5e7eb; border-radius: 6px; width: 100%; background: #fff; cursor: pointer;">
                                <option value="todos" <?php echo $f_barbero === 'todos' ? 'selected' : ''; ?>>Todos</option>
                                <?php foreach ($barberos_filter as $b_f): ?>
                                    <option value="<?php echo $b_f['id']; ?>" <?php echo strval($f_barbero) === strval($b_f['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($b_f['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="width: 150px;">
                            <label style="font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:4px; display:block;">Fecha</label>
                            <input type="date" name="f_fecha" value="<?php echo htmlspecialchars($f_fecha); ?>" style="font-size:12px; padding:8px 12px; height:38px; border:1px solid #e5e7eb; border-radius: 6px; width: 100%;">
                        </div>

                        <div style="display:flex; gap:8px;">
                            <button type="submit" style="height:38px; padding:0 16px; background:#b49363; border:none; color:white; font-size:12px; font-weight:600; border-radius:6px; cursor:pointer;">
                                <i class="fas fa-filter" style="margin-right:6px;"></i> Filtrar
                            </button>
                            <?php if ($f_estado !== 'todos' || !empty($f_fecha) || $f_barbero !== 'todos' || !empty($f_q)): ?>
                                <a href="?page=citas" style="height:38px; display:inline-flex; align-items:center; justify-content:center; padding:0 16px; border:1px solid #e5e7eb; border-radius:6px; color:#4b5563; text-decoration:none; font-size:12px; font-weight:600; background:#ffffff;">
                                    Limpiar
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <div class="card">
                        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                            <span>Control de Citas</span>
                            <div style="display:inline-flex; background:#f1f5f9; padding:3px; border-radius:8px; gap:4px;">
                                <button type="button" onclick="setAppointmentsView('list')" id="view-btn-list" style="border:none; background:#ffffff; padding:6px 12px; border-radius:6px; font-size:11px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; color:#0f172a; box-shadow:0 1px 2px rgba(0,0,0,0.05); transition:all .2s;">
                                    <i class="fas fa-list"></i> Lista
                                </button>
                                <button type="button" onclick="setAppointmentsView('cards')" id="view-btn-cards" style="border:none; background:transparent; padding:6px 12px; border-radius:6px; font-size:11px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:6px; color:#64748b; transition:all .2s;">
                                    <i class="fas fa-grip-vertical"></i> Cuadros
                                </button>
                            </div>
                        </div>
                        <div class="card-content">
                            <?php
                            $limit_citas = 10;
                            $page_citas = isset($_GET['p_citas']) ? max(1, intval($_GET['p_citas'])) : 1;
                            $offset_citas = ($page_citas - 1) * $limit_citas;

                            $where_clauses = [];
                            $params = [];
                            $types = "";

                            if ($is_scoped) {
                                $where_clauses[] = "c.sucursal_id = ?";
                                $params[] = $scope_id;
                                $types .= "i";
                            }

                            if ($f_estado !== 'todos') {
                                $where_clauses[] = "c.estado = ?";
                                $params[] = $f_estado;
                                $types .= "s";
                            }

                            if (!empty($f_fecha)) {
                                $where_clauses[] = "c.fecha = ?";
                                $params[] = $f_fecha;
                                $types .= "s";
                            }

                            if ($f_barbero !== 'todos') {
                                $where_clauses[] = "c.barbero_id = ?";
                                $params[] = intval($f_barbero);
                                $types .= "i";
                            }

                            if (!empty($f_q)) {
                                $q = "%" . $f_q . "%";
                                $where_clauses[] = "(c.cliente_nombre LIKE ? OR c.cliente_telefono LIKE ?)";
                                $params[] = $q;
                                $params[] = $q;
                                $types .= "ss";
                            }

                            $where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

                            // Obtener cantidad total filtrada para paginación
                            $stmt_cnt = $conn->prepare("SELECT COUNT(*) as total FROM citas c $where_sql");
                            if (count($params) > 0) {
                                $stmt_cnt->bind_param($types, ...$params);
                            }
                            $stmt_cnt->execute();
                            $total_citas = $stmt_cnt->get_result()->fetch_assoc()['total'];
                            $stmt_cnt->close();

                            $total_pages_citas = ceil($total_citas / $limit_citas);

                            // Obtener registros de citas filtrados
                            $query_citas = "SELECT c.*, b.nombre as barbero_nombre FROM citas c LEFT JOIN barberos b ON c.barbero_id = b.id $where_sql ORDER BY c.fecha DESC, c.hora DESC LIMIT ? OFFSET ?";
                            $stmt_citas = $conn->prepare($query_citas);

                            $params_fetch = $params;
                            $params_fetch[] = $limit_citas;
                            $params_fetch[] = $offset_citas;
                            $types_fetch = $types . "ii";

                            $stmt_citas->bind_param($types_fetch, ...$params_fetch);
                            $stmt_citas->execute();
                            $citas = $stmt_citas->get_result();

                            $citas_list = [];
                            while ($row = $citas->fetch_assoc()) {
                                $citas_list[] = $row;
                            }
                            $stmt_citas->close();
                            ?>

                            <!-- VISTA DE LISTA (TABLA) -->
                            <div id="citas-table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Cliente</th>
                                            <th>Servicio</th>
                                            <th>Horario</th>
                                            <th>Referencia</th>
                                            <th>Estado</th>
                                            <th>Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($citas_list) === 0): ?>
                                            <tr>
                                                <td colspan="6" style="text-align:center; padding:32px; color:#94a3b8;">Sin citas registradas.</td>
                                            </tr>
                                        <?php else: foreach ($citas_list as $row): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($row['cliente_nombre']); ?></strong><br>
                                                <small style="color: #9ca3af;"><?php echo htmlspecialchars($row['cliente_telefono']); ?></small>
                                            </td>
                                            <td>
                                                <div><?php echo htmlspecialchars($row['servicio']); ?></div>
                                                <small style="color: #b49363;"><strong><?php echo htmlspecialchars($row['barbero_nombre'] ?? 'N/A'); ?></strong></small>
                                            </td>
                                            <td>
                                                <div><?php echo date('d/m/Y', strtotime($row['fecha'])); ?></div>
                                                <small style="color: #9ca3af;"><?php echo date('h:i A', strtotime($row['hora'])); ?></small>
                                            </td>
                                            <td>
                                                <div><?php echo htmlspecialchars($row['metodo_pago']); ?></div>
                                                <small style="color: #9ca3af; font-family: monospace;">#<?php echo htmlspecialchars($row['referencia_pago'] ?: 'N/A'); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($row['estado_pago'] == 'pendiente'): ?>
                                                    <span class="badge badge-pending">Pago Pendiente</span>
                                                <?php else: ?>
                                                    <span class="badge badge-verified">Pago OK</span>
                                                <?php endif; ?>
                                                <?php
                                                    $ce = $row['estado'] ?? 'programada';
                                                    $ce_style = [
                                                        'programada' => 'background:#eef2ff;color:#3730a3',
                                                        'completada' => 'background:#dcfce7;color:#166534',
                                                        'cancelada'  => 'background:#fee2e2;color:#991b1b',
                                                    ][$ce] ?? 'background:#f3f4f6;color:#6b7280';
                                                ?>
                                                <br>
                                                <span class="badge" style="margin-top:4px;display:inline-block;<?php echo $ce_style; ?>"><?php echo ucfirst($ce); ?></span>
                                            </td>
                                            <td>
                                                <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
                                                <?php if ($row['estado_pago'] == 'pendiente'): ?>
                                                    <form action="admin.php?page=citas" method="POST" style="display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                                        <input type="hidden" name="verificar_id" value="<?php echo $row['id']; ?>">
                                                        <button type="submit" class="btn btn-primary">Aprobar</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span style="color: #9ca3af; font-size: 12px;">✓</span>
                                                <?php endif; ?>
                                                <?php
                                                    $wa_tel = preg_replace('/\D/', '', $row['cliente_telefono']);
                                                    if ($wa_tel && strlen($wa_tel) >= 7) {
                                                        if (substr($wa_tel, 0, 1) === '0') $wa_tel = '58' . substr($wa_tel, 1);
                                                        $wa_tpl_row = $config['wa_plantilla'] ?? '';
                                                        if (!$wa_tpl_row) $wa_tpl_row = "Hola {nombre}, tu cita en {sucursal} ha sido confirmada.\n\nServicio: {servicio}\nFecha: {fecha}\nHora: {hora}\n\n¡Te esperamos!";
                                                        $wa_text = urlencode(str_replace(
                                                            ['{nombre}', '{servicio}', '{fecha}', '{hora}', '{sucursal}'],
                                                            [$row['cliente_nombre'], $row['servicio'], date('d/m/Y', strtotime($row['fecha'])), date('h:i A', strtotime($row['hora'])), $nombre_sucursal],
                                                            $wa_tpl_row
                                                        ));
                                                ?>
                                                    <a href="https://wa.me/<?= $wa_tel ?>?text=<?= $wa_text ?>" target="_blank" rel="noopener" title="WhatsApp" style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;background:#25d366;color:white;border-radius:6px;font-size:14px;text-decoration:none"><i class="fab fa-whatsapp"></i></a>
                                                <?php } ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>

                             <!-- VISTA DE CUADROS (GRID) -->
                             <div id="citas-grid-container" style="display:none; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; padding: 4px 0 12px;">
                                 <?php if (count($citas_list) === 0): ?>
                                     <div style="grid-column:1/-1; text-align:center; padding:32px; color:#94a3b8;">Sin citas registradas.</div>
                                 <?php else: foreach ($citas_list as $row):
                                     $est = $row['estado'] ?? 'programada';
                                     $ce_style = [
                                         'programada' => 'background:#eef2ff;color:#3730a3',
                                         'completada' => 'background:#dcfce7;color:#166534',
                                         'cancelada'  => 'background:#fee2e2;color:#991b1b',
                                     ][$est] ?? 'background:#f3f4f6;color:#6b7280';
                                 ?>
                                 <div style="border:1px solid #e5e7eb; border-radius:10px; background:#ffffff; box-shadow:0 1px 3px rgba(0,0,0,0.05); display:flex; flex-direction:column; overflow:hidden;">
                                     <div style="padding:6px 10px; background:linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color:#ffffff; display:flex; justify-content:space-between; align-items:center;">
                                         <span style="font-size:13px; font-weight:700; font-family:'Monaco',monospace;"><?php echo date('h:i A', strtotime($row['hora'])); ?></span>
                                         <span style="font-size:10px; opacity:0.9; font-weight:600;"><?php echo date('d/m/Y', strtotime($row['fecha'])); ?></span>
                                     </div>
                                     <div style="padding:8px 10px; flex:1; display:flex; flex-direction:column; gap:6px;">
                                         <div>
                                             <div style="font-size:12px; font-weight:800; color:#0f172a;"><?php echo htmlspecialchars($row['cliente_nombre']); ?></div>
                                             <div style="font-size:10px; color:#9ca3af; font-family:monospace;"><?php echo htmlspecialchars($row['cliente_telefono']); ?></div>
                                         </div>
                                         <div style="background:#f8fafc; border-left:3px solid #b49363; padding:4px 8px; border-radius:6px; font-size:10px; color:#475569;">
                                             <div><i class="fas fa-scissors" style="margin-right:6px; font-size:8px; color:#b49363;"></i><?php echo htmlspecialchars($row['servicio']); ?></div>
                                             <div style="font-weight:700; color:#0f172a; margin-top:2px;"><i class="fas fa-user-tie" style="margin-right:6px; font-size:8px; color:#b49363;"></i><?php echo htmlspecialchars($row['barbero_nombre'] ?? 'N/A'); ?></div>
                                         </div>
                                         <div style="font-size:10px; color:#64748b; line-height: 1.3;">
                                             Método: <strong><?php echo htmlspecialchars($row['metodo_pago']); ?></strong><br>
                                             Ref: <code style="font-size:9px;"><?php echo htmlspecialchars($row['referencia_pago'] ?: 'N/A'); ?></code>
                                         </div>
                                         <div style="display:flex; justify-content:space-between; align-items:center; margin-top:2px; padding-top:6px; border-top:1px solid #f1f5f9; flex-wrap:wrap; gap:4px;">
                                             <span class="badge" style="font-size:9px; padding:2px 6px; <?php echo $ce_style; ?>"><?php echo ucfirst($est); ?></span>
                                             <?php if ($row['estado_pago'] == 'pendiente'): ?>
                                                 <span class="badge badge-pending" style="font-size:9px; padding:2px 6px;">Pendiente</span>
                                             <?php else: ?>
                                                 <span class="badge badge-verified" style="font-size:9px; padding:2px 6px;">Pago OK</span>
                                             <?php endif; ?>
                                         </div>
                                     </div>
                                     <div style="padding:6px 10px; background:#f8fafc; border-top:1px solid #f1f5f9; display:flex; justify-content:space-between; align-items:center; gap:6px;">
                                         <?php if ($row['estado_pago'] == 'pendiente'): ?>
                                             <form action="admin.php?page=citas" method="POST" style="margin:0;">
                                                 <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                                 <input type="hidden" name="verificar_id" value="<?php echo $row['id']; ?>">
                                                 <button type="submit" class="btn btn-primary" style="padding:3px 8px; font-size:9px; background:#b49363; border:none; color:white; border-radius:4px; font-weight:600; cursor:pointer;">Aprobar</button>
                                             </form>
                                         <?php else: ?>
                                             <span style="color: #22c55e; font-size: 10px; font-weight:700;"><i class="fas fa-check-double"></i> Ok</span>
                                         <?php endif; ?>

                                         <?php
                                             $wa_tel = preg_replace('/\D/', '', $row['cliente_telefono']);
                                             if ($wa_tel && strlen($wa_tel) >= 7) {
                                                 if (substr($wa_tel, 0, 1) === '0') $wa_tel = '58' . substr($wa_tel, 1);
                                                 $wa_tpl_row = $config['wa_plantilla'] ?? '';
                                                 if (!$wa_tpl_row) $wa_tpl_row = "Hola {nombre}, tu cita en {sucursal} ha sido confirmada.\n\nServicio: {servicio}\nFecha: {fecha}\nHora: {hora}\n\n¡Te esperamos!";
                                                 $wa_text = urlencode(str_replace(
                                                     ['{nombre}', '{servicio}', '{fecha}', '{hora}', '{sucursal}'],
                                                     [$row['cliente_nombre'], $row['servicio'], date('d/m/Y', strtotime($row['fecha'])), date('h:i A', strtotime($row['hora'])), $nombre_sucursal],
                                                     $wa_tpl_row
                                                 ));
                                         ?>
                                             <a href="https://wa.me/<?= $wa_tel ?>?text=<?= $wa_text ?>" target="_blank" rel="noopener" style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; background:#25d366; color:white; border-radius:4px; font-size:10px; text-decoration:none;"><i class="fab fa-whatsapp"></i></a>
                                         <?php } ?>
                                     </div>
                                 </div>
                                 <?php endforeach; endif; ?>
                             </div>

                        </div>
                        <?php if ($total_pages_citas > 1): ?>
                        <div class="pagination-container">
                            <span>Mostrando <?= min($total_citas, $offset_citas + 1) ?>-<?= min($total_citas, $offset_citas + $limit_citas) ?> de <?= $total_citas ?> citas</span>
                            <div class="pagination-buttons">
                                <?php
                                $prev_params = $_GET;
                                $prev_params['p_citas'] = max(1, $page_citas - 1);
                                $prev_url = '?' . http_build_query($prev_params);
                                $disabled_prev = ($page_citas == 1) ? 'disabled' : '';
                                ?>
                                <a href="<?= $prev_url ?>" class="pagination-btn <?= $disabled_prev ?>"><i class="fas fa-chevron-left"></i></a>

                                <?php for ($i = 1; $i <= $total_pages_citas; $i++): 
                                    $page_params = $_GET;
                                    $page_params['p_citas'] = $i;
                                    $page_url = '?' . http_build_query($page_params);
                                    $active_class = ($i == $page_citas) ? 'active' : '';
                                ?>
                                    <a href="<?= $page_url ?>" class="pagination-btn <?= $active_class ?>"><?= $i ?></a>
                                <?php endfor; ?>

                                <?php
                                $next_params = $_GET;
                                $next_params['p_citas'] = min($total_pages_citas, $page_citas + 1);
                                $next_url = '?' . http_build_query($next_params);
                                $disabled_next = ($page_citas == $total_pages_citas) ? 'disabled' : '';
                                ?>
                                <a href="<?= $next_url ?>" class="pagination-btn <?= $disabled_next ?>"><i class="fas fa-chevron-right"></i></a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($page == 'clientes'): ?>
                    <!-- FILTERS -->
                    <form method="GET" style="display:flex; flex-wrap:wrap; gap:12px; margin-bottom:20px; align-items:flex-end;">
                        <input type="hidden" name="page" value="clientes">
                        <?php if (isset($_GET['suc'])): ?>
                            <input type="hidden" name="suc" value="<?php echo htmlspecialchars($_GET['suc']); ?>">
                        <?php endif; ?>
                        
                        <div style="flex: 1; min-width: 200px;">
                            <label style="font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:4px; display:block;">Buscar Nombre/Teléfono</label>
                            <input type="text" name="q" class="form-input" placeholder="Nombre o teléfono..." value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>" style="font-size:12px; padding:8px 12px; height:38px;">
                        </div>
                        
                        <div style="width: 150px;">
                            <label style="font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:4px; display:block;">Mínimo de Citas</label>
                            <input type="number" name="min_citas" class="form-input" placeholder="Ej. 1" min="0" value="<?php echo htmlspecialchars($_GET['min_citas'] ?? ''); ?>" style="font-size:12px; padding:8px 12px; height:38px;">
                        </div>

                        <div style="width: 180px;">
                            <label style="font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:4px; display:block;">Última visita desde</label>
                            <input type="date" name="fecha_desde" class="form-input" value="<?php echo htmlspecialchars($_GET['fecha_desde'] ?? ''); ?>" style="font-size:12px; padding:8px 12px; height:38px;">
                        </div>

                        <div style="display:flex; gap:8px;">
                            <button type="submit" class="btn btn-primary" style="height:38px; padding:0 16px; background:#b49363; border-color:#b49363; color:white; font-size:12px; font-weight:600; border-radius:6px; cursor:pointer;">
                                <i class="fas fa-filter" style="margin-right:6px;"></i> Filtrar
                            </button>
                            <?php if (!empty($_GET['q']) || !empty($_GET['min_citas']) || !empty($_GET['fecha_desde'])): ?>
                                <a href="?page=clientes" class="btn" style="height:38px; display:inline-flex; align-items:center; justify-content:center; padding:0 16px; border:1px solid #e5e7eb; border-radius:6px; color:#4b5563; text-decoration:none; font-size:12px; font-weight:600; background:#ffffff;">
                                    Limpiar
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <div class="card">
                        <div class="card-header">Clientes Registrados</div>
                        <div class="card-content">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>Teléfono</th>
                                        <th>Citas Realizadas</th>
                                        <th>Última Visita</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $limit_clientes = 10;
                                    $page_clientes = isset($_GET['p_clientes']) ? max(1, intval($_GET['p_clientes'])) : 1;
                                    $offset_clientes = ($page_clientes - 1) * $limit_clientes;

                                    $where_clauses = [];
                                    $params = [];
                                    $types = "";
                                    $join_params = [$scope_id, $scope_id, $scope_id];
                                    $join_types = "iii";

                                    if (!empty($_GET['q'])) {
                                        $q = "%" . $_GET['q'] . "%";
                                        $where_clauses[] = "(cl.nombre LIKE ? OR cl.telefono LIKE ?)";
                                        $params[] = $q;
                                        $params[] = $q;
                                        $types .= "ss";
                                    }

                                    if (!empty($_GET['fecha_desde'])) {
                                        $where_clauses[] = "cl.ultima_visita >= ?";
                                        $params[] = $_GET['fecha_desde'];
                                        $types .= "s";
                                    }

                                    $having_clauses = [];
                                    $having_params = [];
                                    $having_types = "";
                                    if (isset($_GET['min_citas']) && $_GET['min_citas'] !== '') {
                                        $min_citas = intval($_GET['min_citas']);
                                        $having_clauses[] = "COUNT(ci.id) >= ?";
                                        $having_params[] = $min_citas;
                                        $having_types .= "i";
                                    }

                                    $where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";
                                    $having_sql = count($having_clauses) > 0 ? "HAVING " . implode(" AND ", $having_clauses) : "";

                                    $count_query = "SELECT COUNT(*) as total FROM (
                                        SELECT cl.telefono
                                        FROM clientes cl 
                                        INNER JOIN (
                                            SELECT cliente_telefono FROM citas WHERE sucursal_id = ?
                                            UNION
                                            SELECT cliente_telefono FROM pedidos WHERE sucursal_id = ?
                                        ) active_clients ON cl.telefono = active_clients.cliente_telefono
                                        LEFT JOIN citas ci ON cl.telefono = ci.cliente_telefono AND ci.sucursal_id = ?
                                        $where_sql 
                                        GROUP BY cl.telefono 
                                        $having_sql
                                    ) as t";

                                    $stmt_cnt = $conn->prepare($count_query);
                                    $all_params = array_merge($join_params, $params, $having_params);
                                    $all_types = $join_types . $types . $having_types;
                                    if (!empty($all_types)) {
                                        $stmt_cnt->bind_param($all_types, ...$all_params);
                                    }
                                    $stmt_cnt->execute();
                                    $total_clientes = $stmt_cnt->get_result()->fetch_assoc()['total'];
                                    $stmt_cnt->close();

                                    $total_pages_clientes = ceil($total_clientes / $limit_clientes);

                                    $main_query = "SELECT cl.*, COUNT(ci.id) as total_citas 
                                        FROM clientes cl 
                                        INNER JOIN (
                                            SELECT cliente_telefono FROM citas WHERE sucursal_id = ?
                                            UNION
                                            SELECT cliente_telefono FROM pedidos WHERE sucursal_id = ?
                                        ) active_clients ON cl.telefono = active_clients.cliente_telefono
                                        LEFT JOIN citas ci ON cl.telefono = ci.cliente_telefono AND ci.sucursal_id = ?
                                        $where_sql 
                                        GROUP BY cl.telefono 
                                        $having_sql 
                                        ORDER BY total_citas DESC 
                                        LIMIT ? OFFSET ?";

                                    $stmt_main = $conn->prepare($main_query);
                                    $all_params = array_merge($join_params, $params, $having_params, [$limit_clientes, $offset_clientes]);
                                    $all_types = $join_types . $types . $having_types . "ii";
                                    $stmt_main->bind_param($all_types, ...$all_params);
                                    $stmt_main->execute();
                                    $res_c = $stmt_main->get_result();

                                    while ($c = $res_c->fetch_assoc()):
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($c['nombre']); ?></strong></td>
                                        <td style="font-family: monospace;"><?php echo htmlspecialchars($c['telefono']); ?></td>
                                        <td><i class="fas fa-calendar-check" style="color: #b49363; margin-right: 4px;"></i> <?php echo $c['total_citas']; ?></td>
                                        <td style="color: #9ca3af;"><?php echo $c['ultima_visita'] ? date('d/m/Y', strtotime($c['ultima_visita'])) : 'Sin visitas registradas'; ?></td>
                                    </tr>
                                    <?php endwhile; 
                                    $stmt_main->close();
                                    ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($total_pages_clientes > 1): ?>
                        <div class="pagination-container">
                            <span>Mostrando <?= min($total_clientes, $offset_clientes + 1) ?>-<?= min($total_clientes, $offset_clientes + $limit_clientes) ?> de <?= $total_clientes ?> clientes</span>
                            <div class="pagination-buttons">
                                <?php
                                $prev_params = $_GET;
                                $prev_params['p_clientes'] = max(1, $page_clientes - 1);
                                $prev_url = '?' . http_build_query($prev_params);
                                $disabled_prev = ($page_clientes == 1) ? 'disabled' : '';
                                ?>
                                <a href="<?= $prev_url ?>" class="pagination-btn <?= $disabled_prev ?>"><i class="fas fa-chevron-left"></i></a>

                                <?php for ($i = 1; $i <= $total_pages_clientes; $i++): 
                                    $page_params = $_GET;
                                    $page_params['p_clientes'] = $i;
                                    $page_url = '?' . http_build_query($page_params);
                                    $active_class = ($i == $page_clientes) ? 'active' : '';
                                ?>
                                    <a href="<?= $page_url ?>" class="pagination-btn <?= $active_class ?>"><?= $i ?></a>
                                <?php endfor; ?>

                                <?php
                                $next_params = $_GET;
                                $next_params['p_clientes'] = min($total_pages_clientes, $page_clientes + 1);
                                $next_url = '?' . http_build_query($next_params);
                                $disabled_next = ($page_clientes == $total_pages_clientes) ? 'disabled' : '';
                                ?>
                                <a href="<?= $next_url ?>" class="pagination-btn <?= $disabled_next ?>"><i class="fas fa-chevron-right"></i></a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($page == 'mi_perfil' && $is_basic_plan):
                    // Cargar datos del barbero vinculado
                    $bp = $conn->prepare("SELECT * FROM barberos WHERE id = ?");
                    $bp->bind_param("i", $mi_barbero_id); $bp->execute();
                    $mi_b = $bp->get_result()->fetch_assoc(); $bp->close();
                ?>
                    <div style="max-width:520px">
                        <div style="margin-bottom:20px">
                            <h2 style="font-size:20px;font-weight:800;color:#0f172a">Mi Perfil de Barbero</h2>
                            <p style="font-size:13px;color:#64748b;margin-top:4px">Así te verán tus clientes al reservar.</p>
                        </div>
                        <div class="card">
                            <div class="card-content" style="padding:24px">
                                <form action="../backend/processing/admin.php" method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                    <input type="hidden" name="action" value="edit_barbero">
                                    <input type="hidden" name="id" value="<?php echo $mi_b['id']; ?>">
                                    <input type="hidden" name="activo" value="1">

                                    <!-- Foto -->
                                    <div class="form-group" style="text-align:center">
                                        <div id="mp_foto_wrap" onclick="document.getElementById('mp_foto_file').click()" style="width:100px;height:100px;border-radius:50%;margin:0 auto 12px;overflow:hidden;border:3px solid #e2e8f0;cursor:pointer;position:relative">
                                            <img id="mp_foto_img" src="<?php echo htmlspecialchars($mi_b['foto_url']); ?>" style="width:100%;height:100%;object-fit:cover">
                                            <div style="position:absolute;inset:0;background:rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0">
                                                <i class="fas fa-camera" style="color:white;font-size:20px"></i>
                                            </div>
                                        </div>
                                        <input type="file" id="mp_foto_file" name="foto_file" accept="image/*" style="display:none" onchange="if(this.files[0]){const r=new FileReader();r.onload=e=>document.getElementById('mp_foto_img').src=e.target.result;r.readAsDataURL(this.files[0])}">
                                        <small style="color:#94a3b8;font-size:11px">Haz clic para cambiar tu foto</small>
                                    </div>

                                    <!-- Nombre -->
                                    <div class="form-group">
                                        <label class="form-label">Nombre</label>
                                        <input type="text" name="nombre" class="form-input" value="<?php echo htmlspecialchars($mi_b['nombre']); ?>" required>
                                    </div>

                                    <!-- Horario -->
                                    <div class="form-group">
                                        <label class="form-label"><i class="fas fa-clock" style="color:#b49363;font-size:10px;margin-right:4px"></i> Horario de trabajo</label>
                                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                                            <div>
                                                <div style="font-size:10px;color:#9ca3af;font-weight:600;margin-bottom:4px">Entrada</div>
                                                <input type="time" name="hora_inicio" value="<?php echo substr($mi_b['hora_inicio'],0,5); ?>" class="form-input" required>
                                            </div>
                                            <div>
                                                <div style="font-size:10px;color:#9ca3af;font-weight:600;margin-bottom:4px">Salida</div>
                                                <input type="time" name="hora_fin" value="<?php echo substr($mi_b['hora_fin'],0,5); ?>" class="form-input" required>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Almuerzo -->
                                    <div class="form-group">
                                        <label class="form-label"><i class="fas fa-utensils" style="color:#b49363;font-size:10px;margin-right:4px"></i> Almuerzo</label>
                                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                                            <div>
                                                <div style="font-size:10px;color:#9ca3af;font-weight:600;margin-bottom:4px">Inicio</div>
                                                <input type="time" name="almuerzo_inicio" value="<?php echo substr($mi_b['almuerzo_inicio'],0,5); ?>" class="form-input" required>
                                            </div>
                                            <div>
                                                <div style="font-size:10px;color:#9ca3af;font-weight:600;margin-bottom:4px">Fin</div>
                                                <input type="time" name="almuerzo_fin" value="<?php echo substr($mi_b['almuerzo_fin'],0,5); ?>" class="form-input" required>
                                            </div>
                                        </div>
                                    </div>

                                    <button type="submit" class="btn btn-primary" style="width:100%;background:#b49363;color:white;padding:12px;justify-content:center;margin-top:8px">
                                        <i class="fas fa-save" style="margin-right:6px"></i> Guardar Perfil
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                <?php elseif ($page == 'personal'): ?>

                    <!-- MODAL BARBERO -->
                    <div id="modalBarbero" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);align-items:center;justify-content:center">
                        <div style="background:#fff;border-radius:1rem;padding:28px;width:100%;max-width:420px;margin:16px;box-shadow:0 20px 60px rgba(0,0,0,.2);position:relative;max-height:90vh;overflow-y:auto">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
                                <h3 id="modalBarberoTitle" style="font-size:15px;font-weight:800;color:#0f172a">Registrar Barbero</h3>
                                <button onclick="cerrarModal()" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:18px;line-height:1;padding:4px"><i class="fas fa-times"></i></button>
                            </div>
                            <form action="../backend/processing/admin.php" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                <input type="hidden" id="barb_action" name="action" value="add_barbero">
                                <input type="hidden" id="barb_id" name="id" value="">

                                <!-- upload foto -->
                                <div class="form-group">
                                    <label class="form-label">Foto</label>
                                    <div id="barb_foto_preview" onclick="document.getElementById('barb_foto_file').click()" style="height:100px;border-radius:.75rem;border:2px dashed #e2e8f0;background:#f8fafc;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;position:relative;transition:border-color .2s">
                                        <div id="barb_foto_placeholder" style="text-align:center;color:#94a3b8;pointer-events:none">
                                            <i class="fas fa-camera" style="font-size:22px;margin-bottom:6px;display:block"></i>
                                            <span style="font-size:11px;font-weight:600">Subir imagen</span>
                                        </div>
                                        <img id="barb_foto_img" src="" style="display:none;width:100%;height:100%;object-fit:cover;position:absolute;inset:0">
                                    </div>
                                    <input type="file" id="barb_foto_file" name="foto_file" accept="image/*" style="display:none" onchange="previewBarbFoto(this)">
                                    <input type="text" id="barb_foto_url" name="foto_url" class="form-input" placeholder="O pega una URL de imagen" style="margin-top:8px;font-size:12px" oninput="previewBarbUrl(this.value)">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Nombre</label>
                                    <input type="text" id="barb_nombre" name="nombre" class="form-input" placeholder="Ej. Joshy" required>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" style="display:flex;align-items:center;gap:5px"><i class="fas fa-clock" style="color:#b49363;font-size:10px"></i> Horario de trabajo</label>
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                                        <div>
                                            <div style="font-size:10px;color:#9ca3af;font-weight:600;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Entrada</div>
                                            <input type="time" id="barb_inicio" name="hora_inicio" value="09:00" class="form-input" required style="padding:7px 10px;font-size:13px">
                                        </div>
                                        <div>
                                            <div style="font-size:10px;color:#9ca3af;font-weight:600;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Salida</div>
                                            <input type="time" id="barb_fin" name="hora_fin" value="17:00" class="form-input" required style="padding:7px 10px;font-size:13px">
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" style="display:flex;align-items:center;gap:5px"><i class="fas fa-utensils" style="color:#b49363;font-size:10px"></i> Almuerzo</label>
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                                        <div>
                                            <div style="font-size:10px;color:#9ca3af;font-weight:600;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Inicio</div>
                                            <input type="time" id="barb_almuerzo_inicio" name="almuerzo_inicio" value="12:00" class="form-input" required style="padding:7px 10px;font-size:13px">
                                        </div>
                                        <div>
                                            <div style="font-size:10px;color:#9ca3af;font-weight:600;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Fin</div>
                                            <input type="time" id="barb_almuerzo_fin" name="almuerzo_fin" value="13:00" class="form-input" required style="padding:7px 10px;font-size:13px">
                                        </div>
                                    </div>
                                </div>

                                <div id="barb_credentials_section" style="border-top:1px solid #f1f5f9;margin-top:16px;padding-top:16px">
                                    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;margin-bottom:12px"><i class="fas fa-lock" style="margin-right:4px"></i> Acceso al Sistema</div>
                                    <div class="form-group">
                                        <label class="form-label">Usuario</label>
                                        <input type="text" id="barb_usuario" name="barb_usuario" class="form-input" placeholder="Ej. joshy" autocomplete="off" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Contraseña</label>
                                        <input type="password" id="barb_password" name="barb_password" class="form-input" placeholder="Mín. 8 caracteres" autocomplete="new-password" required minlength="8">
                                    </div>
                                </div>

                                <div class="form-group" id="status_container" style="display:none">
                                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#6b7280;cursor:pointer">
                                        <input type="checkbox" id="barb_activo" name="activo" value="1" style="width:16px;height:16px">
                                        Activo en Sistema
                                    </label>
                                </div>

                                <div style="display:flex;gap:8px;margin-top:20px">
                                    <button type="submit" class="btn btn-primary" style="flex:1;background:#b49363;color:white;justify-content:center">Guardar</button>
                                    <button type="button" onclick="cerrarModal()" class="btn btn-ghost">Cancelar</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- LISTA -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Equipo</h3>
                            <button onclick="abrirModalNuevo()" style="display:flex;align-items:center;gap:6px;padding:7px 14px;background:#0f172a;color:white;border:none;border-radius:.6rem;font-size:12px;font-weight:700;cursor:pointer;letter-spacing:.03em">
                                <i class="fas fa-plus"></i> Agregar
                            </button>
                        </div>
                        <div style="padding:20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px">
                            <?php
                            $barb_q = $conn->prepare("SELECT * FROM barberos WHERE sucursal_id = ?");
                            $barb_q->bind_param("i", $scope_id);
                            $barb_q->execute();
                            $barb = $barb_q->get_result();
                            $current_barberos_count = $barb->num_rows;
                            
                            $plan_info = get_plan_sucursal($conn, $scope_id);
                            $max_allowed_barberos = $plan_info ? intval($plan_info['max_barberos']) : 1;
                            
                            if ($current_barberos_count === 0):
                            ?>
                            <div style="grid-column:1/-1;text-align:center;padding:48px;color:#94a3b8">
                                <i class="fas fa-user-slash" style="font-size:32px;margin-bottom:12px;display:block"></i>
                                <p style="font-weight:600">Sin barberos registrados.</p>
                            </div>
                            <?php else: while ($b = $barb->fetch_assoc()): ?>
                            <div style="border:1px solid #e2e8f0;border-radius:.875rem;overflow:hidden;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.04);display:flex;flex-direction:column">
                                <!-- foto -->
                                <div style="aspect-ratio:1;background:#f8fafc;position:relative;overflow:hidden">
                                    <?php if (!empty($b['foto_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($b['foto_url']); ?>" style="width:100%;height:100%;object-fit:cover;object-position:top center">
                                    <?php else: ?>
                                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f8fafc,#f1f5f9)">
                                            <i class="fas fa-user" style="font-size:32px;color:#b49363;opacity:.5"></i>
                                        </div>
                                    <?php endif; ?>
                                    <span style="position:absolute;top:6px;right:6px;font-size:8px;font-weight:800;padding:2px 6px;border-radius:2rem;backdrop-filter:blur(4px);<?php echo $b['activo'] ? 'background:rgba(220,252,231,.9);color:#166534' : 'background:rgba(254,226,226,.9);color:#991b1b'; ?>">
                                        <?php echo $b['activo'] ? 'Activo' : 'Oculto'; ?>
                                    </span>
                                </div>
                                <!-- info -->
                                <div style="padding:10px 10px 8px;flex:1;display:flex;flex-direction:column;gap:2px">
                                    <div style="font-weight:800;font-size:12px;color:#0f172a"><?php echo htmlspecialchars($b['nombre']); ?></div>
                                    <div style="font-size:10px;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><i class="fas fa-clock" style="margin-right:3px"></i><?php echo date('h:i A', strtotime($b['hora_inicio'])); ?> – <?php echo date('h:i A', strtotime($b['hora_fin'])); ?></div>
                                </div>
                                <!-- acciones -->
                                <div style="padding:0 10px 10px;display:flex;gap:4px;flex-wrap:wrap;align-items:center;">
                                    <?php if (!$is_basic_plan): ?>
                                        <a href="barbero.php?id=<?php echo $b['id']; ?>" target="_blank" title="Ver Agenda" style="padding:6px;background:#eef2ff;border:none;border-radius:.375rem;font-size:10px;font-weight:700;cursor:pointer;color:#4f46e5;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;"><i class="fas fa-eye"></i></a>
                                    <?php endif; ?>
                                    <button onclick="editarBarbero(<?php echo $b['id']; ?>,'<?php echo htmlspecialchars($b['nombre'],ENT_QUOTES); ?>','<?php echo $b['hora_inicio']; ?>','<?php echo $b['hora_fin']; ?>','<?php echo $b['almuerzo_inicio']; ?>','<?php echo $b['almuerzo_fin']; ?>',<?php echo $b['activo']; ?>)" title="Editar" style="flex:1;padding:6px;background:#f1f5f9;border:none;border-radius:.375rem;font-size:10px;font-weight:700;cursor:pointer;color:#475569;display:inline-flex;align-items:center;justify-content:center;gap:4px;height:28px;"><i class="fas fa-pen"></i><span style="font-size:9px">Editar</span></button>
                                    <form action="../backend/processing/admin.php" method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                        <input type="hidden" name="action" value="delete_barbero">
                                        <input type="hidden" name="id" value="<?php echo $b['id']; ?>">
                                        <button type="submit" title="Eliminar" style="padding:6px;background:#fee2e2;border:none;border-radius:.375rem;font-size:10px;cursor:pointer;color:#991b1b;width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                            <?php endwhile; $barb_q->close(); endif; ?>
                        </div>
                    </div>

                    <!-- BLOQUEOS DE HORARIO -->
                    <?php
                    $blq_suc = $is_scoped ? $scope_id : 1;
                    $blq_res = $conn->prepare("SELECT bh.*, b.nombre AS barbero_nombre FROM bloqueos_horario bh JOIN barberos b ON b.id = bh.barbero_id WHERE bh.sucursal_id = ? AND bh.fecha >= CURDATE() ORDER BY bh.fecha ASC, bh.hora_inicio ASC");
                    $blq_res->bind_param("i", $blq_suc);
                    $blq_res->execute();
                    $bloqueos_list = $blq_res->get_result()->fetch_all(MYSQLI_ASSOC);

                    $barberos_blq = [];
                    $bb_res = $conn->prepare("SELECT id, nombre FROM barberos WHERE activo = 1 AND sucursal_id = ? ORDER BY nombre");
                    $bb_res->bind_param("i", $blq_suc);
                    $bb_res->execute();
                    $bb_r = $bb_res->get_result();
                    while ($bb = $bb_r->fetch_assoc()) $barberos_blq[] = $bb;
                    ?>
                    <div class="card" style="margin-top:20px">
                        <div class="card-header">
                            <h3><i class="fas fa-ban" style="color:#ef4444;margin-right:6px"></i>Bloqueos de Horario</h3>
                        </div>
                        <div style="padding:20px">
                            <form action="../backend/processing/admin.php" method="POST" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:16px;padding:16px;background:#f8fafc;border-radius:.75rem;border:1px solid #e2e8f0">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                <input type="hidden" name="action" value="add_bloqueo">
                                <div style="flex:1;min-width:120px">
                                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:4px">Barbero</div>
                                    <select name="bloqueo_barbero_id" class="form-input" required style="padding:8px 10px;font-size:13px">
                                        <?php foreach ($barberos_blq as $bb): ?>
                                        <option value="<?= $bb['id'] ?>"><?= htmlspecialchars($bb['nombre']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div style="min-width:130px">
                                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:4px">Fecha</div>
                                    <input type="date" name="bloqueo_fecha" class="form-input" required min="<?= date('Y-m-d') ?>" style="padding:8px 10px;font-size:13px">
                                </div>
                                <div style="min-width:90px">
                                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:4px">Desde</div>
                                    <input type="time" name="bloqueo_hora_inicio" class="form-input" id="blq_hora_ini" style="padding:8px 10px;font-size:13px">
                                </div>
                                <div style="min-width:90px">
                                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:4px">Hasta</div>
                                    <input type="time" name="bloqueo_hora_fin" class="form-input" id="blq_hora_fin" style="padding:8px 10px;font-size:13px">
                                </div>
                                <div>
                                    <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:#6b7280;cursor:pointer;white-space:nowrap">
                                        <input type="checkbox" name="bloqueo_dia_completo" value="1" onchange="document.getElementById('blq_hora_ini').disabled=this.checked;document.getElementById('blq_hora_fin').disabled=this.checked" style="width:15px;height:15px">
                                        Día completo
                                    </label>
                                </div>
                                <div style="min-width:120px">
                                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:4px">Motivo</div>
                                    <input type="text" name="bloqueo_motivo" class="form-input" placeholder="Ej. Día libre" style="padding:8px 10px;font-size:13px">
                                </div>
                                <button type="submit" style="padding:8px 16px;background:#0f172a;color:white;border:none;border-radius:.5rem;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap"><i class="fas fa-plus" style="margin-right:4px"></i>Bloquear</button>
                            </form>

                            <?php if (empty($bloqueos_list)): ?>
                            <p style="text-align:center;color:#94a3b8;padding:20px;font-size:13px">No hay bloqueos programados.</p>
                            <?php else: ?>
                            <div style="display:flex;flex-direction:column;gap:8px">
                                <?php foreach ($bloqueos_list as $blq): ?>
                                <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:#fff;border:1px solid #e2e8f0;border-radius:.625rem">
                                    <div style="width:38px;height:38px;border-radius:.5rem;background:#fee2e2;color:#dc2626;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                                        <i class="fas fa-<?= $blq['dia_completo'] ? 'calendar-xmark' : 'clock' ?>" style="font-size:14px"></i>
                                    </div>
                                    <div style="flex:1;min-width:0">
                                        <div style="font-size:13px;font-weight:700;color:#0f172a"><?= htmlspecialchars($blq['barbero_nombre']) ?></div>
                                        <div style="font-size:11px;color:#94a3b8">
                                            <?= date('d/m/Y', strtotime($blq['fecha'])) ?>
                                            <?php if ($blq['dia_completo']): ?>
                                                — Día completo
                                            <?php else: ?>
                                                — <?= date('h:i A', strtotime($blq['hora_inicio'])) ?> a <?= date('h:i A', strtotime($blq['hora_fin'])) ?>
                                            <?php endif; ?>
                                            <?php if ($blq['motivo']): ?> · <?= htmlspecialchars($blq['motivo']) ?><?php endif; ?>
                                        </div>
                                    </div>
                                    <form action="../backend/processing/admin.php" method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar este bloqueo?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                        <input type="hidden" name="action" value="delete_bloqueo">
                                        <input type="hidden" name="bloqueo_id" value="<?= $blq['id'] ?>">
                                        <button type="submit" style="padding:6px 10px;background:#fee2e2;border:none;border-radius:.5rem;font-size:11px;cursor:pointer;color:#991b1b"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <script>
                        function abrirModalNuevo() {
                            const currentCount = <?php echo $current_barberos_count; ?>;
                            const maxAllowed = <?php echo $max_allowed_barberos; ?>;
                            if (currentCount >= maxAllowed) {
                                alert("Tu plan actual (" + (maxAllowed === 1 ? "Básico" : "Suscripción") + ") solo permite registrar un máximo de " + maxAllowed + " barbero. Mejora tu plan para registrar más.");
                                return;
                            }
                            resetBarberoForm();
                            document.getElementById('modalBarberoTitle').textContent = 'Registrar Barbero';
                            document.getElementById('modalBarbero').style.display = 'flex';
                        }
                        function cerrarModal() {
                            document.getElementById('modalBarbero').style.display = 'none';
                        }
                        document.getElementById('modalBarbero').addEventListener('click', function(e) {
                            if (e.target === this) cerrarModal();
                        });
                        function editarBarbero(id, nombre, inicio, fin, almuerzo_in, almuerzo_fi, activo) {
                            resetBarberoForm();
                            document.getElementById('barb_id').value = id;
                            document.getElementById('barb_nombre').value = nombre;
                            document.getElementById('barb_inicio').value = inicio.substring(0, 5);
                            document.getElementById('barb_fin').value = fin.substring(0, 5);
                            document.getElementById('barb_almuerzo_inicio').value = almuerzo_in.substring(0, 5);
                            document.getElementById('barb_almuerzo_fin').value = almuerzo_fi.substring(0, 5);
                            document.getElementById('barb_activo').checked = (activo == 1);
                            document.getElementById('status_container').style.display = 'block';
                            document.getElementById('barb_credentials_section').style.display = 'none';
                            document.getElementById('barb_usuario').removeAttribute('required');
                            document.getElementById('barb_password').removeAttribute('required');
                            document.getElementById('barb_action').value = 'edit_barbero';
                            document.getElementById('modalBarberoTitle').textContent = 'Editar Barbero';
                            document.getElementById('modalBarbero').style.display = 'flex';
                        }
                        function previewBarbFoto(input) {
                            if (!input.files[0]) return;
                            const reader = new FileReader();
                            reader.onload = e => showBarbPreview(e.target.result);
                            reader.readAsDataURL(input.files[0]);
                            document.getElementById('barb_foto_url').value = '';
                        }
                        function previewBarbUrl(url) {
                            if (url) { showBarbPreview(url); document.getElementById('barb_foto_file').value = ''; }
                            else resetBarbPreview();
                        }
                        function showBarbPreview(src) {
                            document.getElementById('barb_foto_img').src = src;
                            document.getElementById('barb_foto_img').style.display = 'block';
                            document.getElementById('barb_foto_placeholder').style.display = 'none';
                        }
                        function resetBarbPreview() {
                            document.getElementById('barb_foto_img').style.display = 'none';
                            document.getElementById('barb_foto_img').src = '';
                            document.getElementById('barb_foto_placeholder').style.display = 'block';
                            document.getElementById('barb_foto_file').value = '';
                            document.getElementById('barb_foto_url').value = '';
                        }
                        function resetBarberoForm() {
                            document.getElementById('barb_id').value = '';
                            document.getElementById('barb_nombre').value = '';
                            document.getElementById('barb_inicio').value = '09:00';
                            document.getElementById('barb_fin').value = '17:00';
                            document.getElementById('barb_almuerzo_inicio').value = '12:00';
                            document.getElementById('barb_almuerzo_fin').value = '13:00';
                            document.getElementById('barb_usuario').value = '';
                            document.getElementById('barb_password').value = '';
                            document.getElementById('status_container').style.display = 'none';
                            document.getElementById('barb_credentials_section').style.display = 'block';
                            document.getElementById('barb_usuario').setAttribute('required', '');
                            document.getElementById('barb_password').setAttribute('required', '');
                            document.getElementById('barb_action').value = 'add_barbero';
                            resetBarbPreview();
                        }
                    </script>

                <?php elseif ($page == 'servicios'):
                    $svc_suc = $is_scoped ? $scope_id : 1;
                    $svc_res = $conn->prepare("SELECT * FROM servicios WHERE sucursal_id = ? ORDER BY orden ASC, id ASC");
                    $svc_res->bind_param("i", $svc_suc);
                    $svc_res->execute();
                    $svc_list = $svc_res->get_result()->fetch_all(MYSQLI_ASSOC);
                ?>
                    <!-- MODAL SERVICIO -->
                    <div id="modalServicio" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);align-items:center;justify-content:center">
                        <div style="background:#fff;border-radius:1rem;padding:28px;width:100%;max-width:400px;margin:16px;box-shadow:0 20px 60px rgba(0,0,0,.2);position:relative">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
                                <h3 id="modalSvcTitle" style="font-size:15px;font-weight:800;color:#0f172a">Agregar Servicio</h3>
                                <button onclick="cerrarModalSvc()" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:18px;line-height:1;padding:4px"><i class="fas fa-times"></i></button>
                            </div>
                            <form action="../backend/processing/admin.php" method="POST" id="svcForm" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                <input type="hidden" id="svc_action" name="action" value="add_servicio">
                                <input type="hidden" id="svc_id" name="svc_id" value="">

                                <!-- upload imagen -->
                                <?php if ($has_service_images): ?>
                                <div class="form-group">
                                    <label class="form-label">Imagen</label>
                                    <div id="svc_foto_preview" onclick="document.getElementById('svc_imagen_file').click()" style="height:100px;border-radius:.75rem;border:2px dashed #e2e8f0;background:#f8fafc;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;position:relative;transition:border-color .2s">
                                        <div id="svc_foto_placeholder" style="text-align:center;color:#94a3b8;pointer-events:none">
                                            <i class="fas fa-image" style="font-size:22px;margin-bottom:6px;display:block"></i>
                                            <span style="font-size:11px;font-weight:600">Subir imagen</span>
                                        </div>
                                        <img id="svc_foto_img" src="" style="display:none;width:100%;height:100%;object-fit:cover;position:absolute;inset:0">
                                    </div>
                                    <input type="file" id="svc_imagen_file" name="svc_imagen_file" accept="image/*" style="display:none" onchange="previewSvcFoto(this)">
                                    <input type="text" id="svc_imagen" name="svc_imagen" class="form-input" placeholder="O pega una URL de imagen" style="margin-top:8px;font-size:12px" oninput="previewSvcImg(this.value)">
                                </div>
                                <?php endif; ?>
                                <div class="form-group">
                                    <label class="form-label">Nombre</label>
                                    <input type="text" id="svc_nombre" name="svc_nombre" class="form-input" placeholder="Ej. Corte Clásico" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Descripción</label>
                                    <input type="text" id="svc_descripcion" name="svc_descripcion" class="form-input" placeholder="Ej. Corte con máquina y tijera, incluye lavado" maxlength="255">
                                </div>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                                    <div class="form-group">
                                        <label class="form-label">Precio</label>
                                        <input type="text" id="svc_precio" name="svc_precio" class="form-input" placeholder="Ej. $8.00">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Duración</label>
                                        <input type="text" id="svc_duracion" name="svc_duracion" class="form-input" placeholder="30 min" value="30 min">
                                    </div>
                                </div>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                                    <div class="form-group">
                                        <label class="form-label">Ícono (FA)</label>
                                        <input type="text" id="svc_icono" name="svc_icono" class="form-input" placeholder="fas fa-cut" value="fas fa-cut">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Orden</label>
                                        <input type="number" id="svc_orden" name="svc_orden" class="form-input" value="0" min="0">
                                    </div>
                                </div>
                                <div class="form-group" id="svc_activo_wrap" style="display:none">
                                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#6b7280;cursor:pointer">
                                        <input type="checkbox" id="svc_activo" name="svc_activo" value="1" style="width:16px;height:16px"> Activo
                                    </label>
                                </div>
                                <div style="display:flex;gap:8px;margin-top:20px">
                                    <button type="submit" class="btn btn-primary" style="flex:1;background:#b49363;color:white;justify-content:center"><i class="fas fa-save" style="margin-right:6px"></i>Guardar</button>
                                    <button type="button" onclick="cerrarModalSvc()" class="btn btn-ghost">Cancelar</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- LISTA -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Servicios</h3>
                            <button onclick="abrirModalSvcNuevo()" style="display:flex;align-items:center;gap:6px;padding:7px 14px;background:#0f172a;color:white;border:none;border-radius:.6rem;font-size:12px;font-weight:700;cursor:pointer;letter-spacing:.03em">
                                <i class="fas fa-plus"></i> Agregar
                            </button>
                        </div>
                        <div style="padding:16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px">
                            <?php if (empty($svc_list)): ?>
                                <div style="grid-column:1/-1;text-align:center;padding:48px;color:#94a3b8">
                                    <i class="fas fa-concierge-bell" style="font-size:32px;margin-bottom:12px;display:block"></i>
                                    <p style="font-weight:600">Sin servicios registrados.</p>
                                </div>
                            <?php else: foreach ($svc_list as $sv): ?>
                            <div style="border:1px solid #e2e8f0;border-radius:.6rem;overflow:hidden;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.04);display:flex;flex-direction:column">
                                <!-- imagen o placeholder -->
                                <div style="aspect-ratio:16/9;background:#f8fafc;position:relative;overflow:hidden">
                                    <?php if (!empty($sv['imagen_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($sv['imagen_url']); ?>" style="width:100%;height:100%;object-fit:cover;object-position:center">
                                    <?php else: ?>
                                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f8fafc,#f1f5f9)">
                                            <i class="<?php echo htmlspecialchars($sv['icono']); ?>" style="font-size:24px;color:#b49363;opacity:.7"></i>
                                        </div>
                                    <?php endif; ?>
                                    <span style="position:absolute;top:4px;right:4px;font-size:9px;font-weight:800;padding:2px 6px;border-radius:2rem;backdrop-filter:blur(4px);<?php echo $sv['activo'] ? 'background:rgba(220,252,231,.9);color:#166534' : 'background:rgba(254,226,226,.9);color:#991b1b'; ?>">
                                        <?php echo $sv['activo'] ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </div>
                                <!-- info -->
                                <div style="padding:8px 10px 4px;flex:1;display:flex;flex-direction:column;gap:2px">
                                    <div style="font-weight:800;font-size:12px;color:#0f172a;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($sv['nombre']); ?>"><?php echo htmlspecialchars($sv['nombre']); ?></div>
                                    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:2px">
                                        <span style="font-size:11px;font-weight:700;color:#b49363"><?php echo htmlspecialchars($sv['precio']); ?></span>
                                        <span style="font-size:10px;color:#94a3b8"><i class="fas fa-clock" style="margin-right:3px"></i><?php echo htmlspecialchars($sv['duracion']); ?></span>
                                    </div>
                                </div>
                                <!-- acciones -->
                                <div style="padding:0 10px 10px;display:flex;gap:4px">
                                    <button onclick="editSvc(<?php echo $sv['id']; ?>,'<?php echo htmlspecialchars($sv['nombre'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($sv['precio'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($sv['duracion'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($sv['icono'],ENT_QUOTES); ?>',<?php echo $sv['activo']; ?>,<?php echo $sv['orden']; ?>,'<?php echo htmlspecialchars($sv['imagen_url']??'',ENT_QUOTES); ?>','<?php echo htmlspecialchars($sv['descripcion']??'',ENT_QUOTES); ?>')" style="flex:1;padding:5px;background:#f1f5f9;border:none;border-radius:.4rem;font-size:10px;font-weight:700;cursor:pointer;color:#475569"><i class="fas fa-pen" style="margin-right:4px"></i>Editar</button>
                                    <form action="../backend/processing/admin.php" method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar este servicio?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                        <input type="hidden" name="action" value="delete_servicio">
                                        <input type="hidden" name="svc_id" value="<?php echo $sv['id']; ?>">
                                        <button type="submit" style="padding:5px 8px;background:#fee2e2;border:none;border-radius:.4rem;font-size:10px;cursor:pointer;color:#991b1b;height:24px;"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <script>
                    function abrirModalSvcNuevo() {
                        resetSvcForm();
                        document.getElementById('modalSvcTitle').textContent = 'Agregar Servicio';
                        document.getElementById('modalServicio').style.display = 'flex';
                    }
                    function cerrarModalSvc() {
                        document.getElementById('modalServicio').style.display = 'none';
                    }
                    document.getElementById('modalServicio').addEventListener('click', function(e) {
                        if (e.target === this) cerrarModalSvc();
                    });
                    <?php if ($has_service_images): ?>
                    function previewSvcFoto(input) {
                        if (!input.files[0]) return;
                        const reader = new FileReader();
                        reader.onload = e => showSvcPreview(e.target.result);
                        reader.readAsDataURL(input.files[0]);
                        document.getElementById('svc_imagen').value = '';
                    }
                    function previewSvcImg(url) {
                        if (url) { showSvcPreview(url); document.getElementById('svc_imagen_file').value = ''; }
                        else resetSvcPreview();
                    }
                    function showSvcPreview(src) {
                        document.getElementById('svc_foto_img').src = src;
                        document.getElementById('svc_foto_img').style.display = 'block';
                        document.getElementById('svc_foto_placeholder').style.display = 'none';
                    }
                    function resetSvcPreview() {
                        document.getElementById('svc_foto_img').style.display = 'none';
                        document.getElementById('svc_foto_img').src = '';
                        document.getElementById('svc_foto_placeholder').style.display = 'block';
                        document.getElementById('svc_imagen_file').value = '';
                        document.getElementById('svc_imagen').value = '';
                    }
                    <?php else: ?>
                    function previewSvcFoto(){}
                    function previewSvcImg(){}
                    function showSvcPreview(){}
                    function resetSvcPreview(){}
                    <?php endif; ?>
                    function editSvc(id, nombre, precio, duracion, icono, activo, orden, imagen, descripcion) {
                        resetSvcForm();
                        document.getElementById('svc_id').value          = id;
                        document.getElementById('svc_nombre').value      = nombre;
                        document.getElementById('svc_precio').value      = precio;
                        document.getElementById('svc_duracion').value    = duracion;
                        document.getElementById('svc_icono').value       = icono;
                        document.getElementById('svc_orden').value       = orden;
                        document.getElementById('svc_descripcion').value = descripcion || '';
                        document.getElementById('svc_activo').checked    = (activo == 1);
                        document.getElementById('svc_activo_wrap').style.display = 'block';
                        document.getElementById('svc_action').value  = 'edit_servicio';
                        document.getElementById('modalSvcTitle').textContent = 'Editar Servicio';
                        document.getElementById('modalServicio').style.display = 'flex';
                        <?php if ($has_service_images): ?>
                        if (imagen) { document.getElementById('svc_imagen').value = imagen; showSvcPreview(imagen); }
                        <?php endif; ?>
                    }
                    function resetSvcForm() {
                        document.getElementById('svcForm').reset();
                        document.getElementById('svc_id').value          = '';
                        document.getElementById('svc_action').value      = 'add_servicio';
                        document.getElementById('svc_activo_wrap').style.display = 'none';
                        document.getElementById('svc_duracion').value    = '30 min';
                        document.getElementById('svc_icono').value       = 'fas fa-cut';
                        document.getElementById('svc_orden').value       = '0';
                        document.getElementById('svc_descripcion').value = '';
                        resetSvcPreview();
                    }
                    </script>

                <?php elseif ($page == 'productos' && $has_productos):
                    $prod_suc = $is_scoped ? $scope_id : 1;
                    $prod_res = $conn->prepare("SELECT * FROM productos WHERE sucursal_id = ? ORDER BY nombre ASC");
                    $prod_res->bind_param("i", $prod_suc);
                    $prod_res->execute();
                    $prod_list = $prod_res->get_result()->fetch_all(MYSQLI_ASSOC);
                ?>

                    <!-- MODAL PRODUCTO -->
                    <div id="modalProducto" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.45);backdrop-filter:blur(2px);align-items:center;justify-content:center">
                        <div style="background:#fff;border-radius:1rem;padding:28px;width:100%;max-width:400px;margin:16px;box-shadow:0 20px 60px rgba(0,0,0,.2);position:relative;max-height:90vh;overflow-y:auto">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
                                <h3 id="modalProdTitle" style="font-size:15px;font-weight:800;color:#0f172a">Agregar Producto</h3>
                                <button onclick="cerrarModalProd()" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:18px;padding:4px"><i class="fas fa-times"></i></button>
                            </div>
                            <form action="../backend/processing/admin.php" method="POST" id="prodForm" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                <input type="hidden" id="prod_action" name="action" value="add_producto">
                                <input type="hidden" id="prod_id" name="prod_id" value="">

                                <div class="form-group">
                                    <label class="form-label">Imagen</label>
                                    <div id="prod_foto_preview" onclick="document.getElementById('prod_imagen_file').click()" style="height:100px;border-radius:.75rem;border:2px dashed #e2e8f0;background:#f8fafc;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;position:relative">
                                        <div id="prod_foto_placeholder" style="text-align:center;color:#94a3b8;pointer-events:none">
                                            <i class="fas fa-image" style="font-size:22px;margin-bottom:6px;display:block"></i>
                                            <span style="font-size:11px;font-weight:600">Subir imagen</span>
                                        </div>
                                        <img id="prod_foto_img" src="" style="display:none;width:100%;height:100%;object-fit:cover;position:absolute;inset:0">
                                    </div>
                                    <input type="file" id="prod_imagen_file" name="prod_imagen_file" accept="image/*" style="display:none" onchange="prevProdFile(this)">
                                    <input type="text" id="prod_imagen" name="prod_imagen" class="form-input" placeholder="O pega una URL" style="margin-top:8px;font-size:12px" oninput="prevProdUrl(this.value)">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Nombre</label>
                                    <input type="text" id="prod_nombre" name="prod_nombre" class="form-input" placeholder="Ej. Cera para cabello" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Descripción</label>
                                    <input type="text" id="prod_descripcion" name="prod_descripcion" class="form-input" placeholder="Descripción breve" maxlength="255">
                                </div>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                                    <div class="form-group">
                                        <label class="form-label">Precio ($)</label>
                                        <input type="number" id="prod_precio" name="prod_precio" class="form-input" placeholder="0.00" step="0.01" min="0" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Stock</label>
                                        <input type="number" id="prod_stock" name="prod_stock" class="form-input" value="0" min="0" required>
                                    </div>
                                </div>
                                <div class="form-group" id="prod_activo_wrap" style="display:none">
                                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#6b7280;cursor:pointer">
                                        <input type="checkbox" id="prod_activo" name="prod_activo" value="1" style="width:16px;height:16px"> Visible para clientes
                                    </label>
                                </div>
                                <div style="display:flex;gap:8px;margin-top:20px">
                                    <button type="submit" class="btn btn-primary" style="flex:1;background:#b49363;color:white;justify-content:center"><i class="fas fa-save" style="margin-right:6px"></i>Guardar</button>
                                    <button type="button" onclick="cerrarModalProd()" class="btn" style="background:#f1f5f9;color:#64748b">Cancelar</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- LISTA PRODUCTOS -->
                    <div class="card">
                        <div class="card-header">
                            <h3>Productos a la Venta</h3>
                            <button onclick="abrirModalProdNuevo()" style="display:flex;align-items:center;gap:6px;padding:7px 14px;background:#0f172a;color:white;border:none;border-radius:.6rem;font-size:12px;font-weight:700;cursor:pointer">
                                <i class="fas fa-plus"></i> Agregar
                            </button>
                        </div>
                        <div style="padding:20px;display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px">
                            <?php if (empty($prod_list)): ?>
                            <div style="grid-column:1/-1;text-align:center;padding:48px;color:#94a3b8">
                                <i class="fas fa-box-open" style="font-size:32px;margin-bottom:12px;display:block"></i>
                                <p style="font-weight:600">Sin productos registrados.</p>
                            </div>
                            <?php else: foreach ($prod_list as $pr): ?>
                            <div style="border:1px solid #e2e8f0;border-radius:.875rem;overflow:hidden;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.04);display:flex;flex-direction:column">
                                <div style="aspect-ratio:4/3;background:#f8fafc;position:relative;overflow:hidden">
                                    <?php if (!empty($pr['imagen_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($pr['imagen_url']); ?>" style="width:100%;height:100%;object-fit:cover">
                                    <?php else: ?>
                                        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f8fafc,#f1f5f9)">
                                            <i class="fas fa-box" style="font-size:36px;color:#b49363;opacity:.5"></i>
                                        </div>
                                    <?php endif; ?>
                                    <span style="position:absolute;top:8px;right:8px;font-size:10px;font-weight:800;padding:3px 8px;border-radius:2rem;backdrop-filter:blur(4px);<?php echo $pr['activo'] ? 'background:rgba(220,252,231,.9);color:#166534' : 'background:rgba(254,226,226,.9);color:#991b1b'; ?>">
                                        <?php echo $pr['activo'] ? 'Visible' : 'Oculto'; ?>
                                    </span>
                                    <?php if ($pr['stock'] <= 0): ?>
                                    <span style="position:absolute;top:8px;left:8px;font-size:10px;font-weight:800;padding:3px 8px;border-radius:2rem;background:rgba(254,226,226,.9);color:#991b1b">Agotado</span>
                                    <?php endif; ?>
                                </div>
                                <div style="padding:14px 14px 10px;flex:1;display:flex;flex-direction:column;gap:4px">
                                    <div style="font-weight:800;font-size:14px;color:#0f172a"><?php echo htmlspecialchars($pr['nombre']); ?></div>
                                    <?php if ($pr['descripcion']): ?>
                                    <div style="font-size:11px;color:#94a3b8;line-height:1.3"><?php echo htmlspecialchars($pr['descripcion']); ?></div>
                                    <?php endif; ?>
                                    <div style="display:flex;align-items:center;gap:10px;margin-top:4px">
                                        <span style="font-size:14px;font-weight:800;color:#b49363">$<?php echo number_format($pr['precio'],2); ?></span>
                                        <span style="font-size:11px;color:#64748b"><i class="fas fa-cubes" style="margin-right:3px"></i><?php echo $pr['stock']; ?> en stock</span>
                                    </div>
                                </div>
                                <div style="padding:0 14px 12px;display:flex;gap:6px">
                                    <button onclick="editProd(<?php echo htmlspecialchars(json_encode($pr),ENT_QUOTES); ?>)" style="flex:1;padding:6px;background:#f1f5f9;border:none;border-radius:.5rem;font-size:11px;font-weight:700;cursor:pointer;color:#475569"><i class="fas fa-pen" style="margin-right:4px"></i>Editar</button>
                                    <form action="../backend/processing/admin.php" method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar producto?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                        <input type="hidden" name="action" value="delete_producto">
                                        <input type="hidden" name="prod_id" value="<?php echo $pr['id']; ?>">
                                        <button type="submit" style="padding:6px 10px;background:#fee2e2;border:none;border-radius:.5rem;font-size:11px;cursor:pointer;color:#991b1b"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <script>
                    function abrirModalProdNuevo(){
                        resetProdForm();
                        document.getElementById('modalProdTitle').textContent='Agregar Producto';
                        document.getElementById('modalProducto').style.display='flex';
                    }
                    function cerrarModalProd(){ document.getElementById('modalProducto').style.display='none'; }
                    document.getElementById('modalProducto').addEventListener('click',function(e){ if(e.target===this) cerrarModalProd(); });
                    function prevProdFile(input){
                        if(!input.files[0])return;
                        const r=new FileReader();r.onload=e=>{document.getElementById('prod_foto_img').src=e.target.result;document.getElementById('prod_foto_img').style.display='block';document.getElementById('prod_foto_placeholder').style.display='none';};
                        r.readAsDataURL(input.files[0]);document.getElementById('prod_imagen').value='';
                    }
                    function prevProdUrl(url){
                        if(url){document.getElementById('prod_foto_img').src=url;document.getElementById('prod_foto_img').style.display='block';document.getElementById('prod_foto_placeholder').style.display='none';document.getElementById('prod_imagen_file').value='';}
                        else resetProdPreview();
                    }
                    function resetProdPreview(){
                        document.getElementById('prod_foto_img').style.display='none';document.getElementById('prod_foto_img').src='';
                        document.getElementById('prod_foto_placeholder').style.display='block';
                        document.getElementById('prod_imagen_file').value='';document.getElementById('prod_imagen').value='';
                    }
                    function editProd(p){
                        resetProdForm();
                        document.getElementById('prod_id').value=p.id;
                        document.getElementById('prod_nombre').value=p.nombre;
                        document.getElementById('prod_descripcion').value=p.descripcion||'';
                        document.getElementById('prod_precio').value=p.precio;
                        document.getElementById('prod_stock').value=p.stock;
                        document.getElementById('prod_activo').checked=(p.activo==1);
                        document.getElementById('prod_activo_wrap').style.display='block';
                        document.getElementById('prod_action').value='edit_producto';
                        document.getElementById('modalProdTitle').textContent='Editar Producto';
                        if(p.imagen_url){document.getElementById('prod_imagen').value=p.imagen_url;prevProdUrl(p.imagen_url);}
                        document.getElementById('modalProducto').style.display='flex';
                    }
                    function resetProdForm(){
                        document.getElementById('prodForm').reset();
                        document.getElementById('prod_id').value='';
                        document.getElementById('prod_action').value='add_producto';
                        document.getElementById('prod_activo_wrap').style.display='none';
                        document.getElementById('prod_stock').value='0';
                        resetProdPreview();
                    }
                    </script>

                <?php elseif ($page == 'pedidos' && $has_productos): ?>
                    <div class="card">
                        <div class="card-header">Pedidos de Clientes (Tienda)</div>
                        <div class="card-content">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Cliente</th>
                                        <th>Fecha</th>
                                        <th>Artículos</th>
                                        <th>Total</th>
                                        <th>Pago</th>
                                        <th>Estado</th>
                                        <th>Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $limit_peds = 10;
                                    $page_peds = isset($_GET['p_peds']) ? max(1, intval($_GET['p_peds'])) : 1;
                                    $offset_peds = ($page_peds - 1) * $limit_peds;

                                    $stmt_cnt = $conn->prepare("SELECT COUNT(*) as total FROM pedidos WHERE sucursal_id = ?");
                                    $stmt_cnt->bind_param("i", $scope_id);
                                    $stmt_cnt->execute();
                                    $total_peds = $stmt_cnt->get_result()->fetch_assoc()['total'];
                                    $stmt_cnt->close();

                                    $total_pages_peds = ceil($total_peds / $limit_peds);

                                    $stmt_peds = $conn->prepare("SELECT * FROM pedidos WHERE sucursal_id = ? ORDER BY id DESC LIMIT ? OFFSET ?");
                                    $stmt_peds->bind_param("iii", $scope_id, $limit_peds, $offset_peds);
                                    $stmt_peds->execute();
                                    $peds_res = $stmt_peds->get_result();

                                    if ($peds_res->num_rows === 0):
                                    ?>
                                        <tr>
                                            <td colspan="7" style="text-align:center; padding:32px; color:#94a3b8;">Sin pedidos registrados.</td>
                                        </tr>
                                    <?php
                                    else:
                                        while ($ped = $peds_res->fetch_assoc()):
                                            // Fetch order details
                                            $stmt_det = $conn->prepare("SELECT * FROM pedido_detalles WHERE pedido_id = ?");
                                            $stmt_det->bind_param("i", $ped['id']);
                                            $stmt_det->execute();
                                            $details = $stmt_det->get_result()->fetch_all(MYSQLI_ASSOC);
                                            $stmt_det->close();
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($ped['cliente_nombre']); ?></strong><br>
                                            <small style="color: #9ca3af;"><?php echo htmlspecialchars($ped['cliente_telefono']); ?></small>
                                        </td>
                                        <td>
                                            <div><?php echo date('d/m/Y', strtotime($ped['fecha'])); ?></div>
                                            <small style="color: #9ca3af;"><?php echo date('h:i A', strtotime($ped['fecha'])); ?></small>
                                        </td>
                                        <td>
                                            <div style="font-size:11px; line-height:1.4;">
                                                <?php foreach ($details as $det): ?>
                                                    <div>• <?php echo htmlspecialchars($det['nombre_producto']); ?> (x<?php echo $det['cantidad']; ?>)</div>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong style="color: #b49363;">$<?php echo number_format($ped['total'], 2); ?></strong>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($ped['metodo_pago']); ?></div>
                                            <small style="color: #9ca3af; font-family: monospace;">#<?php echo htmlspecialchars($ped['referencia_pago'] ?: 'N/A'); ?></small>
                                            <br>
                                            <?php if ($ped['estado_pago'] == 'pendiente'): ?>
                                                <span class="badge badge-pending" style="margin-top:4px; display:inline-block;">Pago Pendiente</span>
                                            <?php else: ?>
                                                <span class="badge badge-verified" style="margin-top:4px; display:inline-block;">Pago OK</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                                $es = $ped['estado'] ?? 'pendiente';
                                                $es_style = [
                                                    'pendiente'  => 'background:#fef3c7;color:#d97706',
                                                    'completado' => 'background:#dcfce7;color:#166534',
                                                    'cancelado'  => 'background:#fee2e2;color:#991b1b',
                                                ][$es] ?? 'background:#f3f4f6;color:#6b7280';
                                            ?>
                                            <span class="badge" style="<?php echo $es_style; ?>"><?php echo ucfirst($es); ?></span>
                                        </td>
                                        <td>
                                            <div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
                                            <?php if ($ped['estado'] === 'pendiente'): ?>
                                                <?php if ($ped['estado_pago'] === 'pendiente'): ?>
                                                    <form action="../backend/processing/admin.php" method="POST" style="display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                                        <input type="hidden" name="action" value="aprobar_pago_pedido">
                                                        <input type="hidden" name="pedido_id" value="<?php echo $ped['id']; ?>">
                                                        <button type="submit" class="btn btn-primary" style="padding:4px 8px; font-size:10px;">Aprobar Pago</button>
                                                    </form>
                                                <?php endif; ?>
                                                
                                                <form action="../backend/processing/admin.php" method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                                    <input type="hidden" name="action" value="completar_pedido">
                                                    <input type="hidden" name="pedido_id" value="<?php echo $ped['id']; ?>">
                                                    <button type="submit" class="btn btn-primary" style="padding:4px 8px; font-size:10px; background:#10b981; border:none; color:white;">Completar</button>
                                                </form>

                                                <form action="../backend/processing/admin.php" method="POST" style="display: inline;" onsubmit="return confirm('¿Cancelar este pedido y devolver stock?')">
                                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                                    <input type="hidden" name="action" value="cancelar_pedido">
                                                    <input type="hidden" name="pedido_id" value="<?php echo $ped['id']; ?>">
                                                    <button type="submit" style="padding:4px 8px; background:#fee2e2; border:none; border-radius:.4rem; font-size:10px; cursor:pointer; color:#991b1b; height:26px;">Cancelar</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #9ca3af; font-size: 11px;">Terminado</span>
                                            <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; $stmt_peds->close(); endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($total_pages_peds > 1): ?>
                        <div class="pagination-container">
                            <span>Mostrando <?= min($total_peds, $offset_peds + 1) ?>-<?= min($total_peds, $offset_peds + $limit_peds) ?> de <?= $total_peds ?> pedidos</span>
                            <div class="pagination-buttons">
                                <?php
                                $prev_params = $_GET;
                                $prev_params['p_peds'] = max(1, $page_peds - 1);
                                $prev_url = '?' . http_build_query($prev_params);
                                $disabled_prev = ($page_peds == 1) ? 'disabled' : '';
                                ?>
                                <a href="<?= $prev_url ?>" class="pagination-btn <?= $disabled_prev ?>"><i class="fas fa-chevron-left"></i></a>

                                <?php for ($i = 1; $i <= $total_pages_peds; $i++): 
                                    $page_params = $_GET;
                                    $page_params['p_peds'] = $i;
                                    $page_url = '?' . http_build_query($page_params);
                                    $active_class = ($i == $page_peds) ? 'active' : '';
                                ?>
                                    <a href="<?= $page_url ?>" class="pagination-btn <?= $active_class ?>"><?= $i ?></a>
                                <?php endfor; ?>

                                <?php
                                $next_params = $_GET;
                                $next_params['p_peds'] = min($total_pages_peds, $page_peds + 1);
                                $next_url = '?' . http_build_query($next_params);
                                $disabled_next = ($page_peds == $total_pages_peds) ? 'disabled' : '';
                                ?>
                                <a href="<?= $next_url ?>" class="pagination-btn <?= $disabled_next ?>"><i class="fas fa-chevron-right"></i></a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($page == 'ajustes'): ?>
                    <div class="card">
                        <div class="card-header">Configuración General del Negocio</div>
                        <div style="padding: 20px;">
                            <form action="../backend/processing/admin.php" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                <input type="hidden" name="action" value="update_sys_settings">

                                <!-- INFORMACIÓN DEL NEGOCIO & LOGO -->
                                <div style="margin-bottom: 16px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; background: #fafbfc;">
                                    <div style="font-weight: 700; color: #111827; margin-bottom: 10px; font-size: 12px; display:flex; align-items:center; gap:6px;">
                                        <i class="fas fa-store" style="color: #b49363;"></i> Datos de la Marca (Tienda)
                                    </div>
                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                                        <div class="form-group" style="margin-bottom: 0;">
                                            <label class="form-label" style="font-size:11px; margin-bottom:4px;">Nombre del Negocio / Tienda</label>
                                            <input type="text" name="nombre_negocio" class="form-input" placeholder="Ej. Mi Negocio Pro" value="<?php echo htmlspecialchars($config['nombre_negocio'] ?? ''); ?>" style="font-size:11px; padding:6px 10px; height:32px;">
                                            <small style="color:#9ca3af;font-size:10px;margin-top:2px;display:block">Modifica el nombre de tu barbería.</small>
                                        </div>
                                        <div class="form-group" style="margin-bottom: 0;">
                                            <label class="form-label" style="font-size:11px; margin-bottom:4px;">Logo del Negocio</label>
                                            <div style="display:flex;align-items:center;gap:10px">
                                                <?php if (!empty($config['logo_url'])): ?>
                                                    <img src="<?php echo htmlspecialchars($config['logo_url']); ?>" style="height:32px;max-width:80px;object-fit:contain;border:1px solid #e2e8f0;border-radius:6px;padding:2px;background:#fff">
                                                <?php endif; ?>
                                                <input type="file" name="logo_file" accept="image/*" class="form-input" style="font-size:11px; padding:4px 8px; height:32px;">
                                            </div>
                                            <input type="text" name="logo_url" class="form-input" placeholder="O pega una URL directa de tu logo" value="<?php echo htmlspecialchars($config['logo_url'] ?? ''); ?>" style="margin-top:6px;font-size:11px; padding:6px 10px; height:32px;">
                                        </div>
                                    </div>
                                </div>

                                <div style="font-weight: 700; color: #111827; margin-bottom: 16px; font-size: 14px; border-top:1px solid #e5e7eb; padding-top:20px">
                                    <i class="fas fa-wallet" style="color: #3b82f6; margin-right:8px"></i> Métodos de Pago
                                </div>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; padding-bottom: 12px; align-items: start;">
                                     <!-- PAGO MÓVIL -->
                                     <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; background: #ffffff;">
                                         <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                             <span style="font-weight: 700; color: #111827; font-size: 13px;">
                                                 <i class="fas fa-mobile-alt" style="color: #3b82f6; margin-right: 6px;"></i> Pago Móvil
                                             </span>
                                             <label class="toggle-switch">
                                                 <input type="checkbox" name="estado_pago_movil" value="1" <?php echo ($config['estado_pago_movil'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                                 <span class="toggle-slider"></span>
                                             </label>
                                         </div>
                                         <div class="form-group" style="margin-bottom: 6px;">
                                             <input type="text" name="banco_nombre" class="form-input" placeholder="Banco" value="<?php echo htmlspecialchars($config['banco_nombre'] ?? ''); ?>" style="font-size:11px; padding: 4px 8px; height: 30px;">
                                         </div>
                                         <div class="form-group" style="margin-bottom: 6px;">
                                             <input type="text" name="banco_telefono" class="form-input" placeholder="Teléfono" value="<?php echo htmlspecialchars($config['banco_telefono'] ?? ''); ?>" style="font-size:11px; padding: 4px 8px; height: 30px;">
                                         </div>
                                         <div class="form-group" style="margin-bottom: 0;">
                                             <input type="text" name="banco_ci" class="form-input" placeholder="Cédula/RIF" value="<?php echo htmlspecialchars($config['banco_ci'] ?? ''); ?>" style="font-size:11px; padding: 4px 8px; height: 30px;">
                                         </div>
                                     </div>

                                     <!-- ZELLE -->
                                     <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; background: #ffffff;">
                                         <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                             <span style="font-weight: 700; color: #111827; font-size: 13px;">
                                                 <i class="fas fa-dollar-sign" style="color: #8b5cf6; margin-right: 6px;"></i> Zelle
                                             </span>
                                             <label class="toggle-switch">
                                                 <input type="checkbox" name="estado_zelle" value="1" <?php echo ($config['estado_zelle'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                                 <span class="toggle-slider"></span>
                                             </label>
                                         </div>
                                         <div class="form-group" style="margin-bottom: 0;">
                                             <input type="email" name="zelle_email" class="form-input" placeholder="Email Zelle" value="<?php echo htmlspecialchars($config['zelle_email'] ?? ''); ?>" style="font-size:11px; padding: 4px 8px; height: 30px;">
                                         </div>
                                     </div>

                                     <!-- BINANCE -->
                                     <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; background: #ffffff;">
                                         <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                             <span style="font-weight: 700; color: #111827; font-size: 13px;">
                                                 <i class="fas fa-coins" style="color: #f3ba2f; margin-right: 6px;"></i> Binance Pay
                                             </span>
                                             <label class="toggle-switch">
                                                 <input type="checkbox" name="estado_binance" value="1" <?php echo ($config['estado_binance'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                                 <span class="toggle-slider"></span>
                                             </label>
                                         </div>
                                         <div class="form-group" style="margin-bottom: 0;">
                                             <input type="text" name="binance_pay_id" class="form-input" placeholder="Dirección Binance Pay / Email / ID" value="<?php echo htmlspecialchars($config['binance_pay_id'] ?? ''); ?>" style="font-size:11px; padding: 4px 8px; height: 30px;">
                                         </div>
                                     </div>

                                     <!-- PAYPAL -->
                                     <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; background: #ffffff;">
                                         <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                             <span style="font-weight: 700; color: #111827; font-size: 13px;">
                                                 <i class="fab fa-paypal" style="color: #003087; margin-right: 6px;"></i> PayPal
                                             </span>
                                             <label class="toggle-switch">
                                                 <input type="checkbox" name="estado_paypal" value="1" <?php echo ($config['estado_paypal'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                                 <span class="toggle-slider"></span>
                                             </label>
                                         </div>
                                         <div class="form-group" style="margin-bottom: 0;">
                                             <input type="email" name="paypal_email" class="form-input" placeholder="Dirección / Email PayPal" value="<?php echo htmlspecialchars($config['paypal_email'] ?? ''); ?>" style="font-size:11px; padding: 4px 8px; height: 30px;">
                                         </div>
                                     </div>

                                     <!-- EFECTIVO -->
                                     <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; background: #ffffff;">
                                         <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                             <span style="font-weight: 700; color: #111827; font-size: 13px;">
                                                 <i class="fas fa-money-bill-wave" style="color: #10b981; margin-right: 6px;"></i> Efectivo
                                             </span>
                                             <label class="toggle-switch">
                                                 <input type="checkbox" name="estado_efectivo" value="1" <?php echo ($config['estado_efectivo'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                                 <span class="toggle-slider"></span>
                                             </label>
                                         </div>
                                         <small style="color: #9ca3af; font-size: 10px; display: block; line-height: 1.3;">Pago en el local sin referencias online.</small>
                                     </div>
                                 </div>

                                <!-- WHATSAPP / CONTACTO -->
                                <div style="margin-top: 24px; border-top: 1px solid #e5e7eb; padding-top: 20px;">
                                    <div style="font-weight: 700; color: #111827; margin-bottom: 16px; font-size: 14px;">
                                        <i class="fab fa-whatsapp" style="color: #25d366; margin-right: 8px;"></i> WhatsApp y Contacto
                                    </div>
                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                                        <div class="form-group">
                                            <label class="form-label">Teléfono de contacto / WhatsApp</label>
                                            <input type="text" name="contacto" class="form-input" placeholder="Ej. 04121234567" value="<?php echo htmlspecialchars($config['contacto'] ?? ''); ?>">
                                            <small style="color:#9ca3af;font-size:11px;margin-top:4px;display:block">Número al que se enviarán las confirmaciones de cita.</small>
                                        </div>
                                        <div class="form-group" style="grid-column:1/-1">
                                            <label class="form-label">Mensaje de confirmación WhatsApp</label>
                                            <?php
                                                $wa_default = "Hola {nombre}, tu cita en {sucursal} ha sido confirmada.\n\nServicio: {servicio}\nFecha: {fecha}\nHora: {hora}\n\n¡Te esperamos!";
                                                $wa_actual = $config['wa_plantilla'] ?? '';
                                            ?>
                                            <textarea name="wa_plantilla" class="form-input" rows="5" placeholder="<?= htmlspecialchars($wa_default) ?>" style="resize:vertical;font-size:13px;line-height:1.5"><?= htmlspecialchars($wa_actual) ?></textarea>
                                            <div style="margin-top:8px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px">
                                                <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:6px">Variables disponibles</div>
                                                <div style="display:flex;flex-wrap:wrap;gap:6px">
                                                    <?php foreach (['{nombre}' => 'Nombre del cliente', '{servicio}' => 'Servicio reservado', '{fecha}' => 'Fecha de la cita', '{hora}' => 'Hora de la cita', '{sucursal}' => 'Nombre de tu negocio'] as $var => $desc): ?>
                                                    <span style="font-size:11px;padding:3px 8px;background:#eef2ff;color:#3730a3;border-radius:4px;font-weight:600;cursor:default" title="<?= $desc ?>"><?= $var ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                                <small style="display:block;margin-top:6px;color:#94a3b8;font-size:11px">Si lo dejas vacío se usará el mensaje por defecto.</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- APARIENCIA -->
                                <?php if ($has_custom_colors): ?>
                                <div style="margin-top: 28px; border-top: 1px solid #e5e7eb; padding-top: 24px;">
                                    <div style="font-weight: 700; color: #111827; margin-bottom: 16px; font-size: 14px;">
                                        <i class="fas fa-palette" style="color: #b49363; margin-right: 8px;"></i> Identidad Visual de tu Página
                                    </div>
                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px;">
                                        <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px;">
                                            <div style="font-weight: 600; color: #374151; margin-bottom: 10px; font-size: 13px;">Color Primario</div>
                                            <div style="display: flex; align-items: center; gap: 12px;">
                                                <input type="color" name="color_primario" value="<?php echo htmlspecialchars($config['color_primario'] ?? '#1a3461'); ?>"
                                                    style="width: 48px; height: 48px; border: none; border-radius: 6px; cursor: pointer; padding: 2px;">
                                                <div>
                                                    <div style="font-size: 12px; color: #6b7280;">Encabezado, fondo oscuro</div>
                                                    <code style="font-size: 11px; color: #9ca3af;" id="hex_primario"><?php echo htmlspecialchars($config['color_primario'] ?? '#1a3461'); ?></code>
                                                </div>
                                            </div>
                                        </div>
                                        <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px;">
                                            <div style="font-weight: 600; color: #374151; margin-bottom: 10px; font-size: 13px;">Color de Acento</div>
                                            <div style="display: flex; align-items: center; gap: 12px;">
                                                <input type="color" name="color_acento" value="<?php echo htmlspecialchars($config['color_acento'] ?? '#c49a4a'); ?>"
                                                    style="width: 48px; height: 48px; border: none; border-radius: 6px; cursor: pointer; padding: 2px;">
                                                <div>
                                                    <div style="font-size: 12px; color: #6b7280;">Botones, íconos, destacados</div>
                                                    <code style="font-size: 11px; color: #9ca3af;" id="hex_acento"><?php echo htmlspecialchars($config['color_acento'] ?? '#c49a4a'); ?></code>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <p style="margin-top: 10px; font-size: 12px; color: #9ca3af;">Los cambios se reflejan en la página de reservas de tus clientes.</p>
                                </div>
                                <?php else: ?>
                                <div style="margin-top: 28px; border-top: 1px solid #e5e7eb; padding-top: 24px;">
                                    <div style="display:flex;align-items:center;gap:10px;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;color:#64748b">
                                        <i class="fas fa-lock" style="color:#94a3b8"></i>
                                        <div>
                                            <div style="font-weight:700;font-size:13px;color:#374151">Personalización visual</div>
                                            <div style="font-size:12px">Disponible desde el plan Profesional.</div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div style="margin-top: 24px; text-align: right;">
                                    <button type="submit" class="btn" style="background: #b49363; color: white; padding: 10px 24px;">
                                        <i class="fas fa-save" style="margin-right: 6px;"></i> Guardar Ajustes
                                    </button>
                                </div>
                            </form>
                            <?php if ($has_custom_colors): ?>
                            <script>
                                document.querySelector('[name="color_primario"]').addEventListener('input', function() {
                                    document.getElementById('hex_primario').textContent = this.value;
                                });
                                document.querySelector('[name="color_acento"]').addEventListener('input', function() {
                                    document.getElementById('hex_acento').textContent = this.value;
                                });
                            </script>
                            <?php endif; ?>

                            <?php if ($mi_token): ?>
                            <div style="margin-top:28px;border-top:1px solid #e5e7eb;padding-top:24px">
                                <div style="font-weight:700;color:#111827;margin-bottom:12px;font-size:14px">
                                    <i class="fas fa-link" style="color:#b49363;margin-right:8px"></i> Enlace de Reservas para Clientes
                                </div>
                                <p style="font-size:12px;color:#6b7280;margin-bottom:12px">Comparte este enlace con tus clientes para que puedan hacer sus reservas.</p>
                                <div style="display:flex;gap:8px;align-items:center;background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:12px 16px">
                                    <i class="fas fa-globe" style="color:#b49363;flex-shrink:0"></i>
                                    <span id="clienteUrl" style="font-size:13px;color:#374151;flex:1;word-break:break-all;font-family:monospace">
                                        <?php
                                            $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                                            $base  = $proto . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']);
                                            $full_url = $base . '/cliente.php?t=' . $mi_token;
                                            echo htmlspecialchars($full_url);
                                        ?>
                                    </span>
                                    <button onclick="copyClienteUrl()" id="copyBtn"
                                        style="background:#0f172a;color:white;border:none;border-radius:7px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;flex-shrink:0;display:flex;align-items:center;gap:5px">
                                        <i class="fas fa-copy"></i> Copiar
                                    </button>
                                    <a href="<?= htmlspecialchars($full_url) ?>" target="_blank"
                                        style="background:#f1f5f9;color:#374151;border:none;border-radius:7px;padding:7px 12px;font-size:12px;font-weight:700;cursor:pointer;flex-shrink:0;text-decoration:none;display:flex;align-items:center;gap:5px">
                                        <i class="fas fa-external-link-alt"></i>
                                    </a>
                                </div>
                            </div>
                            <script>
                            function copyClienteUrl() {
                                navigator.clipboard.writeText(document.getElementById('clienteUrl').textContent.trim()).then(() => {
                                    const btn = document.getElementById('copyBtn');
                                    btn.innerHTML = '<i class="fas fa-check"></i> Copiado';
                                    setTimeout(() => { btn.innerHTML = '<i class="fas fa-copy"></i> Copiar'; }, 2000);
                                });
                            }
                            </script>
                            <?php endif; ?>

                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        function setAppointmentsView(view) {
            const tableContainer = document.getElementById('citas-table-container');
            const gridContainer = document.getElementById('citas-grid-container');
            const btnList = document.getElementById('view-btn-list');
            const btnCards = document.getElementById('view-btn-cards');
            
            if (!tableContainer || !gridContainer) return;
            
            if (view === 'cards') {
                tableContainer.style.display = 'none';
                gridContainer.style.display = 'grid';
                
                if (btnCards) {
                    btnCards.style.background = '#ffffff';
                    btnCards.style.color = '#0f172a';
                    btnCards.style.boxShadow = '0 1px 2px rgba(0,0,0,0.05)';
                }
                if (btnList) {
                    btnList.style.background = 'transparent';
                    btnList.style.color = '#64748b';
                    btnList.style.boxShadow = 'none';
                }
                
                localStorage.setItem('admin_citas_view', 'cards');
            } else {
                tableContainer.style.display = 'block';
                gridContainer.style.display = 'none';
                
                if (btnList) {
                    btnList.style.background = '#ffffff';
                    btnList.style.color = '#0f172a';
                    btnList.style.boxShadow = '0 1px 2px rgba(0,0,0,0.05)';
                }
                if (btnCards) {
                    btnCards.style.background = 'transparent';
                    btnCards.style.color = '#64748b';
                    btnCards.style.boxShadow = 'none';
                }
                
                localStorage.setItem('admin_citas_view', 'list');
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const savedView = localStorage.getItem('admin_citas_view') || 'list';
            setAppointmentsView(savedView);
        });

        document.getElementById('sidebar-toggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.add('open');
        });
        document.getElementById('sidebar-close')?.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.remove('open');
        });
        document.addEventListener('click', function(e) {
            const sidebar = document.querySelector('.sidebar');
            const toggle = document.getElementById('sidebar-toggle');
            if (window.innerWidth <= 992 && sidebar.classList.contains('open')) {
                if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
    </script>
</body>
</html>


