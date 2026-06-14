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

$page = isset($_GET['page']) ? $_GET['page'] : 'citas';
$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';
$page_labels = [
    'citas'     => 'Control de Citas',
    'clientes'  => 'Club VIP',
    'personal'  => 'Equipo',
    'servicios' => 'Servicios',
    'ajustes'   => 'Configuración',
];

$tienda_nombre = 'Panel global';
if ($is_scoped && $scope_id) {
    $st_t = $conn->prepare("SELECT nombre FROM sucursales WHERE id = ?");
    $st_t->bind_param("i", $scope_id);
    $st_t->execute();
    $tienda_nombre = $st_t->get_result()->fetch_assoc()['nombre'] ?? 'Mi tienda';
    $st_t->close();
}

// PROCESAR APROBACIÓN DE PAGO SEGURA (PREPARED STATEMENTS)
if (isset($_POST['verificar_id'])) {
    if (!csrf_validate()) {
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
    $stmt_sel = $conn->prepare("SELECT cliente_telefono, cliente_nombre FROM citas WHERE id = ?");
    $stmt_sel->bind_param("i", $id_cita);
    $stmt_sel->execute();
    $res_c = $stmt_sel->get_result();

    if ($res_c && $cita_info = $res_c->fetch_assoc()) {
        $tel = $cita_info['cliente_telefono'];
        $nom = $cita_info['cliente_nombre'];

        // 3. Insertar o actualizar puntos
        $stmt_ins = $conn->prepare("INSERT INTO clientes (telefono, nombre, puntos, ultima_visita) VALUES (?, ?, 1, CURDATE()) ON DUPLICATE KEY UPDATE puntos = puntos + 1, ultima_visita = CURDATE()");
        $stmt_ins->bind_param("ss", $tel, $nom);
        $stmt_ins->execute();
        $stmt_ins->close();
    }
    $stmt_sel->close();

    header("Location: admin.php?page=citas&msg=Pago+verificado+con+éxito+y+puntos+asignados");
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

$total_clientes_club = $conn->query("SELECT COUNT(*) as total FROM clientes")->fetch_assoc()['total'];

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

// Tiendas reales (excluye el ámbito Administración id=1) para asignar barberos
$tiendas_arr = [];
$res_t = $conn->query("SELECT id, nombre FROM sucursales WHERE id > 1 AND activo = 1 ORDER BY nombre");
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
            background: #ffffff;
            border-right: 1px solid #e5e7eb;
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
            border-bottom: 1px solid #f3f4f6;
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
            color: #111827;
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
            color: #6b7280;
            transition: all 0.2s;
            cursor: pointer;
        }

        .nav-item:hover {
            background: #f3f4f6;
            color: #111827;
        }

        .nav-item.active {
            background: rgba(180, 147, 99, 0.1);
            color: #111827;
            border-left: 4px solid #b49363;
            padding-left: 12px;
        }

        .nav-section {
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: #9ca3af;
            padding: 12px 16px 6px;
        }

        .sidebar-store {
            padding: 0 20px 16px;
            border-bottom: 1px solid #f3f4f6;
        }

        .sidebar-store-name {
            font-size: 13px;
            font-weight: 700;
            color: #111827;
            line-height: 1.3;
        }

        .sidebar-store-sub {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .menu-toggle {
            display: none;
            background: none;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 8px 10px;
            cursor: pointer;
            color: #374151;
        }

        .page-header {
            margin-bottom: 24px;
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 800;
            color: #111827;
            letter-spacing: -0.02em;
            line-height: 1.2;
        }

        .page-title span { color: #b49363; }

        .page-subtitle {
            font-size: 13px;
            color: #6b7280;
            margin-top: 4px;
        }

        .alert-banner {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 13px;
            color: #92400e;
        }

        .alert-banner i { font-size: 18px; color: #d97706; }

        .alert-banner a {
            margin-left: auto;
            color: #92400e;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }

        .filter-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }

        .filter-pill {
            padding: 8px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 2rem;
            background: #fff;
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            cursor: pointer;
            transition: all 0.15s;
        }

        .filter-pill:hover { background: #f8fafc; color: #111827; }

        .filter-pill.active {
            background: #111827;
            border-color: #111827;
            color: #fff;
        }

        .filter-pill .count {
            display: inline-block;
            min-width: 18px;
            padding: 1px 6px;
            margin-left: 4px;
            background: rgba(0,0,0,0.08);
            border-radius: 2rem;
            font-size: 10px;
        }

        .filter-pill.active .count { background: rgba(255,255,255,0.2); }

        .kpi-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border-left: 4px solid #e5e7eb;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .kpi-card.gold { border-left-color: #b49363; }
        .kpi-card.amber { border-left-color: #f59e0b; }
        .kpi-card.green { border-left-color: #10b981; }

        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 36px;
            margin-bottom: 12px;
            opacity: 0.35;
            display: block;
        }

        .empty-state strong {
            display: block;
            color: #64748b;
            font-size: 15px;
            margin-bottom: 4px;
        }

        .card-header-flex {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .card-header-flex small {
            font-size: 11px;
            color: #9ca3af;
            text-transform: none;
            letter-spacing: 0;
            font-weight: 500;
        }

        .points-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 2rem;
            font-weight: 800;
            color: #92400e;
            font-size: 12px;
        }

        .btn-approve {
            background: #10b981;
            color: white;
            padding: 8px 14px;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s;
        }

        .btn-approve:hover { background: #059669; }

        .btn-secondary {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: #f9fafb;
            color: #6b7280;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-secondary:hover { background: #f3f4f6; color: #111827; }

        .btn-danger {
            padding: 6px 10px;
            background: #fee2e2;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            color: #991b1b;
        }

        .btn-danger:hover { background: #fecaca; }

        .page-grid {
            display: grid;
            grid-template-columns: minmax(280px, 1fr) 2fr;
            gap: 24px;
            align-items: start;
        }

        .form-card-body { padding: 20px; }

        .form-section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #9ca3af;
            margin-bottom: 12px;
            padding-top: 16px;
            border-top: 1px solid #e5e7eb;
            margin-top: 8px;
        }

        .barbero-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px;
            background: #fafbfc;
            transition: box-shadow 0.15s, border-color 0.15s;
        }

        .barbero-card:hover {
            border-color: #d1d5db;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }

        .barbero-card-header {
            display: flex;
            gap: 12px;
            margin-bottom: 12px;
            align-items: center;
        }

        .barbero-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e5e7eb;
        }

        .barbero-name { font-weight: 700; color: #111827; }

        .barbero-schedule {
            font-size: 12px;
            color: #9ca3af;
            display: flex;
            align-items: center;
            gap: 4px;
            margin-top: 2px;
        }

        .barbero-actions {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }

        .barbero-actions .btn-secondary { flex: 1; }

        .barbero-grid {
            padding: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
        }

        .svc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 16px;
            padding: 20px;
        }

        .svc-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 18px;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .svc-card:hover {
            border-color: #b49363;
            box-shadow: 0 4px 16px rgba(180,147,99,0.12);
        }

        .svc-card-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: rgba(180,147,99,0.12);
            color: #b49363;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .svc-card-name { font-weight: 700; font-size: 15px; color: #111827; }

        .svc-card-meta {
            font-size: 13px;
            color: #64748b;
            display: flex;
            gap: 12px;
        }

        .svc-card-actions {
            display: flex;
            gap: 8px;
            margin-top: auto;
            padding-top: 8px;
            border-top: 1px solid #f3f4f6;
        }

        .payment-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            background: #fff;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .payment-card.active {
            border-color: #b49363;
            box-shadow: 0 0 0 3px rgba(180,147,99,0.08);
        }

        .payment-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .payment-card-title {
            font-weight: 700;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
        }

        .payment-card-title i { font-size: 18px; }

        .payment-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            z-index: 999;
        }

        .sidebar-overlay.visible { display: block; }

        .toast-msg {
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .search-box {
            position: relative;
            margin-bottom: 16px;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 14px;
        }

        .search-box input {
            width: 100%;
            padding: 10px 12px 10px 36px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }

        .search-box input:focus {
            outline: none;
            border-color: #b49363;
            box-shadow: 0 0 0 3px rgba(180,147,99,0.1);
        }

        .btn-gold {
            width: 100%;
            background: #b49363;
            color: white;
            padding: 10px;
            margin-top: 8px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-gold:hover { background: #9a7d52; }

        .verified-check {
            color: #10b981;
            font-size: 14px;
            font-weight: 700;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #0f172a;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 11px;
        }

        .nav-item i {
            width: 20px;
        }

        .sidebar-user {
            padding: 16px;
            border-top: 1px solid #f3f4f6;
            background: #fafbfc;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .user-details p {
            font-size: 12px;
            margin: 2px 0;
        }

        .user-details p:first-child {
            font-weight: 600;
            color: #111827;
        }

        .user-details p:last-child {
            font-size: 10px;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .logout-btn {
            width: 100%;
            padding: 8px;
            background: none;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            color: #6b7280;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }

        .logout-btn:hover {
            color: #ef4444;
            border-color: #fecaca;
            background: #fef2f2;
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
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .kpi-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #9ca3af;
            margin-bottom: 8px;
        }

        .kpi-value {
            font-size: 28px;
            font-weight: 800;
            color: #111827;
            font-family: 'Monaco', monospace;
        }

        .kpi-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            border-radius: 10px;
            font-size: 18px;
            flex-shrink: 0;
        }

        /* TABLES */
        .card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        .card-header {
            padding: 16px;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
        @media (max-width: 768px) {
            .menu-toggle { display: inline-flex; align-items: center; }
            .sidebar {
                width: 200px;
            }
            .main-content {
                margin-left: 200px;
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
            .page-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .sidebar {
                position: fixed;
                left: -100%;
                width: 100%;
                transition: left 0.3s;
                z-index: 1000;
            }
            .sidebar.open {
                left: 0;
            }
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="admin-container">
        <!-- SIDEBAR -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <i class="fas fa-scissors"></i>
                <span>AlCorte Pro</span>
            </div>
            <div class="sidebar-store">
                <div class="sidebar-store-name"><?php echo htmlspecialchars($tienda_nombre); ?></div>
                <div class="sidebar-store-sub"><?php echo $_SESSION['rol'] === 'gerente' ? 'Gerente' : 'Administrador'; ?></div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section">Operaciones</div>
                <a href="?page=citas" class="nav-item <?php echo $page == 'citas' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check"></i>
                    <span>Citas</span>
                    <?php if ($pendientes_hoy > 0): ?>
                    <span style="margin-left:auto;background:#fef3c7;color:#92400e;font-size:10px;font-weight:800;padding:2px 7px;border-radius:2rem"><?php echo $pendientes_hoy; ?></span>
                    <?php endif; ?>
                </a>
                <a href="?page=clientes" class="nav-item <?php echo $page == 'clientes' ? 'active' : ''; ?>">
                    <i class="fas fa-crown"></i>
                    <span>Club VIP</span>
                </a>
                <div class="nav-section">Tienda</div>
                <a href="?page=personal" class="nav-item <?php echo $page == 'personal' ? 'active' : ''; ?>">
                    <i class="fas fa-user-tie"></i>
                    <span>Equipo</span>
                </a>
                <a href="?page=servicios" class="nav-item <?php echo $page == 'servicios' ? 'active' : ''; ?>">
                    <i class="fas fa-cut"></i>
                    <span>Servicios</span>
                </a>
                <a href="?page=ajustes" class="nav-item <?php echo $page == 'ajustes' ? 'active' : ''; ?>">
                    <i class="fas fa-sliders-h"></i>
                    <span>Pagos</span>
                </a>
            </nav>

            <div class="sidebar-user">
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['nombre'], 0, 2)); ?></div>
                    <div class="user-details">
                        <p><?php echo htmlspecialchars(substr($_SESSION['nombre'], 0, 16)); ?></p>
                        <p><?php echo $_SESSION['rol'] == 'gerente' ? 'Gerente' : 'Admin'; ?></p>
                    </div>
                </div>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Cerrar sesión
                </a>
            </div>
        </aside>

        <!-- MAIN -->
        <div class="main-content">
            <header class="header">
                <div style="display:flex;align-items:center;gap:12px">
                    <button type="button" class="menu-toggle" id="menuToggle" aria-label="Menú">
                        <i class="fas fa-bars"></i>
                    </button>
                    <span class="header-breadcrumb">
                        <?php echo htmlspecialchars($page_labels[$page] ?? 'Panel'); ?>
                    </span>
                </div>
                <?php if (!empty($msg)): ?>
                    <span class="success-badge toast-msg" id="toastMsg">✓ <?php echo $msg; ?></span>
                <?php endif; ?>
            </header>

            <div class="content">
                <div class="page-header">
                    <h1 class="page-title"><?php
                        $titles = [
                            'citas' => 'Control de <span>Citas</span>',
                            'clientes' => 'Club <span>VIP</span>',
                            'personal' => 'Mi <span>Equipo</span>',
                            'servicios' => 'Catálogo de <span>Servicios</span>',
                            'ajustes' => 'Métodos de <span>Pago</span>',
                        ];
                        echo $titles[$page] ?? 'Panel';
                    ?></h1>
                    <p class="page-subtitle"><?php
                        $subs = [
                            'citas' => 'Valida pagos, revisa turnos y aprueba citas pendientes.',
                            'clientes' => 'Clientes con puntos acumulados en el club de fidelidad.',
                            'personal' => 'Barberos, horarios y accesos al sistema.',
                            'servicios' => 'Servicios visibles para reservas de clientes.',
                            'ajustes' => 'Activa Pago Móvil, Zelle o Efectivo para tu tienda.',
                        ];
                        echo $subs[$page] ?? '';
                    ?></p>
                </div>

                <?php if ($page == 'citas'): ?>
                <?php if ($pendientes_hoy > 0): ?>
                <div class="alert-banner">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>Tienes <strong><?php echo $pendientes_hoy; ?></strong> pago<?php echo $pendientes_hoy !== 1 ? 's' : ''; ?> pendiente<?php echo $pendientes_hoy !== 1 ? 's' : ''; ?> por verificar.</span>
                    <a href="#" onclick="document.querySelector('[data-filter=pending]').click();return false;">Ver pendientes →</a>
                </div>
                <?php endif; ?>

                <div class="kpi-grid">
                    <div class="kpi-card gold">
                        <div>
                            <div class="kpi-label">Turnos hoy</div>
                            <div class="kpi-value"><?php echo $hoy_citas; ?></div>
                        </div>
                        <div class="kpi-icon" style="background:#fef3c7;color:#f59e0b"><i class="fas fa-calendar-day"></i></div>
                    </div>
                    <div class="kpi-card amber">
                        <div>
                            <div class="kpi-label">Pagos por validar</div>
                            <div class="kpi-value"><?php echo $pendientes_hoy; ?></div>
                        </div>
                        <div class="kpi-icon" style="background:#fef08a;color:#ca8a04"><i class="fas fa-hourglass-half"></i></div>
                    </div>
                    <div class="kpi-card green">
                        <div>
                            <div class="kpi-label">Clientes VIP</div>
                            <div class="kpi-value"><?php echo $total_clientes_club; ?></div>
                        </div>
                        <div class="kpi-icon" style="background:#dcfce7;color:#16a34a"><i class="fas fa-crown"></i></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- PAGE CONTENT -->
                <?php if ($page == 'citas'):
                    if ($is_scoped) {
                        $citas_stmt = $conn->prepare("SELECT c.*, b.nombre as barbero_nombre FROM citas c LEFT JOIN barberos b ON c.barbero_id = b.id WHERE c.sucursal_id = ? ORDER BY c.fecha DESC, c.hora DESC");
                        $citas_stmt->bind_param("i", $scope_id);
                        $citas_stmt->execute();
                        $citas_res = $citas_stmt->get_result();
                    } else {
                        $citas_res = $conn->query("SELECT c.*, b.nombre as barbero_nombre FROM citas c LEFT JOIN barberos b ON c.barbero_id = b.id ORDER BY c.fecha DESC, c.hora DESC");
                    }
                    $citas_rows = [];
                    $count_all = $count_pending = $count_today = 0;
                    $today = date('Y-m-d');
                    while ($row = $citas_res->fetch_assoc()) {
                        $citas_rows[] = $row;
                        $count_all++;
                        if ($row['estado_pago'] === 'pendiente') $count_pending++;
                        if ($row['fecha'] === $today) $count_today++;
                    }
                ?>
                    <div class="card">
                        <div class="card-header card-header-flex">
                            <div>Listado de citas <small><?php echo $count_all; ?> en total</small></div>
                        </div>
                        <div style="padding:16px 16px 0">
                            <div class="filter-pills" id="citasFilters">
                                <button type="button" class="filter-pill active" data-filter="all">Todas <span class="count"><?php echo $count_all; ?></span></button>
                                <button type="button" class="filter-pill" data-filter="pending">Pendientes <span class="count"><?php echo $count_pending; ?></span></button>
                                <button type="button" class="filter-pill" data-filter="today">Hoy <span class="count"><?php echo $count_today; ?></span></button>
                            </div>
                        </div>
                        <div class="card-content">
                            <?php if (empty($citas_rows)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-xmark"></i>
                                <strong>Sin citas registradas</strong>
                                <span style="font-size:13px">Las reservas de clientes aparecerán aquí</span>
                            </div>
                            <?php else: ?>
                            <table id="citasTable">
                                <thead>
                                    <tr>
                                        <th>Cliente</th>
                                        <th>Servicio</th>
                                        <th>Horario</th>
                                        <th>Pago</th>
                                        <th>Estado</th>
                                        <th>Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($citas_rows as $row): ?>
                                    <tr class="cita-row" data-pago="<?php echo htmlspecialchars($row['estado_pago']); ?>" data-fecha="<?php echo htmlspecialchars($row['fecha']); ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($row['cliente_nombre']); ?></strong><br>
                                            <small style="color:#9ca3af;"><?php echo htmlspecialchars($row['cliente_telefono']); ?></small>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($row['servicio']); ?></div>
                                            <small style="color:#b49363;font-weight:600"><?php echo htmlspecialchars($row['barbero_nombre'] ?? 'Sin barbero'); ?></small>
                                        </td>
                                        <td>
                                            <div><?php echo date('d/m/Y', strtotime($row['fecha'])); ?></div>
                                            <small style="color:#9ca3af;"><?php echo date('h:i A', strtotime($row['hora'])); ?></small>
                                        </td>
                                        <td>
                                            <div><?php echo htmlspecialchars($row['metodo_pago']); ?></div>
                                            <small style="color:#9ca3af;font-family:monospace;"><?php echo $row['referencia_pago'] ? '#'.htmlspecialchars($row['referencia_pago']) : '—'; ?></small>
                                        </td>
                                        <td>
                                            <?php if ($row['estado_pago'] == 'pendiente'): ?>
                                                <span class="badge badge-pending">Pago pendiente</span>
                                            <?php else: ?>
                                                <span class="badge badge-verified">Verificado</span>
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
                                            <?php if ($row['estado_pago'] == 'pendiente'): ?>
                                                <form method="POST" style="display:inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                                    <input type="hidden" name="verificar_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" class="btn-approve"><i class="fas fa-check"></i> Aprobar</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="verified-check"><i class="fas fa-circle-check"></i> OK</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>
                    <script>
                    document.querySelectorAll('#citasFilters .filter-pill').forEach(btn => {
                        btn.addEventListener('click', () => {
                            document.querySelectorAll('#citasFilters .filter-pill').forEach(b => b.classList.remove('active'));
                            btn.classList.add('active');
                            const f = btn.dataset.filter;
                            const today = new Date().toISOString().slice(0, 10);
                            document.querySelectorAll('.cita-row').forEach(row => {
                                let show = true;
                                if (f === 'pending') show = row.dataset.pago === 'pendiente';
                                if (f === 'today') show = row.dataset.fecha === today;
                                row.style.display = show ? '' : 'none';
                            });
                        });
                    });
                    </script>

                <?php elseif ($page == 'clientes'): ?>
                    <div class="card">
                        <div class="card-header card-header-flex">
                            <div>Clientes VIP <small><?php echo $total_clientes_club; ?> registrados</small></div>
                        </div>
                        <div style="padding:16px 16px 0">
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" id="clienteSearch" placeholder="Buscar por nombre o teléfono…">
                            </div>
                        </div>
                        <div class="card-content">
                            <?php
                            $res_c = $conn->query("SELECT * FROM clientes ORDER BY puntos DESC");
                            $clientes_rows = $res_c ? $res_c->fetch_all(MYSQLI_ASSOC) : [];
                            if (empty($clientes_rows)):
                            ?>
                            <div class="empty-state">
                                <i class="fas fa-crown"></i>
                                <strong>Sin clientes en el club</strong>
                                <span style="font-size:13px">Los puntos se asignan al verificar pagos de citas</span>
                            </div>
                            <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Cliente</th>
                                        <th>Teléfono</th>
                                        <th>Puntos</th>
                                        <th>Última visita</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($clientes_rows as $c): ?>
                                    <tr class="cliente-row">
                                        <td><strong><?php echo htmlspecialchars($c['nombre']); ?></strong></td>
                                        <td style="font-family:monospace;color:#64748b"><?php echo htmlspecialchars($c['telefono']); ?></td>
                                        <td><span class="points-badge"><i class="fas fa-star"></i> <?php echo intval($c['puntos']); ?></span></td>
                                        <td style="color:#9ca3af;"><?php echo $c['ultima_visita'] ? date('d/m/Y', strtotime($c['ultima_visita'])) : '—'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div>
                    <script>
                    document.getElementById('clienteSearch')?.addEventListener('input', function() {
                        const q = this.value.toLowerCase().trim();
                        document.querySelectorAll('.cliente-row').forEach(row => {
                            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
                        });
                    });
                    </script>

                <?php elseif ($page == 'personal'): ?>
                    <div class="page-grid">
                        <div class="card">
                            <div class="card-header card-header-flex">
                                <span id="barb_form_title">Registrar Barbero</span>
                                <button type="button" class="btn-secondary" id="barb_cancel_btn" style="display:none" onclick="resetBarberoForm()">Cancelar</button>
                            </div>
                            <div class="form-card-body">
                                <form action="../backend/processing/admin.php" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                    <input type="hidden" id="barb_action" name="action" value="add_barbero">
                                    <input type="hidden" id="barb_id" name="id" value="">

                                    <div class="form-group">
                                        <label class="form-label">Nombre</label>
                                        <input type="text" id="barb_nombre" name="nombre" class="form-input" placeholder="Ej. Joshy" required>
                                    </div>

                                    <?php if (!$is_scoped): ?>
                                    <div class="form-group">
                                        <label class="form-label">Tienda</label>
                                        <select id="barb_sucursal" name="sucursal_id" class="form-select" required>
                                            <?php if (empty($tiendas_arr)): ?>
                                                <option value="">— Crea una tienda primero —</option>
                                            <?php else: foreach ($tiendas_arr as $t): ?>
                                                <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['nombre']); ?></option>
                                            <?php endforeach; endif; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>

                                    <div class="form-group">
                                        <label class="form-label">Hora Entrada</label>
                                        <input type="time" id="barb_inicio" name="hora_inicio" value="09:00" class="form-input" required>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Hora Salida</label>
                                        <input type="time" id="barb_fin" name="hora_fin" value="17:00" class="form-input" required>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Almuerzo Inicio</label>
                                        <input type="time" id="barb_almuerzo_inicio" name="almuerzo_inicio" value="12:00" class="form-input" required>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Almuerzo Fin</label>
                                        <input type="time" id="barb_almuerzo_fin" name="almuerzo_fin" value="13:00" class="form-input" required>
                                    </div>

                                    <div id="barb_credentials_section">
                                        <div class="form-section-title"><i class="fas fa-lock"></i> Acceso al Sistema</div>
                                        <div class="form-group">
                                            <label class="form-label">Usuario</label>
                                            <input type="text" id="barb_usuario" name="barb_usuario" class="form-input" placeholder="Ej. joshy" autocomplete="off" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Contraseña</label>
                                            <input type="password" id="barb_password" name="barb_password" class="form-input" placeholder="Mín. 8 caracteres" autocomplete="new-password" required minlength="8">
                                        </div>
                                    </div>

                                    <div class="form-group" id="status_container" style="display: none;">
                                        <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: #6b7280; cursor: pointer;">
                                            <input type="checkbox" id="barb_activo" name="activo" value="1" style="width: 16px; height: 16px;">
                                            Activo en Sistema
                                        </label>
                                    </div>

                                    <button type="submit" class="btn-gold">
                                        <i class="fas fa-save"></i> Guardar
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header card-header-flex">
                                <span>Barberos en Sistema</span>
                                <small>Horarios y visibilidad</small>
                            </div>
                            <div class="barbero-grid">
                                <?php
                                if ($is_scoped) {
                                    $barb_stmt = $conn->prepare("SELECT * FROM barberos WHERE sucursal_id = ? ORDER BY nombre");
                                    $barb_stmt->bind_param("i", $scope_id);
                                    $barb_stmt->execute();
                                    $barb_res = $barb_stmt->get_result();
                                } else {
                                    $barb_res = $conn->query("SELECT * FROM barberos ORDER BY nombre");
                                }
                                $has_barberos = false;
                                while ($b = $barb_res->fetch_assoc()):
                                    $has_barberos = true;
                                ?>
                                <div class="barbero-card">
                                    <div class="barbero-card-header">
                                        <img src="<?php echo $b['foto_url']; ?>" alt="" class="barbero-avatar">
                                        <div>
                                            <div class="barbero-name"><?php echo htmlspecialchars($b['nombre']); ?></div>
                                            <div class="barbero-schedule">
                                                <i class="fas fa-clock"></i>
                                                <?php echo date('h:i A', strtotime($b['hora_inicio'])); ?> – <?php echo date('h:i A', strtotime($b['hora_fin'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="barbero-actions">
                                        <button type="button" class="btn-secondary" onclick="editarBarbero(<?php echo $b['id']; ?>, '<?php echo htmlspecialchars($b['nombre'], ENT_QUOTES); ?>', '<?php echo $b['hora_inicio']; ?>', '<?php echo $b['hora_fin']; ?>', '<?php echo $b['almuerzo_inicio']; ?>', '<?php echo $b['almuerzo_fin']; ?>', <?php echo $b['activo']; ?>)"><i class="fas fa-pen"></i> Editar</button>
                                        <form action="../backend/processing/admin.php" method="POST" onsubmit="return confirm('¿Eliminar este barbero?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                            <input type="hidden" name="action" value="delete_barbero">
                                            <input type="hidden" name="id" value="<?php echo $b['id']; ?>">
                                            <button type="submit" class="btn-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                    <span class="badge" style="<?php echo $b['activo'] == 1 ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b'; ?>">
                                        <?php echo $b['activo'] == 1 ? 'Visible en reservas' : 'Oculto'; ?>
                                    </span>
                                </div>
                                <?php endwhile;
                                if (!$has_barberos): ?>
                                <div class="empty-state" style="grid-column:1/-1;padding:32px">
                                    <i class="fas fa-user-tie"></i>
                                    <strong>Sin barberos registrados</strong>
                                    <span style="font-size:13px">Agrega el primero con el formulario</span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <script>
                        function editarBarbero(id, nombre, inicio, fin, almuerzo_in, almuerzo_fi, activo) {
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
                            document.getElementById('barb_form_title').textContent = 'Editar Barbero';
                            document.getElementById('barb_cancel_btn').style.display = 'inline-block';
                            document.getElementById('barb_nombre').scrollIntoView({ behavior: 'smooth', block: 'center' });
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
                            document.getElementById('barb_form_title').textContent = 'Registrar Barbero';
                            document.getElementById('barb_cancel_btn').style.display = 'none';
                        }
                    </script>

                <?php elseif ($page == 'servicios'):
                    $svc_suc = $is_scoped ? $scope_id : 1;
                    $svc_res = $conn->prepare("SELECT * FROM servicios WHERE sucursal_id = ? ORDER BY orden ASC, id ASC");
                    $svc_res->bind_param("i", $svc_suc);
                    $svc_res->execute();
                    $svc_list = $svc_res->get_result()->fetch_all(MYSQLI_ASSOC);
                ?>
                    <div class="page-grid">
                        <div class="card">
                            <div class="card-header card-header-flex">
                                <span id="svc_form_title">Agregar Servicio</span>
                                <button type="button" class="btn-secondary" id="svc_cancel_btn" style="display:none" onclick="resetSvcForm()">Cancelar</button>
                            </div>
                            <div class="form-card-body">
                                <form action="../backend/processing/admin.php" method="POST" id="svcForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                    <input type="hidden" id="svc_action" name="action" value="add_servicio">
                                    <input type="hidden" id="svc_id"     name="svc_id" value="">

                                    <div class="form-group">
                                        <label class="form-label">Nombre</label>
                                        <input type="text" id="svc_nombre" name="svc_nombre" class="form-input" placeholder="Ej. Corte Clásico" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Precio</label>
                                        <input type="text" id="svc_precio" name="svc_precio" class="form-input" placeholder="Ej. Bs 8">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Duración</label>
                                        <input type="text" id="svc_duracion" name="svc_duracion" class="form-input" placeholder="Ej. 30 min" value="30 min">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Ícono (Font Awesome)</label>
                                        <input type="text" id="svc_icono" name="svc_icono" class="form-input" placeholder="fas fa-cut" value="fas fa-cut">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">Orden</label>
                                        <input type="number" id="svc_orden" name="svc_orden" class="form-input" value="0" min="0">
                                    </div>
                                    <div class="form-group" id="svc_activo_wrap" style="display:none;">
                                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#6b7280;cursor:pointer;">
                                            <input type="checkbox" id="svc_activo" name="svc_activo" value="1" style="width:16px;height:16px;"> Activo
                                        </label>
                                    </div>

                                    <button type="submit" class="btn-gold">
                                        <i class="fas fa-save"></i> Guardar
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header card-header-flex">
                                <span>Servicios registrados</span>
                                <small><?php echo count($svc_list); ?> en catálogo</small>
                            </div>
                            <?php if (empty($svc_list)): ?>
                            <div class="empty-state">
                                <i class="fas fa-cut"></i>
                                <strong>Sin servicios en el catálogo</strong>
                                <span style="font-size:13px">Agrega servicios para que los clientes puedan reservar</span>
                            </div>
                            <?php else: ?>
                            <div class="svc-grid">
                                <?php foreach ($svc_list as $sv): ?>
                                <div class="svc-card">
                                    <div class="svc-card-icon"><i class="<?php echo htmlspecialchars($sv['icono']); ?>"></i></div>
                                    <div class="svc-card-name"><?php echo htmlspecialchars($sv['nombre']); ?></div>
                                    <div class="svc-card-meta">
                                        <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($sv['precio']); ?></span>
                                        <span><i class="fas fa-clock"></i> <?php echo htmlspecialchars($sv['duracion']); ?></span>
                                    </div>
                                    <span class="badge" style="<?php echo $sv['activo'] ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b'; ?>">
                                        <?php echo $sv['activo'] ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                    <div class="svc-card-actions">
                                        <button type="button" class="btn-secondary" style="flex:1"
                                            onclick="editSvc(<?php echo $sv['id']; ?>,'<?php echo htmlspecialchars($sv['nombre'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($sv['precio'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($sv['duracion'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($sv['icono'],ENT_QUOTES); ?>',<?php echo $sv['activo']; ?>,<?php echo $sv['orden']; ?>)">
                                            <i class="fas fa-pen"></i> Editar
                                        </button>
                                        <form action="../backend/processing/admin.php" method="POST" onsubmit="return confirm('¿Eliminar este servicio?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                            <input type="hidden" name="action" value="delete_servicio">
                                            <input type="hidden" name="svc_id" value="<?php echo $sv['id']; ?>">
                                            <button type="submit" class="btn-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <script>
                    function editSvc(id, nombre, precio, duracion, icono, activo, orden) {
                        document.getElementById('svc_id').value      = id;
                        document.getElementById('svc_nombre').value  = nombre;
                        document.getElementById('svc_precio').value  = precio;
                        document.getElementById('svc_duracion').value = duracion;
                        document.getElementById('svc_icono').value   = icono;
                        document.getElementById('svc_orden').value   = orden;
                        document.getElementById('svc_activo').checked = (activo == 1);
                        document.getElementById('svc_activo_wrap').style.display = 'block';
                        document.getElementById('svc_action').value = 'edit_servicio';
                        document.getElementById('svc_form_title').textContent = 'Editar Servicio';
                        document.getElementById('svc_cancel_btn').style.display = 'inline-block';
                        document.getElementById('svc_nombre').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    function resetSvcForm() {
                        document.getElementById('svcForm').reset();
                        document.getElementById('svc_id').value     = '';
                        document.getElementById('svc_action').value = 'add_servicio';
                        document.getElementById('svc_form_title').textContent = 'Agregar Servicio';
                        document.getElementById('svc_activo_wrap').style.display = 'none';
                        document.getElementById('svc_cancel_btn').style.display  = 'none';
                        document.getElementById('svc_duracion').value = '30 min';
                        document.getElementById('svc_icono').value   = 'fas fa-cut';
                        document.getElementById('svc_orden').value   = '0';
                    }
                    </script>

                <?php elseif ($page == 'ajustes'): ?>
                    <form action="../backend/processing/admin.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                        <input type="hidden" name="action" value="update_sys_settings">

                        <div class="payment-grid">
                            <div class="payment-card" data-payment="movil">
                                <div class="payment-card-header">
                                    <span class="payment-card-title">
                                        <i class="fas fa-mobile-alt" style="color:#3b82f6"></i> Pago Móvil
                                    </span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="estado_pago_movil" value="1" class="payment-toggle" <?php echo ($config['estado_pago_movil'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Banco</label>
                                    <input type="text" name="banco_nombre" class="form-input" placeholder="Ej. Banesco" value="<?php echo htmlspecialchars($config['banco_nombre'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Teléfono</label>
                                    <input type="text" name="banco_telefono" class="form-input" placeholder="0414-0000000" value="<?php echo htmlspecialchars($config['banco_telefono'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Cédula / RIF</label>
                                    <input type="text" name="banco_ci" class="form-input" placeholder="V-00000000" value="<?php echo htmlspecialchars($config['banco_ci'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="payment-card" data-payment="zelle">
                                <div class="payment-card-header">
                                    <span class="payment-card-title">
                                        <i class="fas fa-dollar-sign" style="color:#8b5cf6"></i> Zelle
                                    </span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="estado_zelle" value="1" class="payment-toggle" <?php echo ($config['estado_zelle'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Correo Zelle</label>
                                    <input type="email" name="zelle_email" class="form-input" placeholder="tienda@email.com" value="<?php echo htmlspecialchars($config['zelle_email'] ?? ''); ?>">
                                </div>
                                <p style="font-size:12px;color:#9ca3af;margin-top:8px">Los clientes verán este correo al reservar con Zelle.</p>
                            </div>

                            <div class="payment-card" data-payment="efectivo">
                                <div class="payment-card-header">
                                    <span class="payment-card-title">
                                        <i class="fas fa-money-bill-wave" style="color:#10b981"></i> Efectivo
                                    </span>
                                    <label class="toggle-switch">
                                        <input type="checkbox" name="estado_efectivo" value="1" class="payment-toggle" <?php echo ($config['estado_efectivo'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                                <p style="font-size:13px;color:#64748b;line-height:1.5">Permite reservas sin referencia de pago. El cliente paga directamente en el local.</p>
                            </div>
                        </div>

                        <div style="margin-top:24px;display:flex;justify-content:flex-end;gap:12px;align-items:center">
                            <span style="font-size:12px;color:#9ca3af">Los cambios aplican de inmediato en las reservas</span>
                            <button type="submit" class="btn-gold" style="width:auto;padding:10px 28px">
                                <i class="fas fa-save"></i> Guardar Ajustes
                            </button>
                        </div>
                    </form>
                    <script>
                    function updatePaymentCards() {
                        document.querySelectorAll('.payment-card').forEach(card => {
                            const toggle = card.querySelector('.payment-toggle');
                            card.classList.toggle('active', toggle && toggle.checked);
                        });
                    }
                    document.querySelectorAll('.payment-toggle').forEach(t => t.addEventListener('change', updatePaymentCards));
                    updatePaymentCards();
                    </script>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
    (function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggle = () => {
            sidebar?.classList.toggle('open');
            overlay?.classList.toggle('visible', sidebar?.classList.contains('open'));
        };
        document.getElementById('menuToggle')?.addEventListener('click', toggle);
        overlay?.addEventListener('click', toggle);

        const toast = document.getElementById('toastMsg');
        if (toast) {
            setTimeout(() => {
                toast.style.transition = 'opacity 0.4s';
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 400);
            }, 4500);
        }
    })();
    </script>
</body>
</html>


