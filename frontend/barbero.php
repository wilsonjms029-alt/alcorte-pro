<?php
require_once '../backend/config/config.php';

// Seguridad del rol
if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], ['barbero', 'admin', 'superadmin'])) {
    header("Location: ../index.php");
    exit;
}

$is_admin_viewing = ($_SESSION['rol'] === 'admin' || $_SESSION['rol'] === 'superadmin');
$id_barbero = intval($_SESSION['barbero_id']);

if ($is_admin_viewing && isset($_GET['id'])) {
    $id_barbero = intval($_GET['id']);
}
$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';

// Obtener información del barbero y su sucursal relacionada
$stmt_b_info = $conn->prepare("
    SELECT b.nombre, b.foto_url, b.sucursal_id, s.nombre AS sucursal_nombre 
    FROM barberos b 
    LEFT JOIN sucursales s ON b.sucursal_id = s.id 
    WHERE b.id = ?
");
$stmt_b_info->bind_param("i", $id_barbero);
$stmt_b_info->execute();
$barbero_data = $stmt_b_info->get_result()->fetch_assoc();
$stmt_b_info->close();

$barbero_nombre   = $barbero_data['nombre'] ?? $_SESSION['nombre'];
$barbero_foto     = $barbero_data['foto_url'] ?? '';
$sucursal_nombre  = $barbero_data['sucursal_nombre'] ?? 'Mi Sucursal';
$sucursal_id      = intval($barbero_data['sucursal_id'] ?? 0);

// Verificar si el barbero pertenece a un plan básico
$plan_activo = get_plan_sucursal($conn, $sucursal_id);
$is_basic_plan = $plan_activo ? ($plan_activo['nombre'] === 'Básico') : false;

// El plan básico no incluye acceso al panel de barbero de forma directa
if ($is_basic_plan && !$is_admin_viewing) {
    header("Location: logout.php?msg=El+plan+básico+no+incluye+acceso+al+panel+de+barbero");
    exit;
}

// Solo los administradores simulando la vista pueden gestionar citas en este panel (los barberos autorizados solo leen)
$can_manage_citas = $is_admin_viewing;

// Marcar cita como completada / cancelada (solo citas propias y si tiene permiso)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cita_id'], $_POST['nuevo_estado'])) {
    if (!$can_manage_citas) {
        header("Location: barbero.php?msg=Acción+no+permitida");
        exit;
    }
    if (!csrf_validate()) {
        header("Location: barbero.php?msg=Error+de+seguridad");
        exit;
    }
    csrf_regenerate();

    $cita_id      = intval($_POST['cita_id']);
    $nuevo_estado = $_POST['nuevo_estado'];

    if (in_array($nuevo_estado, ['completada', 'cancelada'], true)) {
        $up = $conn->prepare("UPDATE citas SET estado = ? WHERE id = ? AND barbero_id = ?");
        $up->bind_param("sii", $nuevo_estado, $cita_id, $id_barbero);
        $up->execute();
        $up->close();
        $txt = $nuevo_estado === 'completada' ? 'Cita+marcada+como+completada' : 'Cita+cancelada';
        header("Location: barbero.php?msg=$txt");
        exit;
    }
    header("Location: barbero.php");
    exit;
}

$page = $_GET['page'] ?? 'agenda';

