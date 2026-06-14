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

// PROCESAR APROBACIÓN DE PAGO SEGURA (PREPARED STATEMENTS)
if (isset($_POST['verificar_id'])) {
    if (!csrf_validate()) {
        header("Location: index.php?page=citas&msg=Error+de+seguridad");
        exit;
    }
    csrf_regenerate();

    $id_cita = intval($_POST['verificar_id']);

    if ($is_scoped) {
        $check = $conn->prepare("SELECT id FROM citas WHERE id = ? AND sucursal_id = ?");
        $check->bind_param("ii", $id_cita, $scope_id);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            header("Location: index.php?page=citas&msg=Acceso+denegado");
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

    header("Location: index.php?page=citas&msg=Pago+verificado+con+éxito+y+puntos+asignados");
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
            background: #fef3f2;
            color: #111827;
            border-left: 4px solid #b49363;
            padding-left: 12px;
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

        .kpi-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
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
            font-weight: 700;
            color: #111827;
            font-family: 'Monaco', monospace;
            margin-bottom: 12px;
        }

        .kpi-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            font-size: 18px;
            margin-top: 12px;
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
    <div class="admin-container">
        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <i class="fas fa-scissors"></i>
                <span>AlCorte</span>
            </div>

            <nav class="sidebar-nav">
                <a href="?page=citas" class="nav-item <?php echo $page == 'citas' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-check"></i>
                    <span>Control de Citas</span>
                </a>
                <a href="?page=clientes" class="nav-item <?php echo $page == 'clientes' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span>Clientes</span>
                </a>
                <a href="?page=personal" class="nav-item <?php echo $page == 'personal' ? 'active' : ''; ?>">
                    <i class="fas fa-user-tie"></i>
                    <span>Equipo</span>
                </a>
                <a href="?page=servicios" class="nav-item <?php echo $page == 'servicios' ? 'active' : ''; ?>">
                    <i class="fas fa-scissors"></i>
                    <span>Servicios</span>
                </a>
                <a href="?page=ajustes" class="nav-item <?php echo $page == 'ajustes' ? 'active' : ''; ?>">
                    <i class="fas fa-sliders-h"></i>
                    <span>Configuración</span>
                </a>
            </nav>

            <div class="sidebar-user">
                <div class="user-info">
                    <div class="user-avatar">AD</div>
                    <div class="user-details">
                        <p><?php echo htmlspecialchars(substr($_SESSION['nombre'], 0, 12)); ?></p>
                        <p><?php echo $_SESSION['rol'] == 'gerente' ? 'Gerente' : 'Admin'; ?></p>
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
            <header class="header">
                <span class="header-breadcrumb">
                    <?php echo strtoupper($_SESSION['rol']); ?> / <?php echo strtoupper(str_replace('_', ' ', $page)); ?>
                </span>
                <?php if (!empty($msg)): ?>
                    <span class="success-badge">✓ <?php echo $msg; ?></span>
                <?php endif; ?>
            </header>

            <!-- CONTENT -->
            <div class="content">
                <!-- KPI CARDS -->
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-label">Turnos Agendados (Hoy)</div>
                        <div class="kpi-value"><?php echo $hoy_citas; ?></div>
                        <div class="kpi-icon" style="background: #fef3c7; color: #f59e0b;">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-label">Por Validar</div>
                        <div class="kpi-value"><?php echo $pendientes_hoy; ?></div>
                        <div class="kpi-icon" style="background: #fef08a; color: #eab308;">
                            <i class="fas fa-hourglass-half"></i>
                        </div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-label">Clientes Club VIP</div>
                        <div class="kpi-value"><?php echo $total_clientes_club; ?></div>
                        <div class="kpi-icon" style="background: #dcfce7; color: #22c55e;">
                            <i class="fas fa-crown"></i>
                        </div>
                    </div>
                </div>

                <!-- PAGE CONTENT -->
                <?php if ($page == 'citas'): ?>
                    <div class="card">
                        <div class="card-header">Control de Citas</div>
                        <div class="card-content">
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
                                    <?php
                                    if ($is_scoped) {
                                        $citas = $conn->prepare("SELECT c.*, b.nombre as barbero_nombre FROM citas c LEFT JOIN barberos b ON c.barbero_id = b.id WHERE c.sucursal_id = ? ORDER BY c.fecha DESC, c.hora DESC");
                                        $citas->bind_param("i", $scope_id);
                                        $citas->execute();
                                        $citas = $citas->get_result();
                                    } else {
                                        $citas = $conn->query("SELECT c.*, b.nombre as barbero_nombre FROM citas c LEFT JOIN barberos b ON c.barbero_id = b.id ORDER BY c.fecha DESC, c.hora DESC");
                                    }
                                    while ($row = $citas->fetch_assoc()):
                                    ?>
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
                                            <?php if ($row['estado_pago'] == 'pendiente'): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                                    <input type="hidden" name="verificar_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" class="btn btn-primary">Aprobar</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #9ca3af; font-size: 12px;">✓</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($page == 'clientes'): ?>
                    <div class="card">
                        <div class="card-header">Clientes VIP Registrados</div>
                        <div class="card-content">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>Teléfono</th>
                                        <th>Puntos</th>
                                        <th>Última Visita</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $res_c = $conn->query("SELECT * FROM clientes ORDER BY puntos DESC");
                                    while ($c = $res_c->fetch_assoc()):
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($c['nombre']); ?></strong></td>
                                        <td style="font-family: monospace;"><?php echo htmlspecialchars($c['telefono']); ?></td>
                                        <td><i class="fas fa-star" style="color: #b49363; margin-right: 4px;"></i> <?php echo $c['puntos']; ?></td>
                                        <td style="color: #9ca3af;"><?php echo date('d/m/Y', strtotime($c['ultima_visita'])); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($page == 'personal'): ?>
                    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px;">
                        <div class="card">
                            <div class="card-header">Registrar Barbero</div>
                            <div style="padding: 20px;">
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

                                    <div id="barb_credentials_section" style="border-top: 1px solid #e5e7eb; margin-top: 16px; padding-top: 16px;">
                                        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; margin-bottom: 12px;">
                                            <i class="fas fa-lock" style="margin-right: 4px;"></i> Acceso al Sistema
                                        </div>
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

                                    <button type="submit" class="btn btn-primary" style="width: 100%; background: #b49363; color: white; padding: 10px; margin-top: 16px;">
                                        Guardar
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">Barberos en Sistema</div>
                            <div style="padding: 20px; display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 16px;">
                                <?php
                                $barb = $conn->query("SELECT * FROM barberos");
                                while ($b = $barb->fetch_assoc()):
                                ?>
                                <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; background: #fafbfc;">
                                    <div style="display: flex; gap: 12px; margin-bottom: 12px;">
                                        <img src="<?php echo $b['foto_url']; ?>" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover;">
                                        <div>
                                            <div style="font-weight: 700; color: #111827;"><?php echo htmlspecialchars($b['nombre']); ?></div>
                                            <small style="color: #9ca3af;"><?php echo date('h:i A', strtotime($b['hora_inicio'])); ?> - <?php echo date('h:i A', strtotime($b['hora_fin'])); ?></small>
                                        </div>
                                    </div>
                                    <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                                        <button type="button" onclick="editarBarbero(<?php echo $b['id']; ?>, '<?php echo htmlspecialchars($b['nombre'], ENT_QUOTES); ?>', '<?php echo $b['hora_inicio']; ?>', '<?php echo $b['hora_fin']; ?>', '<?php echo $b['almuerzo_inicio']; ?>', '<?php echo $b['almuerzo_fin']; ?>', <?php echo $b['activo']; ?>)" style="flex: 1; padding: 6px; background: #e5e7eb; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; color: #6b7280;">Editar</button>
                                        <form action="../backend/processing/admin.php" method="POST" style="flex: 1; display: contents;" onsubmit="return confirm('¿Eliminar?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                            <input type="hidden" name="action" value="delete_barbero">
                                            <input type="hidden" name="id" value="<?php echo $b['id']; ?>">
                                            <button type="submit" style="flex: 1; padding: 6px; background: #fee2e2; border: none; border-radius: 4px; font-size: 12px; cursor: pointer; color: #991b1b;">Eliminar</button>
                                        </form>
                                    </div>
                                    <div>
                                        <span class="badge" style="<?php echo $b['activo'] == 1 ? 'background: #dcfce7; color: #166534;' : 'background: #fee2e2; color: #991b1b;'; ?>">
                                            <?php echo $b['activo'] == 1 ? 'Visible' : 'Oculto'; ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endwhile; ?>
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
                        }
                    </script>

                <?php elseif ($page == 'servicios'):
                    $svc_suc = $is_scoped ? $scope_id : 1;
                    $svc_res = $conn->prepare("SELECT * FROM servicios WHERE sucursal_id = ? ORDER BY orden ASC, id ASC");
                    $svc_res->bind_param("i", $svc_suc);
                    $svc_res->execute();
                    $svc_list = $svc_res->get_result()->fetch_all(MYSQLI_ASSOC);
                ?>
                    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px;">

                        <!-- FORMULARIO -->
                        <div class="card">
                            <div class="card-header" id="svc_form_title">Agregar Servicio</div>
                            <div style="padding: 20px;">
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

                                    <button type="submit" class="btn btn-primary" style="width:100%;background:#b49363;color:white;padding:10px;margin-top:8px;">
                                        <i class="fas fa-save" style="margin-right:6px;"></i> Guardar
                                    </button>
                                    <button type="button" onclick="resetSvcForm()" id="svc_cancel_btn"
                                            style="display:none;width:100%;margin-top:8px;padding:10px;border:1.5px solid #e5e7eb;border-radius:6px;background:#f9fafb;color:#6b7280;cursor:pointer;">
                                        Cancelar
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- LISTA DE SERVICIOS -->
                        <div class="card">
                            <div class="card-header">Servicios registrados</div>
                            <div style="padding: 20px;">
                                <?php if (empty($svc_list)): ?>
                                    <p style="color:#9ca3af;font-size:13px;">No hay servicios registrados aún.</p>
                                <?php else: ?>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Ícono</th>
                                            <th>Nombre</th>
                                            <th>Precio</th>
                                            <th>Duración</th>
                                            <th>Estado</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($svc_list as $sv): ?>
                                    <tr>
                                        <td style="text-align:center;font-size:18px;color:#b49363;">
                                            <i class="<?php echo htmlspecialchars($sv['icono']); ?>"></i>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($sv['nombre']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($sv['precio']); ?></td>
                                        <td><?php echo htmlspecialchars($sv['duracion']); ?></td>
                                        <td>
                                            <span class="badge" style="<?php echo $sv['activo'] ? 'background:#dcfce7;color:#166534' : 'background:#fee2e2;color:#991b1b'; ?>">
                                                <?php echo $sv['activo'] ? 'Activo' : 'Inactivo'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button type="button"
                                                onclick="editSvc(<?php echo $sv['id']; ?>,'<?php echo htmlspecialchars($sv['nombre'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($sv['precio'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($sv['duracion'],ENT_QUOTES); ?>','<?php echo htmlspecialchars($sv['icono'],ENT_QUOTES); ?>',<?php echo $sv['activo']; ?>,<?php echo $sv['orden']; ?>)"
                                                style="padding:5px 10px;background:#e5e7eb;border:none;border-radius:4px;font-size:12px;cursor:pointer;color:#6b7280;margin-right:4px;">
                                                Editar
                                            </button>
                                            <form action="../backend/processing/admin.php" method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este servicio?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                                <input type="hidden" name="action"  value="delete_servicio">
                                                <input type="hidden" name="svc_id"  value="<?php echo $sv['id']; ?>">
                                                <button type="submit" style="padding:5px 10px;background:#fee2e2;border:none;border-radius:4px;font-size:12px;cursor:pointer;color:#991b1b;">
                                                    Eliminar
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php endif; ?>
                            </div>
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
                        document.getElementById('svc_cancel_btn').style.display = 'block';
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
                    <div class="card">
                        <div class="card-header">Configuración de Métodos de Pago</div>
                        <div style="padding: 20px;">
                            <form action="../backend/processing/admin.php" method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                <input type="hidden" name="action" value="update_sys_settings">

                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px;">
                                    <!-- PAGO MÓVIL -->
                                    <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                                            <span style="font-weight: 700; color: #111827;">
                                                <i class="fas fa-mobile-alt" style="color: #3b82f6; margin-right: 8px;"></i> Pago Móvil
                                            </span>
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="estado_pago_movil" value="1" <?php echo ($config['estado_pago_movil'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                        <div class="form-group">
                                            <input type="text" name="banco_nombre" class="form-input" placeholder="Banco" value="<?php echo htmlspecialchars($config['banco_nombre'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group">
                                            <input type="text" name="banco_telefono" class="form-input" placeholder="Teléfono" value="<?php echo htmlspecialchars($config['banco_telefono'] ?? ''); ?>">
                                        </div>
                                        <div class="form-group">
                                            <input type="text" name="banco_ci" class="form-input" placeholder="Cédula/RIF" value="<?php echo htmlspecialchars($config['banco_ci'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <!-- ZELLE -->
                                    <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                                            <span style="font-weight: 700; color: #111827;">
                                                <i class="fas fa-dollar-sign" style="color: #8b5cf6; margin-right: 8px;"></i> Zelle
                                            </span>
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="estado_zelle" value="1" <?php echo ($config['estado_zelle'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                        <div class="form-group">
                                            <input type="email" name="zelle_email" class="form-input" placeholder="Email Zelle" value="<?php echo htmlspecialchars($config['zelle_email'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <!-- EFECTIVO -->
                                    <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                                            <span style="font-weight: 700; color: #111827;">
                                                <i class="fas fa-money-bill-wave" style="color: #10b981; margin-right: 8px;"></i> Efectivo
                                            </span>
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="estado_efectivo" value="1" <?php echo ($config['estado_efectivo'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                        <small style="color: #9ca3af;">Pago directo en local sin necesidad de referencia</small>
                                    </div>
                                </div>

                                <div style="margin-top: 24px; text-align: right;">
                                    <button type="submit" class="btn" style="background: #b49363; color: white; padding: 10px 24px;">
                                        <i class="fas fa-save" style="margin-right: 6px;"></i> Guardar Ajustes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>


