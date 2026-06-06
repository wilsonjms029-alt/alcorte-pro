<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../backend/config/config.php';

// Seguridad del rol
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'barbero') {
    header("Location: ../index.php");
    exit;
}

$id_barbero = intval($_SESSION['barbero_id']);

// Obtener las citas de la agenda exclusivas de este barbero
$stmt = $conn->prepare("SELECT * FROM citas WHERE barbero_id = ? ORDER BY fecha ASC, hora ASC");
$stmt->bind_param("i", $id_barbero);
$stmt->execute();
$mis_citas = $stmt->get_result();
$total_agenda = $mis_citas->num_rows;
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
            color: #6b7280;
            transition: all 0.2s;
            cursor: pointer;
            background: #fef3f2;
            border-left: 4px solid #b49363;
            padding-left: 12px;
            color: #111827;
        }

        .nav-item i {
            width: 20px;
            color: #b49363;
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
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        .cita-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            transition: all 0.2s;
        }

        .cita-card:hover {
            border-color: #b49363;
            box-shadow: 0 4px 12px rgba(180, 147, 99, 0.1);
        }

        .cita-card-header {
            padding: 16px;
            background: linear-gradient(135deg, #b49363 0%, #9d7e54 100%);
            color: white;
            border-left: 4px solid #7c5c3d;
        }

        .cita-time {
            font-size: 28px;
            font-weight: 700;
            font-family: 'Monaco', monospace;
            line-height: 1;
            margin-bottom: 4px;
        }

        .cita-date {
            font-size: 12px;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .cita-card-content {
            padding: 16px;
        }

        .cita-client {
            font-size: 16px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
        }

        .cita-phone {
            font-size: 11px;
            color: #9ca3af;
            font-family: monospace;
            margin-bottom: 12px;
        }

        .cita-service {
            padding: 8px;
            background: #f9fafb;
            border-radius: 6px;
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 12px;
            border-left: 3px solid #b49363;
            padding-left: 12px;
        }

        .cita-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 12px;
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
            .citas-grid {
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
    <div class="barbero-container">
        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="sidebar-brand">
                <i class="fas fa-scissors"></i>
                <span>AlCorte</span>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-item">
                    <i class="fas fa-calendar-check"></i>
                    <span>Mi Agenda</span>
                </div>
            </nav>

            <div class="sidebar-user">
                <div class="user-info">
                    <div class="user-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="user-details">
                        <p><?php echo htmlspecialchars(substr($_SESSION['nombre'], 0, 12)); ?></p>
                        <p>Barbero</p>
                    </div>
                </div>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Salir
                </a>
            </div>
        </aside>

        <!-- MAIN -->
        <div class="main-content">
            <!-- HEADER -->
            <header class="header">
                <span class="header-breadcrumb">Mi Agenda de Turnos</span>
                <span class="header-badge">
                    <i class="fas fa-calendar-day"></i>
                    <?php echo $total_agenda; ?> Turno<?php echo $total_agenda != 1 ? 's' : ''; ?>
                </span>
            </header>

            <!-- CONTENT -->
            <div class="content">
                <div class="content-max">
                    <div class="section-title">
                        <i class="fas fa-list-check"></i>
                        Próximos Clientes
                    </div>

                    <?php if ($total_agenda == 0): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-calendar-times"></i>
                            </div>
                            <p class="empty-state-text">No tienes citas agendadas por ahora. ¡Descansa!</p>
                        </div>
                    <?php else: ?>
                        <div class="citas-grid">
                            <?php while ($row = $mis_citas->fetch_assoc()): ?>
                                <div class="cita-card">
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
                                            <span class="cita-payment">Pago</span>
                                            <span class="cita-payment <?php echo $row['estado_pago'] == 'pendiente' ? 'cita-status-pending' : 'cita-status-verified'; ?>" style="padding: 4px 8px; border-radius: 4px;">
                                                <?php echo $row['estado_pago'] == 'pendiente' ? 'Pendiente' : 'Verificado'; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>