// Citas completadas esta semana (últimos 7 días)
$stmt_est = $conn->prepare("SELECT COUNT(*) as c FROM citas WHERE barbero_id = ? AND estado = 'completada' AND fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$stmt_est->bind_param("i", $id_barbero); $stmt_est->execute();
$citas_esta_semana = $stmt_est->get_result()->fetch_assoc()['c']; $stmt_est->close();

// Citas completadas la semana anterior (días -14 a -7)
$stmt_est2 = $conn->prepare("SELECT COUNT(*) as c FROM citas WHERE barbero_id = ? AND estado = 'completada' AND fecha >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND fecha < DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
$stmt_est2->bind_param("i", $id_barbero); $stmt_est2->execute();
$citas_semana_anterior = $stmt_est2->get_result()->fetch_assoc()['c']; $stmt_est2->close();

// Proyección
if ($citas_semana_anterior == 0) {
    $proyeccion = $citas_esta_semana > 0 ? 100 : 0;
} else {
    $proyeccion = (($citas_esta_semana - $citas_semana_anterior) / $citas_semana_anterior) * 100;
}

// Filtro en las citas
$f_estado = $_GET['f_estado'] ?? 'todos';
$f_fecha  = $_GET['f_fecha'] ?? '';
$f_q      = $_GET['f_q'] ?? '';

$where_clauses = ["barbero_id = ?"];
$params = [$id_barbero];
$types = "i";

if ($f_estado !== 'todos') {
    $where_clauses[] = "estado = ?";
    $params[] = $f_estado;
    $types .= "s";
}
if (!empty($f_fecha)) {
    $where_clauses[] = "fecha = ?";
    $params[] = $f_fecha;
    $types .= "s";
}
if (!empty($f_q)) {
    $q = "%" . $f_q . "%";
    $where_clauses[] = "(cliente_nombre LIKE ? OR cliente_telefono LIKE ?)";
    $params[] = $q;
    $params[] = $q;
    $types .= "ss";
}

$where_sql = implode(" AND ", $where_clauses);
$stmt = $conn->prepare("SELECT * FROM citas WHERE $where_sql ORDER BY CASE estado WHEN 'programada' THEN 0 WHEN 'completada' THEN 1 ELSE 2 END, fecha ASC, hora ASC");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$citas_arr = [];
while ($c = $res->fetch_assoc()) { $citas_arr[] = $c; }
$stmt->close();

// Totales para KPI badges
$stmt_kpi = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN estado = 'programada' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN estado = 'completada' THEN 1 ELSE 0 END) as completed FROM citas WHERE barbero_id = ?");
$stmt_kpi->bind_param("i", $id_barbero); $stmt_kpi->execute();
$kpi_res = $stmt_kpi->get_result()->fetch_assoc();
$total_agenda  = $kpi_res['total'] ?? 0;
$n_programadas = $kpi_res['pending'] ?? 0;
$n_completadas = $kpi_res['completed'] ?? 0;
$stmt_kpi->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Agenda - AlCorte Pro</title>
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
            background: #f9fafb;
            color: #111827;
        }

        .barbero-container {
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
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            color: #94a3b8;
            transition: all 0.2s;
            cursor: pointer;
            margin-bottom: 4px;
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
            color: #94a3b8;
        }

        .nav-item.active i {
            color: #b49363;
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
            background: #fef3c7;
            color: #b49363;
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

        /* HEADER (eliminado — menú móvil flotante) */
        .mobile-menu-fab {
            position: fixed;
            top: 14px;
            left: 14px;
            z-index: 900;
            display: none;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            background: #0f172a;
            color: #fff;
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            cursor: pointer;
            font-size: 18px;
        }

        .header-badge {
            font-size: 12px;
            font-weight: 700;
            padding: 6px 12px;
            background: #fef3c7;
            color: #78350f;
            border: 1px solid #fcd34d;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* CONTENT */
        .content {
            flex: 1;
            padding: 32px;
            overflow-y: auto;
        }

        .content-max {
            max-width: 1000px;
        }

        .section-title {
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title i {
            color: #b49363;
        }

        /* CITAS GRID */
        .citas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 12px;
        }

        .cita-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            transition: all 0.2s;
        }

        .cita-card:hover {
            border-color: #b49363;
            box-shadow: 0 4px 12px rgba(180, 147, 99, 0.1);
        }

        .cita-card-header {
            padding: 10px 12px;
            background: linear-gradient(135deg, #b49363 0%, #9d7e54 100%);
            color: white;
            border-left: 4px solid #7c5c3d;
        }

        .cita-time {
            font-size: 20px;
            font-weight: 700;
            font-family: 'Monaco', monospace;
            line-height: 1;
            margin-bottom: 2px;
        }

        .cita-date {
            font-size: 10px;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .cita-card-content {
            padding: 10px 12px;
        }

        .cita-client {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
        }

        .cita-phone {
            font-size: 11px;
            color: #9ca3af;
            font-family: monospace;
            margin-bottom: 8px;
        }

        .cita-service {
            padding: 6px 10px;
            background: #f9fafb;
            border-radius: 6px;
            font-size: 11px;
            color: #6b7280;
            margin-bottom: 8px;
            border-left: 3px solid #b49363;
        }

        .cita-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 8px;
            border-top: 1px solid #e5e7eb;
        }

        .cita-payment {
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .cita-status-pending {
            background: #fef3c7;
            color: #78350f;
        }

        .cita-status-verified {
            background: #dcfce7;
            color: #166534;
        }

        /* EMPTY STATE */
        .empty-state {
            padding: 60px 20px;
            text-align: center;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
        }

        .empty-state-icon {
            font-size: 48px;
            color: #d1d5db;
            margin-bottom: 16px;
        }

        .empty-state-text {
            font-size: 14px;
            color: #9ca3af;
        }

        /* MENSAJE TOAST */
        .msg-toast {
            font-size: 12px;
            font-weight: 700;
            padding: 6px 12px;
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #d1fae5;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ESTADO BADGE */
        .estado-badge {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 4px 10px;
            border-radius: 9999px;
        }
        .estado-programada { background: #eef2ff; color: #3730a3; }
        .estado-completada { background: #dcfce7; color: #166534; }
        .estado-cancelada  { background: #fee2e2; color: #991b1b; }

        /* TARJETAS POR ESTADO */
        .cita-card.is-done   { opacity: 0.85; }
        .cita-card.is-done .cita-card-header   { background: linear-gradient(135deg, #16a34a 0%, #15803d 100%); border-left-color: #14532d; }
        .cita-card.is-cancelled { opacity: 0.6; }
        .cita-card.is-cancelled .cita-card-header { background: linear-gradient(135deg, #9ca3af 0%, #6b7280 100%); border-left-color: #4b5563; }
        .cita-card.is-cancelled .cita-client { text-decoration: line-through; color: #9ca3af; }

        /* BOTONES DE ACCIÓN */
        .cita-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
        }
        .act-btn {
            width: 100%;
            padding: 8px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.18s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        .act-done   { background: #dcfce7; color: #166534; }
        .act-done:hover   { background: #16a34a; color: #fff; }
        .act-cancel { background: #fef2f2; color: #b91c1c; }
        .act-cancel:hover { background: #ef4444; color: #fff; }

        /* RESPONSIVE */
        @media (max-width: 768px) {
            .sidebar {
                width: 200px;
            }
            .main-content {
                margin-left: 200px;
            }
            .content {
                padding: 16px;
            }
            .citas-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
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
            .mobile-menu-fab {
                display: inline-flex !important;
            }
            .mobile-close {
                display: inline-block !important;
            }
        }
    </style>
</head>
<body>
    <?php if ($is_admin_viewing): ?>
        <div style="background:#fff7ed; border-bottom:1px solid #ffedd5; padding:10px 24px; font-size:13px; color:#c2410c; display:flex; justify-content:space-between; align-items:center; z-index:9999; position:relative; font-family:'Inter',sans-serif; font-weight:600;">
            <span><i class="fas fa-eye" style="margin-right:8px; color:#f97316;"></i> Vista de Administrador. Viendo agenda de: <strong><?php echo htmlspecialchars($barbero_nombre); ?></strong></span>
            <a href="admin.php?page=personal" style="color:#ffffff; background:#c2410c; padding:4px 12px; border-radius:6px; font-size:11px; text-decoration:none; font-weight:700; display:inline-flex; align-items:center; gap:6px; transition:opacity .15s;" onmouseover="this.style.opacity=.9" onmouseout="this.style.opacity=1"><i class="fas fa-arrow-left"></i> Volver a Panel Admin</a>
        </div>
    <?php endif; ?>
    <div class="barbero-container">
        <button type="button" id="sidebar-toggle" class="mobile-menu-fab" aria-label="Abrir menú">
            <i class="fas fa-bars"></i>
        </button>
        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="sidebar-brand" style="flex-direction:column;align-items:flex-start;gap:2px">
                <div style="display:flex;align-items:center;justify-content:space-between;width:100%">
                    <div style="display:flex;align-items:center;gap:10px">
                        <i class="fas fa-scissors"></i>
                        <span>AlCorte</span>
                    </div>
                    <button type="button" id="sidebar-close" style="background:none; border:none; color:#ffffff; font-size:18px; cursor:pointer; display:none; padding:4px;" class="mobile-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <nav class="sidebar-nav">
                <a href="?page=agenda" class="nav-item <?php echo $page == 'agenda' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check"></i>
                    <span>Mi Agenda</span>
                </a>
                <a href="?page=estadisticas" class="nav-item <?php echo $page == 'estadisticas' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i>
                    <span>Estadísticas</span>
                </a>
            </nav>

            <div class="sidebar-user">
                <div class="user-info">
                    <?php if (!empty($barbero_foto)): ?>
                        <img src="<?php echo htmlspecialchars($barbero_foto); ?>" style="width:36px; height:36px; border-radius:50%; object-fit:cover; border:2px solid #b49363;">
                    <?php else: ?>
                        <div class="user-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                    <?php endif; ?>
                    <div class="user-details">
                        <p style="font-weight:600; color:#ffffff;"><?php echo htmlspecialchars(substr($barbero_nombre, 0, 15)); ?></p>
                        <p style="font-size:10px; color:#b49363; font-weight:700; text-transform:uppercase; margin-top:2px;"><?php echo htmlspecialchars(substr($sucursal_nombre, 0, 18)); ?></p>
                    </div>
                </div>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Salir
                </a>
            </div>
        </aside>

        <!-- MAIN -->
        <div class="main-content">
            <div class="content">
                <div class="content-max">
                    <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-bottom:16px">
                        <?php if ($msg): ?><span class="msg-toast"><i class="fas fa-circle-check"></i> <?php echo $msg; ?></span><?php endif; ?>
                        <span class="header-badge" style="background:#eef2ff;color:#3730a3;border-color:#c7d2fe">
                            <i class="fas fa-hourglass-half"></i>
                            <?php echo $n_programadas; ?> Pendiente<?php echo $n_programadas != 1 ? 's' : ''; ?>
                        </span>
                        <span class="header-badge">
                            <i class="fas fa-calendar-day"></i>
                            <?php echo $total_agenda; ?> Turno<?php echo $total_agenda != 1 ? 's' : ''; ?>
                        </span>
                    </div>
                    <?php if ($page === 'agenda'): ?>
                        <!-- FILTRO DE CITAS -->
                        <form method="GET" style="display:flex; flex-wrap:wrap; gap:12px; margin-bottom:20px; align-items:flex-end; background:#ffffff; border:1px solid #e5e7eb; border-radius:10px; padding:16px;">
                            <input type="hidden" name="page" value="agenda">
                            
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

                            <div style="width: 150px;">
                                <label style="font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:4px; display:block;">Fecha</label>
                                <input type="date" name="f_fecha" value="<?php echo htmlspecialchars($f_fecha); ?>" style="font-size:12px; padding:8px 12px; height:38px; border:1px solid #e5e7eb; border-radius: 6px; width: 100%;">
                            </div>

                            <div style="display:flex; gap:8px;">
                                <button type="submit" style="height:38px; padding:0 16px; background:#b49363; border:none; color:white; font-size:12px; font-weight:600; border-radius:6px; cursor:pointer;">
                                    <i class="fas fa-filter" style="margin-right:6px;"></i> Filtrar
                                </button>
                                <?php if ($f_estado !== 'todos' || !empty($f_fecha) || !empty($f_q)): ?>
                                    <a href="?page=agenda" style="height:38px; display:inline-flex; align-items:center; justify-content:center; padding:0 16px; border:1px solid #e5e7eb; border-radius:6px; color:#4b5563; text-decoration:none; font-size:12px; font-weight:600; background:#ffffff;">
                                        Limpiar
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <div class="section-title">
                            <i class="fas fa-list-check"></i>
                            Próximos Clientes
                        </div>

                        <?php if (count($citas_arr) == 0): ?>
                            <div style="padding:40px; text-align:center; background:#ffffff; border:1px solid #e5e7eb; border-radius:12px; color:#94a3b8;">
                                <i class="fas fa-calendar-times" style="font-size:32px; margin-bottom:12px; display:block;"></i>
                                <p style="font-weight:600">No se encontraron citas con los filtros aplicados.</p>
                            </div>
                        <?php else: ?>
                            <div class="citas-grid">
                                <?php foreach ($citas_arr as $row):
                                    $est = $row['estado'];
                                    $cardClass = $est === 'completada' ? 'is-done' : ($est === 'cancelada' ? 'is-cancelled' : '');
                                ?>
                                    <div class="cita-card <?php echo $cardClass; ?>">
                                        <div class="cita-card-header">
                                            <div class="cita-time">
                                                <?php echo date('H:i', strtotime($row['hora'])); ?>
                                            </div>
                                            <div class="cita-date">
                                                <?php echo date('d/m/Y', strtotime($row['fecha'])); ?>
                                            </div>
                                        </div>
                                        <div class="cita-card-content">
                                            <div class="cita-client">
                                                <?php echo htmlspecialchars($row['cliente_nombre']); ?>
                                            </div>
                                            <div class="cita-phone">
                                                <i class="fas fa-phone-alt" style="margin-right: 4px;"></i>
                                                <?php echo htmlspecialchars($row['cliente_telefono']); ?>
                                            </div>
                                            <div class="cita-service">
                                                <i class="fas fa-scissors" style="margin-right: 6px;"></i>
                                                <?php echo htmlspecialchars($row['servicio']); ?>
                                            </div>
                                            <div class="cita-footer">
                                                <span class="estado-badge estado-<?php echo $est; ?>">
                                                    <?php echo ucfirst($est); ?>
                                                </span>
                                                <span class="cita-payment <?php echo $row['estado_pago'] == 'pendiente' ? 'cita-status-pending' : 'cita-status-verified'; ?>" style="padding: 4px 8px; border-radius: 4px;">
                                                    Pago <?php echo $row['estado_pago'] == 'pendiente' ? 'Pendiente' : 'OK'; ?>
                                                </span>
                                            </div>

                                            <?php if ($est === 'programada' && $can_manage_citas): ?>
                                            <div class="cita-actions">
                                                <form method="POST" style="flex:1">
                                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                                    <input type="hidden" name="cita_id" value="<?php echo $row['id']; ?>">
                                                    <input type="hidden" name="nuevo_estado" value="completada">
                                                    <button type="submit" class="act-btn act-done"><i class="fas fa-check"></i> Completar</button>
                                                </form>
                                                <form method="POST" style="flex:1" onsubmit="return confirm('¿Cancelar esta cita?')">
                                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                                    <input type="hidden" name="cita_id" value="<?php echo $row['id']; ?>">
                                                    <input type="hidden" name="nuevo_estado" value="cancelada">
                                                    <button type="submit" class="act-btn act-cancel"><i class="fas fa-xmark"></i> Cancelar</button>
                                                </form>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- ESTADISTICAS -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px;">
                            <div class="kpi-card" style="background:#fff; border:1px solid #e5e7eb; border-left:4px solid #111827; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); transition:all 0.2s ease;">
                                <div style="font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; margin-bottom:8px; letter-spacing:0.5px;">Citas de esta semana</div>
                                <div style="font-size:28px; font-weight:700; color:#111827;"><?php echo $citas_esta_semana; ?></div>
                                <div style="font-size:11px; color:#64748b; margin-top:8px;">Últimos 7 días</div>
                            </div>

                            <div class="kpi-card" style="background:#fff; border:1px solid #e5e7eb; border-left:4px solid #111827; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); transition:all 0.2s ease;">
                                <div style="font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; margin-bottom:8px; letter-spacing:0.5px;">Proyección / Rendimiento</div>
                                <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
                                    <?php if ($proyeccion > 0): ?>
                                        <span style="font-size:28px; font-weight:700; color:#10b981;">+<?php echo round($proyeccion, 1); ?>%</span>
                                        <i class="fas fa-arrow-trend-up" style="color:#10b981; font-size:24px;"></i>
                                    <?php elseif ($proyeccion < 0): ?>
                                        <span style="font-size:28px; font-weight:700; color:#ef4444;"><?php echo round($proyeccion, 1); ?>%</span>
                                        <i class="fas fa-arrow-trend-down" style="color:#ef4444; font-size:24px;"></i>
                                    <?php else: ?>
                                        <span style="font-size:28px; font-weight:700; color:#6b7280;">0%</span>
                                        <i class="fas fa-arrows-left-right" style="color:#6b7280; font-size:24px;"></i>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:11px; color:#64748b; margin-top:8px;">Comparado con semana anterior</div>
                            </div>

                            <div class="kpi-card" style="background:#fff; border:1px solid #e5e7eb; border-left:4px solid #111827; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); transition:all 0.2s ease;">
                                <div style="font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; margin-bottom:8px; letter-spacing:0.5px;">Total Histórico Completado</div>
                                <div style="font-size:28px; font-weight:700; color:#b49363;"><?php echo $n_completadas; ?></div>
                                <div style="font-size:11px; color:#64748b; margin-top:8px;">Citas atendidas con éxito</div>
                            </div>
                        </div>

                        <!-- TOP SERVICES -->
                        <div class="card" style="background:#fff; border:1px solid #e5e7eb; border-top:4px solid #111827; border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,0.05); overflow:hidden;">
                            <div class="card-header" style="padding:16px; border-bottom:1px solid #e5e7eb; border-left:4px solid #b49363; font-weight:700; font-size:14px; text-transform:uppercase; color:#111827; display:flex; align-items:center; gap:8px;">
                                <i class="fas fa-star" style="color:#b49363;"></i> Servicios más realizados
                            </div>
                            <div style="padding:20px;">
                                <?php
                                $stmt_top = $conn->prepare("SELECT servicio, COUNT(*) as total FROM citas WHERE barbero_id = ? AND estado = 'completada' GROUP BY servicio ORDER BY total DESC LIMIT 5");
                                $stmt_top->bind_param("i", $id_barbero); $stmt_top->execute();
                                $top_res = $stmt_top->get_result();
                                $stmt_top->close();
                                if ($top_res->num_rows == 0):
                                ?>
                                    <p style="text-align:center; color:#94a3b8; padding:20px; font-size:13px;">No hay datos suficientes para proyectar estadísticas. ¡Completa citas en tu agenda!</p>
                                <?php else: ?>
                                    <div style="display:flex; flex-direction:column; gap:16px;">
                                        <?php 
                                        $max_qty = 1;
                                        $rows = [];
                                        while ($s_row = $top_res->fetch_assoc()) {
                                            $rows[] = $s_row;
                                            if ($s_row['total'] > $max_qty) $max_qty = $s_row['total'];
                                        }
                                        foreach ($rows as $s_row): 
                                            $pct = ($s_row['total'] / $max_qty) * 100;
                                        ?>
                                            <div>
                                                <div style="display:flex; justify-content:space-between; font-size:13px; font-weight:600; color:#111827; margin-bottom:6px;">
                                                    <span><?php echo htmlspecialchars($s_row['servicio']); ?></span>
                                                    <span style="color:#b49363;"><?php echo $s_row['total']; ?> citas</span>
                                                </div>
                                                <div style="height:8px; background:#f1f5f9; border-radius:4px; overflow:hidden;">
                                                    <div style="width:<?php echo $pct; ?>%; height:100%; background:linear-gradient(90deg, #b49363, #9d7e54); border-radius:4px;"></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.getElementById('sidebar-toggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.add('open');
        });
        document.getElementById('sidebar-close')?.addEventListener('click', function() {
            document.querySelector('.sidebar').classList.remove('open');
        });
        document.addEventListener('click', function(e) {
            const sidebar = document.querySelector('.sidebar');
            const toggle = document.getElementById('sidebar-toggle');
            if (window.innerWidth <= 640 && sidebar.classList.contains('open')) {
                if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });
    </script>
</body>
</html>


