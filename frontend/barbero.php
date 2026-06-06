<?php
require_once '../backend/config/config.php';

// Seguridad del rol
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'barbero') {
    header("Location: ../index.php");
    exit;
}

$id_barbero = intval($_SESSION['barbero_id']);
$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';

// Marcar cita como completada / cancelada (solo citas propias)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cita_id'], $_POST['nuevo_estado'])) {
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

// Obtener las citas del barbero (programadas primero, luego por fecha/hora)
$stmt = $conn->prepare(
    "SELECT * FROM citas WHERE barbero_id = ?
     ORDER BY CASE estado WHEN 'programada' THEN 0 WHEN 'completada' THEN 1 ELSE 2 END,
              fecha ASC, hora ASC"
);
$stmt->bind_param("i", $id_barbero);
$stmt->execute();
$res = $stmt->get_result();
$citas_arr = [];
while ($c = $res->fetch_assoc()) { $citas_arr[] = $c; }
$stmt->close();

$total_agenda  = count($citas_arr);
$n_programadas = count(array_filter($citas_arr, fn($c) => $c['estado'] === 'programada'));
$n_completadas = count(array_filter($citas_arr, fn($c) => $c['estado'] === 'completada'));
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
                <div style="display:flex;align-items:center;gap:10px">
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

                                        <?php if ($est === 'programada'): ?>
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
                </div>
            </div>
        </div>
    </div>
</body>
</html>


