<?php
/**
 * AlCorte Pro - Panel de Estadísticas y Calculadora de Comisiones de Equipo
 * Incluido dentro de frontend/admin.php cuando $page === 'estadisticas'
 */

if (!defined('DB_HOST') && !isset($conn)) {
    die('Acceso denegado');
}

// ─────────────────────────────────────────────────────────────
// PARÁMETROS DE FILTRADO
// ─────────────────────────────────────────────────────────────
$f_rango   = $_GET['f_rango'] ?? 'mes';
$f_desde   = trim($_GET['f_desde'] ?? '');
$f_hasta   = trim($_GET['f_hasta'] ?? '');
$f_barbero = trim($_GET['f_barbero'] ?? 'todos');
$pct_base  = floatval($_GET['pct_base'] ?? 50);
if ($pct_base <= 0 || $pct_base > 100) $pct_base = 50;
$monto_fijo_persona = floatval($_GET['monto_fijo_persona'] ?? 5.00);
$modo_comision = $_GET['modo_comision'] ?? 'porcentaje'; // 'porcentaje' o 'fijo_persona'

// Fechas según rango seleccionado
$hoy_ymd = date('Y-m-d');
$label_rango = 'Este Mes';

switch ($f_rango) {
    case 'hoy':
        $desde = $hoy_ymd;
        $hasta = $hoy_ymd;
        $label_rango = 'Hoy (' . date('d/m/Y') . ')';
        break;
    case 'semana':
        $desde = date('Y-m-d', strtotime('-6 days'));
        $hasta = $hoy_ymd;
        $label_rango = 'Últimos 7 días (' . date('d/m/Y', strtotime($desde)) . ' - ' . date('d/m/Y') . ')';
        break;
    case 'mes_ant':
        $desde = date('Y-m-01', strtotime('first day of last month'));
        $hasta = date('Y-m-t', strtotime('last day of last month'));
        $label_rango = 'Mes Anterior (' . date('d/m/Y', strtotime($desde)) . ' - ' . date('d/m/Y', strtotime($hasta)) . ')';
        break;
    case 'anio':
        $desde = date('Y-01-01');
        $hasta = date('Y-12-31');
        $label_rango = 'Este Año (' . date('Y') . ')';
        break;
    case 'custom':
        $desde = !empty($f_desde) ? $f_desde : date('Y-m-01');
        $hasta = !empty($f_hasta) ? $f_hasta : $hoy_ymd;
        $label_rango = 'Personalizado (' . date('d/m/Y', strtotime($desde)) . ' - ' . date('d/m/Y', strtotime($hasta)) . ')';
        break;
    case 'todo':
        $desde = '2000-01-01';
        $hasta = '2099-12-31';
        $label_rango = 'Histórico Completo';
        break;
    case 'mes':
    default:
        $f_rango = 'mes';
        $desde = date('Y-m-01');
        $hasta = date('Y-m-t');
        $label_rango = 'Este Mes (' . date('d/m/Y', strtotime($desde)) . ' - ' . date('d/m/Y', strtotime($hasta)) . ')';
        break;
}

// Diccionario de precios de servicios
$servicios_precios = [];
if ($is_scoped) {
    $stmt_sv = $conn->prepare("SELECT LOWER(TRIM(nombre)) as nom, precio, sucursal_id FROM servicios WHERE sucursal_id = ?");
    $stmt_sv->bind_param('i', $scope_id);
    $stmt_sv->execute();
    $res_sv = $stmt_sv->get_result();
} else {
    $res_sv = $conn->query("SELECT LOWER(TRIM(nombre)) as nom, precio, sucursal_id FROM servicios");
}
if ($res_sv) {
    while ($sv = $res_sv->fetch_assoc()) {
        $clean_p = floatval(preg_replace('/[^0-9.]/', '', str_replace(',', '.', $sv['precio'])));
        $servicios_precios[$sv['sucursal_id'] . '_' . $sv['nom']] = $clean_p;
        $servicios_precios['0_' . $sv['nom']] = $clean_p;
    }
}
if (isset($stmt_sv)) $stmt_sv->close();

// Barberos del equipo
$barberos_equipo = [];
if ($is_scoped) {
    $stmt_b = $conn->prepare("SELECT id, nombre, foto_url, activo FROM barberos WHERE sucursal_id = ? ORDER BY activo DESC, nombre ASC");
    $stmt_b->bind_param('i', $scope_id);
    $stmt_b->execute();
    $res_b = $stmt_b->get_result();
} else {
    $res_b = $conn->query("SELECT id, nombre, foto_url, activo, sucursal_id FROM barberos ORDER BY activo DESC, nombre ASC");
}
if ($res_b) {
    while ($b = $res_b->fetch_assoc()) {
        $b_id = intval($b['id']);
        $barberos_equipo[$b_id] = [
            'id'                 => $b_id,
            'nombre'             => $b['nombre'],
            'foto_url'           => !empty($b['foto_url']) ? $b['foto_url'] : ('https://ui-avatars.com/api/?background=b49363&color=fff&name=' . urlencode($b['nombre'])),
            'activo'             => intval($b['activo']),
            'personas_atendidas' => 0,
            'total_facturado'    => 0.0,
            'citas_programadas'  => 0,
            'citas_canceladas'   => 0,
            'servicios_detalle'  => [],
        ];
    }
}
if (isset($stmt_b)) $stmt_b->close();

// Consulta de citas en el rango
$sql_citas = "SELECT c.id, c.barbero_id, c.cliente_nombre, c.cliente_telefono, c.servicio, c.fecha, c.hora, c.metodo_pago, c.estado, c.estado_pago, c.sucursal_id, b.nombre as barbero_nombre
              FROM citas c
              LEFT JOIN barberos b ON c.barbero_id = b.id
              WHERE 1=1";
$params = [];
$types  = "";

if ($is_scoped) {
    $sql_citas .= " AND c.sucursal_id = ?";
    $params[] = $scope_id;
    $types   .= "i";
}

$sql_citas .= " AND c.fecha BETWEEN ? AND ?";
$params[] = $desde;
$params[] = $hasta;
$types   .= "ss";

if ($f_barbero !== 'todos' && intval($f_barbero) > 0) {
    $sql_citas .= " AND c.barbero_id = ?";
    $params[] = intval($f_barbero);
    $types   .= "i";
}

$sql_citas .= " ORDER BY c.fecha ASC, c.hora ASC";

$stmt_c = $conn->prepare($sql_citas);
if ($types) {
    $stmt_c->bind_param($types, ...$params);
}
$stmt_c->execute();
$citas_res = $stmt_c->get_result();

$total_citas_periodo = 0;
$personas_atendidas  = 0; // Citas completadas
$citas_programadas   = 0;
$citas_canceladas    = 0;
$facturacion_total   = 0.0;
$clientes_unicos     = [];
$servicios_stats     = []; // [servicio => ['count' => X, 'total' => Y]]
$metodos_pago        = []; // [metodo => ['count' => X, 'total' => Y]]
$timeline_data       = []; // [fecha => ['atendidas' => X, 'total' => Y]]

while ($c = $citas_res->fetch_assoc()) {
    $total_citas_periodo++;
    $est = $c['estado'];
    $s_nom_clean = strtolower(trim($c['servicio']));
    $suc_c = intval($c['sucursal_id']);
    $precio = $servicios_precios[$suc_c . '_' . $s_nom_clean] ?? ($servicios_precios['0_' . $s_nom_clean] ?? 0.0);
    $b_id = intval($c['barbero_id'] ?? 0);

    if ($b_id > 0 && !isset($barberos_equipo[$b_id])) {
        $barberos_equipo[$b_id] = [
            'id'                 => $b_id,
            'nombre'             => $c['barbero_nombre'] ?: "Barbero #$b_id",
            'foto_url'           => 'https://ui-avatars.com/api/?background=b49363&color=fff&name=' . urlencode($c['barbero_nombre'] ?: 'Barbero'),
            'activo'             => 1,
            'personas_atendidas' => 0,
            'total_facturado'    => 0.0,
            'citas_programadas'  => 0,
            'citas_canceladas'   => 0,
            'servicios_detalle'  => [],
        ];
    }

    if ($est === 'completada') {
        $personas_atendidas++;
        $facturacion_total += $precio;
        if (!empty($c['cliente_telefono'])) {
            $clientes_unicos[$c['cliente_telefono']] = true;
        }

        // Estadísticas por servicio
        $s_label = $c['servicio'] ?: 'General';
        if (!isset($servicios_stats[$s_label])) {
            $servicios_stats[$s_label] = ['count' => 0, 'total' => 0.0];
        }
        $servicios_stats[$s_label]['count']++;
        $servicios_stats[$s_label]['total'] += $precio;

        // Por método de pago
        $mp = !empty($c['metodo_pago']) ? $c['metodo_pago'] : 'Efectivo';
        if (!isset($metodos_pago[$mp])) {
            $metodos_pago[$mp] = ['count' => 0, 'total' => 0.0];
        }
        $metodos_pago[$mp]['count']++;
        $metodos_pago[$mp]['total'] += $precio;

        // Por barbero
        if ($b_id > 0) {
            $barberos_equipo[$b_id]['personas_atendidas']++;
            $barberos_equipo[$b_id]['total_facturado'] += $precio;
            $barberos_equipo[$b_id]['servicios_detalle'][$s_label] = ($barberos_equipo[$b_id]['servicios_detalle'][$s_label] ?? 0) + 1;
        }

        // Timeline
        $f_dia = $c['fecha'];
        if (!isset($timeline_data[$f_dia])) {
            $timeline_data[$f_dia] = ['atendidas' => 0, 'total' => 0.0];
        }
        $timeline_data[$f_dia]['atendidas']++;
        $timeline_data[$f_dia]['total'] += $precio;

    } elseif ($est === 'programada') {
        $citas_programadas++;
        if ($b_id > 0) {
            $barberos_equipo[$b_id]['citas_programadas']++;
        }
    } elseif ($est === 'cancelada') {
        $citas_canceladas++;
        if ($b_id > 0) {
            $barberos_equipo[$b_id]['citas_canceladas']++;
        }
    }
}
$stmt_c->close();

$total_clientes_unicos = count($clientes_unicos);
$tasa_completadas = $total_citas_periodo > 0 ? round(($personas_atendidas / $total_citas_periodo) * 100, 1) : 0;
$ticket_promedio = $personas_atendidas > 0 ? round($facturacion_total / $personas_atendidas, 2) : 0.0;

// Ordenar top servicios por cantidad
uasort($servicios_stats, function($a, $b) {
    return $b['count'] <=> $a['count'];
});

// Totales de equipo para comisiones
$total_personas_equipo = 0;
$total_facturado_equipo = 0.0;
foreach ($barberos_equipo as $barb) {
    $total_personas_equipo += $barb['personas_atendidas'];
    $total_facturado_equipo += $barb['total_facturado'];
}
$comision_equipo_inicial = $modo_comision === 'fijo_persona'
    ? ($total_personas_equipo * $monto_fijo_persona)
    : ($total_facturado_equipo * ($pct_base / 100));
$margen_barberia_inicial = max(0, $total_facturado_equipo - $comision_equipo_inicial);

// Preparar datos para Chart.js
$chart_labels = [];
$chart_atendidas = [];
$chart_ingresos = [];

$start_ts = strtotime($desde);
$end_ts   = min(strtotime($hasta), strtotime('+31 days', $start_ts));
$diff_days = ($end_ts - $start_ts) / 86400;

if ($diff_days <= 32 && $diff_days >= 0 && $desde !== '2000-01-01') {
    $cur_ts = $start_ts;
    while ($cur_ts <= $end_ts) {
        $d_ymd = date('Y-m-d', $cur_ts);
        $d_label = date('d/m', $cur_ts);
        $chart_labels[] = $d_label;
        $chart_atendidas[] = $timeline_data[$d_ymd]['atendidas'] ?? 0;
        $chart_ingresos[]  = round($timeline_data[$d_ymd]['total'] ?? 0.0, 2);
        $cur_ts += 86400;
    }
} else {
    ksort($timeline_data);
    if (!empty($timeline_data)) {
        foreach ($timeline_data as $d_ymd => $d_val) {
            $chart_labels[] = date('d/m', strtotime($d_ymd));
            $chart_atendidas[] = $d_val['atendidas'];
            $chart_ingresos[]  = round($d_val['total'], 2);
        }
    } else {
        $chart_labels = ['Sin datos'];
        $chart_atendidas = [0];
        $chart_ingresos = [0];
    }
}

$suc_url_param = ($is_scoped && $scope_id) ? '&suc=' . intval($scope_id) : '';
?>

<!-- ESTILOS ESPECÍFICOS DEL MÓDULO DE ESTADÍSTICAS Y COMISIONES -->
<style>
    .stats-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 24px;
    }
    .stats-title {
        font-size: 24px;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: -0.5px;
    }
    .stats-title span {
        color: #b49363;
    }
    .stats-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 11px;
        font-weight: 700;
        padding: 5px 12px;
        border-radius: 20px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .stats-badge.pro {
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fde68a;
    }
    .stats-badge.basic {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
    }

    /* FILTROS */
    .filter-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 16px 20px;
        margin-bottom: 24px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    }
    .filter-pills {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 14px;
    }
    .filter-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 14px;
        border-radius: 8px;
        font-size: 12px;
        font-weight: 600;
        text-decoration: none;
        color: #64748b;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        transition: all 0.15s ease;
    }
    .filter-pill:hover {
        background: #f1f5f9;
        color: #0f172a;
    }
    .filter-pill.active {
        background: #0f172a;
        color: #ffffff;
        border-color: #0f172a;
    }

    /* KPIS */
    .stat-hero-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .stat-hero-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 18px 20px;
        position: relative;
        overflow: hidden;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .stat-hero-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    .stat-hero-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: #0f172a;
    }
    .stat-hero-card.gold::before { background: #b49363; }
    .stat-hero-card.emerald::before { background: #10b981; }
    .stat-hero-card.blue::before { background: #3b82f6; }
    .stat-hero-card.amber::before { background: #f59e0b; }

    .stat-hero-title {
        font-size: 11px;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .stat-hero-val {
        font-size: 28px;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.1;
        font-family: 'Inter', sans-serif;
    }
    .stat-hero-sub {
        font-size: 11px;
        color: #94a3b8;
        margin-top: 6px;
    }

    /* CAJA DE COMISIONES DEL EQUIPO */
    .commission-box {
        background: #ffffff;
        border: 2px solid #b49363;
        border-radius: 14px;
        padding: 24px;
        margin-top: 30px;
        margin-bottom: 30px;
        box-shadow: 0 4px 20px rgba(180, 147, 99, 0.08);
        position: relative;
    }
    .commission-box-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 16px;
        padding-bottom: 18px;
        border-bottom: 1px solid #f1f5f9;
        margin-bottom: 20px;
    }
    .commission-toolbar {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 16px;
        margin-bottom: 20px;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }
    .pct-preset-btn {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        padding: 5px 10px;
        font-size: 11px;
        font-weight: 700;
        color: #475569;
        cursor: pointer;
        transition: all 0.15s;
    }
    .pct-preset-btn:hover, .pct-preset-btn.active {
        background: #b49363;
        color: #ffffff;
        border-color: #b49363;
    }
    .commission-kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }
    .com-kpi-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px 16px;
        text-align: left;
    }
    .com-kpi-card.highlight {
        background: #fefce8;
        border-color: #fef08a;
    }
    .com-kpi-card.success {
        background: #ecfdf5;
        border-color: #a7f3d0;
    }
    .com-kpi-label {
        font-size: 10px;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 4px;
    }
    .com-kpi-val {
        font-size: 22px;
        font-weight: 800;
        color: #0f172a;
    }

    /* TABLA DE BARBEROS */
    .barbero-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 13px;
    }
    .barbero-table th {
        background: #f8fafc;
        padding: 10px 14px;
        font-size: 11px;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid #e2e8f0;
        text-align: left;
    }
    .barbero-table td {
        padding: 12px 14px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .barbero-table tr:hover td {
        background: #fcfcfc;
    }

    /* IMPRESIÓN */
    @media print {
        body {
            background: #ffffff !important;
            color: #000000 !important;
        }
        .sidebar, .filter-card, .btn-print, .stats-header, .stat-hero-grid, .charts-grid, .no-print {
            display: none !important;
        }
        .main-content, .content {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
        }
        .commission-box {
            border: 1px solid #000 !important;
            box-shadow: none !important;
            margin: 0 !important;
            padding: 10px !important;
        }
        .print-receipt-header {
            display: block !important;
            margin-bottom: 20px;
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
        }
        .print-signatures {
            display: flex !important;
            justify-content: space-between;
            margin-top: 50px;
            padding-top: 20px;
        }
    }
    .print-receipt-header, .print-signatures {
        display: none;
    }
</style>

<!-- HEADER PRINCIPAL -->
<div class="stats-header">
    <div>
        <h1 class="stats-title">Panel de <span>Estadísticas</span></h1>
        <p style="font-size:13px; color:#64748b; margin-top:4px;">
            Métricas de citas, personas atendidas, facturación estimada y rendimiento del negocio.
        </p>
    </div>
    <div style="display:flex; align-items:center; gap:10px;">
        <?php if ($has_comisiones): ?>
            <span class="stats-badge pro">
                <i class="fas fa-crown"></i> Plan <?= htmlspecialchars($plan_activo['nombre'] ?? 'Profesional / Pro') ?> · Comisiones de Equipo Activas
            </span>
        <?php else: ?>
            <span class="stats-badge basic">
                <i class="fas fa-user"></i> Plan <?= htmlspecialchars($plan_activo['nombre'] ?? 'Básico') ?> · Estadísticas Estándar
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- BARRA DE FILTROS POR FECHA Y BARBERO -->
<div class="filter-card no-print">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
        <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">
            <i class="fas fa-calendar-alt" style="color:#b49363; margin-right:4px;"></i> Período Evaluado:
            <strong style="color:#0f172a; margin-left:4px;"><?= htmlspecialchars($label_rango) ?></strong>
        </div>
        <div style="font-size:11px; color:#94a3b8;">
            Mostrando citas entre <strong><?= date('d/m/Y', strtotime($desde)) ?></strong> y <strong><?= date('d/m/Y', strtotime($hasta)) ?></strong>
        </div>
    </div>

    <!-- Píldoras de rango rápido -->
    <div class="filter-pills">
        <a href="?page=estadisticas&f_rango=hoy<?= $suc_url_param ?>" class="filter-pill <?= $f_rango === 'hoy' ? 'active' : '' ?>">
            <i class="fas fa-clock"></i> Hoy
        </a>
        <a href="?page=estadisticas&f_rango=semana<?= $suc_url_param ?>" class="filter-pill <?= $f_rango === 'semana' ? 'active' : '' ?>">
            <i class="fas fa-calendar-week"></i> Últimos 7 Días
        </a>
        <a href="?page=estadisticas&f_rango=mes<?= $suc_url_param ?>" class="filter-pill <?= $f_rango === 'mes' ? 'active' : '' ?>">
            <i class="fas fa-calendar-day"></i> Este Mes
        </a>
        <a href="?page=estadisticas&f_rango=mes_ant<?= $suc_url_param ?>" class="filter-pill <?= $f_rango === 'mes_ant' ? 'active' : '' ?>">
            <i class="fas fa-backward"></i> Mes Anterior
        </a>
        <a href="?page=estadisticas&f_rango=anio<?= $suc_url_param ?>" class="filter-pill <?= $f_rango === 'anio' ? 'active' : '' ?>">
            <i class="fas fa-calendar"></i> Este Año
        </a>
        <a href="?page=estadisticas&f_rango=todo<?= $suc_url_param ?>" class="filter-pill <?= $f_rango === 'todo' ? 'active' : '' ?>">
            <i class="fas fa-history"></i> Todo el Historial
        </a>
    </div>

    <!-- Formulario de rango personalizado y barbero -->
    <form method="GET" action="" style="display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px; border-top:1px solid #f1f5f9; padding-top:12px;">
        <input type="hidden" name="page" value="estadisticas">
        <input type="hidden" name="f_rango" value="custom">
        <?php if ($is_scoped && $scope_id): ?>
            <input type="hidden" name="suc" value="<?= intval($scope_id) ?>">
        <?php endif; ?>

        <div style="flex:1; min-width:140px;">
            <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:4px; display:block;">Desde</label>
            <input type="date" name="f_desde" value="<?= htmlspecialchars($desde !== '2000-01-01' ? $desde : date('Y-m-01')) ?>"
                   style="width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:0 10px; font-size:12px; color:#0f172a; background:#fff;">
        </div>

        <div style="flex:1; min-width:140px;">
            <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:4px; display:block;">Hasta</label>
            <input type="date" name="f_hasta" value="<?= htmlspecialchars($hasta !== '2099-12-31' ? $hasta : $hoy_ymd) ?>"
                   style="width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:0 10px; font-size:12px; color:#0f172a; background:#fff;">
        </div>

        <div style="flex:1; min-width:170px;">
            <label style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:4px; display:block;">Filtrar por Barbero</label>
            <select name="f_barbero" style="width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:0 10px; font-size:12px; color:#0f172a; background:#fff; cursor:pointer;">
                <option value="todos" <?= $f_barbero === 'todos' ? 'selected' : '' ?>>Todos los barberos</option>
                <?php foreach ($barberos_equipo as $barb_opt): ?>
                    <option value="<?= $barb_opt['id'] ?>" <?= strval($f_barbero) === strval($barb_opt['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($barb_opt['nombre']) ?> <?= $barb_opt['activo'] ? '' : '(Inactivo)' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex; gap:8px;">
            <button type="submit" style="height:38px; padding:0 16px; background:#0f172a; border:none; color:#fff; font-size:12px; font-weight:700; border-radius:6px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                <i class="fas fa-filter"></i> Aplicar
            </button>
            <?php if ($f_rango !== 'mes' || !empty($f_desde) || !empty($f_hasta) || $f_barbero !== 'todos'): ?>
                <a href="?page=estadisticas<?= $suc_url_param ?>" style="height:38px; padding:0 14px; border:1px solid #cbd5e1; background:#ffffff; color:#475569; font-size:12px; font-weight:600; border-radius:6px; text-decoration:none; display:inline-flex; align-items:center; justify-content:center;">
                    Restablecer
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- TARJETAS DE KPIS PRINCIPALES (ACCESIBLES PARA TODOS LOS PLANES) -->
<div class="stat-hero-grid no-print">
    <!-- PERSONAS ATENDIDAS (Cálculo central) -->
    <div class="stat-hero-card emerald">
        <div class="stat-hero-title">
            <span>Personas Atendidas</span>
            <i class="fas fa-user-check" style="color:#10b981; font-size:16px;"></i>
        </div>
        <div class="stat-hero-val" style="color:#10b981;"><?= number_format($personas_atendidas) ?></div>
        <div class="stat-hero-sub">Clientes con cita completada con éxito</div>
    </div>

    <!-- FACTURACIÓN ESTIMADA -->
    <div class="stat-hero-card gold">
        <div class="stat-hero-title">
            <span>Facturación Servicios</span>
            <i class="fas fa-dollar-sign" style="color:#b49363; font-size:16px;"></i>
        </div>
        <div class="stat-hero-val">$<?= number_format($facturacion_total, 2) ?></div>
        <div class="stat-hero-sub">Ingresos calculados según precios de servicios</div>
    </div>

    <!-- CITAS TOTALES REGISTRADAS -->
    <div class="stat-hero-card blue">
        <div class="stat-hero-title">
            <span>Total Citas</span>
            <i class="fas fa-calendar-check" style="color:#3b82f6; font-size:16px;"></i>
        </div>
        <div class="stat-hero-val"><?= number_format($total_citas_periodo) ?></div>
        <div class="stat-hero-sub"><?= $citas_programadas ?> por atender · <?= $citas_canceladas ?> canceladas</div>
    </div>

    <!-- TASA DE CUMPLIMIENTO -->
    <div class="stat-hero-card amber">
        <div class="stat-hero-title">
            <span>Efectividad / Éxito</span>
            <i class="fas fa-chart-pie" style="color:#f59e0b; font-size:16px;"></i>
        </div>
        <div class="stat-hero-val"><?= $tasa_completadas ?>%</div>
        <div class="stat-hero-sub">Porcentaje de asistencia completada</div>
    </div>

    <!-- CLIENTES ÚNICOS -->
    <div class="stat-hero-card">
        <div class="stat-hero-title">
            <span>Clientes Únicos</span>
            <i class="fas fa-users" style="color:#64748b; font-size:16px;"></i>
        </div>
        <div class="stat-hero-val"><?= number_format($total_clientes_unicos) ?></div>
        <div class="stat-hero-sub">Personas distintas atendidas en el período</div>
    </div>

    <!-- TICKET PROMEDIO -->
    <div class="stat-hero-card">
        <div class="stat-hero-title">
            <span>Ticket Promedio</span>
            <i class="fas fa-receipt" style="color:#64748b; font-size:16px;"></i>
        </div>
        <div class="stat-hero-val">$<?= number_format($ticket_promedio, 2) ?></div>
        <div class="stat-hero-sub">Ingreso promedio por persona atendida</div>
    </div>
</div>

<!-- GRÁFICOS Y DESGLOSE VISUAL (DISPONIBLE PARA TODOS LOS PLANES) -->
<div class="charts-grid no-print" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:20px; margin-bottom:24px;">
    <!-- GRÁFICO DE EVOLUCIÓN TEMPORAL -->
    <div class="card" style="padding:20px; background:#fff; border:1px solid #e2e8f0; border-radius:12px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <div style="font-size:13px; font-weight:700; color:#0f172a; text-transform:uppercase; letter-spacing:0.5px;">
                <i class="fas fa-chart-area" style="color:#b49363; margin-right:6px;"></i> Evolución de Personas Atendidas e Ingresos
            </div>
            <div style="font-size:11px; color:#64748b;">
                <span style="display:inline-block; width:10px; height:10px; background:#b49363; border-radius:50%; margin-right:4px;"></span> Atendidas
                <span style="display:inline-block; width:10px; height:10px; background:#0f172a; border-radius:50%; margin-left:10px; margin-right:4px;"></span> Facturación ($)
            </div>
        </div>
        <div style="position:relative; height:240px; width:100%;">
            <canvas id="chartEvolucion"></canvas>
        </div>
    </div>

    <!-- SERVICIOS MÁS SOLICITADOS -->
    <div class="card" style="padding:20px; background:#fff; border:1px solid #e2e8f0; border-radius:12px;">
        <div style="font-size:13px; font-weight:700; color:#0f172a; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:16px;">
            <i class="fas fa-scissors" style="color:#b49363; margin-right:6px;"></i> Servicios Más Realizados
        </div>

        <?php if (empty($servicios_stats)): ?>
            <div style="text-align:center; padding:40px 20px; color:#94a3b8; font-size:13px;">
                <i class="fas fa-info-circle" style="font-size:24px; margin-bottom:8px; display:block;"></i>
                No se registraron servicios completados en este período.
            </div>
        <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:12px;">
                <?php
                $max_count = 1;
                foreach ($servicios_stats as $s_item) {
                    if ($s_item['count'] > $max_count) $max_count = $s_item['count'];
                }
                $i_svc = 0;
                foreach ($servicios_stats as $svc_nombre => $svc_data):
                    if ($i_svc++ >= 5) break;
                    $pct_bar = round(($svc_data['count'] / $max_count) * 100);
                ?>
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; font-size:12px; margin-bottom:4px;">
                            <span style="font-weight:700; color:#0f172a;"><?= htmlspecialchars($svc_nombre) ?></span>
                            <span style="color:#64748b;">
                                <strong><?= $svc_data['count'] ?></strong> personas · <span style="color:#b49363; font-weight:700;">$<?= number_format($svc_data['total'], 2) ?></span>
                            </span>
                        </div>
                        <div style="height:6px; background:#f1f5f9; border-radius:3px; overflow:hidden;">
                            <div style="width:<?= $pct_bar ?>%; height:100%; background:linear-gradient(90deg, #b49363, #9d7e54); border-radius:3px;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- ESPACIO DE COMISIONES DEL EQUIPO (DIFERENCIA PARA LOS DOS PLANES MÁS CAROS) -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<?php if ($has_comisiones): ?>
    <!-- CONTENEDOR EXCLUSIVO PARA LOS 2 PLANES MÁS CAROS (PROFESIONAL Y PRO) -->
    <div id="seccion-comisiones" class="commission-box">
        <!-- HEADER IMPRESO (SOLO APARECE EN MODO IMPRESIÓN/RECIBO) -->
        <div class="print-receipt-header">
            <h2 style="font-size:20px; font-weight:800; text-transform:uppercase; margin:0;"><?= htmlspecialchars($nombre_sucursal ?: 'AlCorte Pro') ?></h2>
            <div style="font-size:13px; color:#555; margin-top:2px;">Liquidación y Pago de Comisiones al Equipo de Barberos</div>
            <div style="font-size:11px; color:#777; margin-top:4px;">
                Período: <strong><?= date('d/m/Y', strtotime($desde)) ?></strong> al <strong><?= date('d/m/Y', strtotime($hasta)) ?></strong> · Emitido el: <?= date('d/m/Y H:i') ?>
            </div>
        </div>

        <!-- HEADER PANTALLA -->
        <div class="commission-box-header no-print">
            <div>
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                    <div style="width:32px; height:32px; border-radius:8px; background:#fef3c7; color:#b45309; display:flex; align-items:center; justify-content:center; font-size:16px;">
                        <i class="fas fa-calculator"></i>
                    </div>
                    <h2 style="font-size:18px; font-weight:800; color:#0f172a; margin:0;">
                        Calculadora y Liquidación de Comisiones del Equipo
                    </h2>
                </div>
                <p style="font-size:13px; color:#64748b; margin:0;">
                    Indica el porcentaje de pago deseado para calcular automáticamente cuánto pagar a cada barbero según las personas atendidas.
                </p>
            </div>
            <div style="display:flex; align-items:center; gap:10px;">
                <span class="stats-badge pro">
                    <i class="fas fa-check-double"></i> Módulo Profesional / Pro
                </span>
                <button type="button" onclick="imprimirLiquidacion()" class="btn-print"
                        style="height:36px; padding:0 14px; background:#0f172a; color:#fff; border:none; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                    <i class="fas fa-print"></i> Imprimir Liquidación
                </button>
            </div>
        </div>

        <!-- BARRA DE CONFIGURACIÓN INTERACTIVA (PORCENTAJE Y MODO) -->
        <div class="commission-toolbar no-print">
            <div style="display:flex; flex-wrap:wrap; align-items:center; gap:16px;">
                <!-- MODO DE CÁLCULO -->
                <div>
                    <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:6px; display:block;">
                        Modo de Cálculo
                    </label>
                    <div style="display:flex; gap:6px;">
                        <label style="font-size:12px; font-weight:600; color:#0f172a; display:inline-flex; align-items:center; gap:4px; cursor:pointer; background:#fff; padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px;">
                            <input type="radio" name="modo_calc" value="porcentaje" checked onchange="toggleModoComision(this.value)">
                            % sobre Facturación de personas atendidas
                        </label>
                        <label style="font-size:12px; font-weight:600; color:#0f172a; display:inline-flex; align-items:center; gap:4px; cursor:pointer; background:#fff; padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px;">
                            <input type="radio" name="modo_calc" value="fijo_persona" onchange="toggleModoComision(this.value)">
                            Monto fijo ($) por persona atendida
                        </label>
                    </div>
                </div>

                <!-- ENTRADA DE PORCENTAJE GLOBAL -->
                <div id="wrap_porcentaje_input">
                    <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:6px; display:block;">
                        % de Pago al Equipo
                    </label>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <div style="position:relative; width:100px;">
                            <input type="number" id="pct_comision_input" min="0" max="100" step="1" value="<?= htmlspecialchars($pct_base) ?>"
                                   oninput="actualizarPctBase(this.value)"
                                   style="width:100%; height:36px; border:1.5px solid #b49363; border-radius:6px; padding:0 24px 0 10px; font-size:14px; font-weight:800; color:#0f172a; background:#fff;">
                            <span style="position:absolute; right:8px; top:8px; font-weight:800; color:#b49363;">%</span>
                        </div>
                        <div style="display:flex; gap:4px;">
                            <button type="button" class="pct-preset-btn <?= $pct_base == 35 ? 'active' : '' ?>" onclick="setPct(35)">35%</button>
                            <button type="button" class="pct-preset-btn <?= $pct_base == 40 ? 'active' : '' ?>" onclick="setPct(40)">40%</button>
                            <button type="button" class="pct-preset-btn <?= $pct_base == 50 ? 'active' : '' ?>" onclick="setPct(50)">50%</button>
                            <button type="button" class="pct-preset-btn <?= $pct_base == 60 ? 'active' : '' ?>" onclick="setPct(60)">60%</button>
                            <button type="button" class="pct-preset-btn <?= $pct_base == 70 ? 'active' : '' ?>" onclick="setPct(70)">70%</button>
                        </div>
                    </div>
                </div>

                <!-- ENTRADA DE MONTO FIJO POR PERSONA (OCULTA POR DEFECTO) -->
                <div id="wrap_monto_fijo_input" style="display:none;">
                    <label style="font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:6px; display:block;">
                        Pago por Cada Persona Atendida ($)
                    </label>
                    <div style="position:relative; width:130px;">
                        <span style="position:absolute; left:10px; top:8px; font-weight:800; color:#10b981;">$</span>
                        <input type="number" id="tarifa_persona_input" min="0" step="0.5" value="<?= number_format($monto_fijo_persona, 2) ?>"
                               oninput="recalcularComisiones()"
                               style="width:100%; height:36px; border:1.5px solid #10b981; border-radius:6px; padding:0 10px 0 24px; font-size:14px; font-weight:800; color:#0f172a; background:#fff;">
                    </div>
                </div>
            </div>

            <div style="font-size:12px; color:#64748b; text-align:right;">
                <div>Cálculo en vivo según <strong>personas atendidas</strong></div>
                <small style="color:#94a3b8;">Puedes personalizar el % individual de cada barbero en la tabla</small>
            </div>
        </div>

        <!-- RESUMEN EN VIVO DE COMISIONES (KPIS) -->
        <div class="commission-kpis">
            <div class="com-kpi-card">
                <div class="com-kpi-label">Personas Atendidas (Equipo)</div>
                <div class="com-kpi-val" id="resumen_total_personas"><?= number_format($total_personas_equipo) ?></div>
                <div style="font-size:11px; color:#64748b; margin-top:2px;">Clientes atendidos en el período</div>
            </div>

            <div class="com-kpi-card">
                <div class="com-kpi-label">Total Generado por el Equipo</div>
                <div class="com-kpi-val" id="resumen_total_facturado">$<?= number_format($total_facturado_equipo, 2) ?></div>
                <div style="font-size:11px; color:#64748b; margin-top:2px;">Facturación total de servicios</div>
            </div>

            <div class="com-kpi-card highlight">
                <div class="com-kpi-label" style="color:#b45309;">
                    <i class="fas fa-hand-holding-dollar"></i> Total Comisiones a Pagar
                </div>
                <div class="com-kpi-val" id="resumen_total_comisiones" style="color:#b45309;">
                    $<?= number_format($comision_equipo_inicial, 2) ?>
                </div>
                <div style="font-size:11px; color:#92400e; margin-top:2px;" id="resumen_pct_efectivo">
                    <?= $pct_base ?>% del total facturado
                </div>
            </div>

            <div class="com-kpi-card success">
                <div class="com-kpi-label" style="color:#065f46;">
                    <i class="fas fa-piggy-bank"></i> Margen Libre para la Barbería
                </div>
                <div class="com-kpi-val" id="resumen_margen_barberia" style="color:#065f46;">
                    $<?= number_format($margen_barberia_inicial, 2) ?>
                </div>
                <div style="font-size:11px; color:#047857; margin-top:2px;">
                    Ganancia del negocio post-comisión
                </div>
            </div>
        </div>

        <!-- TABLA DESGLOSADA POR MIEMBRO DEL EQUIPO -->
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; overflow-x:auto;">
            <table class="barbero-table">
                <thead>
                    <tr>
                        <th>Barbero / Miembro del Equipo</th>
                        <th style="text-align:center;">Personas Atendidas</th>
                        <th style="text-align:right;">Total Producido ($)</th>
                        <th style="text-align:center; width:130px;" class="no-print">% Comisión Barbero</th>
                        <th style="text-align:right; color:#b45309;">Comisión a Pagar ($)</th>
                        <th style="text-align:right; color:#065f46;">Margen Barbería ($)</th>
                        <th style="text-align:center;" class="print-only">Firma Recibido Conforme</th>
                    </tr>
                </thead>
                <tbody id="barberos_tbody">
                    <?php if (empty($barberos_equipo)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding:30px; color:#94a3b8;">
                                No hay barberos registrados en esta sucursal. Agrega miembros en la sección "Equipo".
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($barberos_equipo as $b_info):
                            $b_atendidas = $b_info['personas_atendidas'];
                            $b_facturado = $b_info['total_facturado'];
                            $b_comision = $b_facturado * ($pct_base / 100);
                            $b_margen   = max(0, $b_facturado - $b_comision);
                        ?>
                            <tr class="barbero-comision-row"
                                data-id="<?= $b_info['id'] ?>"
                                data-atendidas="<?= $b_atendidas ?>"
                                data-facturado="<?= $b_facturado ?>">
                                <td>
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <img src="<?= htmlspecialchars($b_info['foto_url']) ?>"
                                             style="width:36px; height:36px; border-radius:50%; object-fit:cover; border:1.5px solid #b49363;"
                                             alt="<?= htmlspecialchars($b_info['nombre']) ?>">
                                        <div>
                                            <strong style="color:#0f172a; font-size:13px; display:block;">
                                                <?= htmlspecialchars($b_info['nombre']) ?>
                                            </strong>
                                            <span style="font-size:11px; color:#64748b;">
                                                <?= $b_info['activo'] ? '<span style="color:#10b981;">● Activo</span>' : '<span style="color:#94a3b8;">● Inactivo</span>' ?>
                                                <?php if (!empty($b_info['servicios_detalle'])): ?>
                                                    · <?= count($b_info['servicios_detalle']) ?> tipos de servicio
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>

                                <td style="text-align:center;">
                                    <span style="display:inline-block; font-size:14px; font-weight:800; color:#0f172a; background:#f1f5f9; padding:4px 12px; border-radius:20px;">
                                        <?= $b_atendidas ?>
                                    </span>
                                    <div style="font-size:10px; color:#94a3b8; margin-top:2px;">clientes</div>
                                </td>

                                <td style="text-align:right;">
                                    <strong style="font-size:14px; color:#0f172a;">
                                        $<?= number_format($b_facturado, 2) ?>
                                    </strong>
                                    <div style="font-size:10px; color:#94a3b8;">
                                        <?= $b_atendidas > 0 ? '$' . number_format($b_facturado / $b_atendidas, 2) . ' / pers.' : '$0.00' ?>
                                    </div>
                                </td>

                                <td style="text-align:center;" class="no-print">
                                    <div style="display:inline-flex; align-items:center; position:relative; width:80px;">
                                        <input type="number" min="0" max="100" step="1"
                                               class="barbero-pct-input"
                                               value="<?= htmlspecialchars($pct_base) ?>"
                                               oninput="onBarberoPctChange(this)"
                                               style="width:100%; height:30px; border:1px solid #cbd5e1; border-radius:6px; padding:0 18px 0 8px; font-size:12px; font-weight:700; text-align:right;">
                                        <span style="position:absolute; right:6px; font-size:11px; font-weight:700; color:#64748b;">%</span>
                                    </div>
                                </td>

                                <td style="text-align:right;">
                                    <span class="barbero-comision-valor" style="font-size:15px; font-weight:800; color:#b45309;">
                                        $<?= number_format($b_comision, 2) ?>
                                    </span>
                                    <div class="barbero-modo-label" style="font-size:10px; color:#92400e;">
                                        <?= $pct_base ?>%
                                    </div>
                                </td>

                                <td style="text-align:right;">
                                    <span class="barbero-margen-valor" style="font-size:14px; font-weight:700; color:#065f46;">
                                        $<?= number_format($b_margen, 2) ?>
                                    </span>
                                </td>

                                <td style="text-align:center; min-width:140px;" class="print-only">
                                    <div style="border-bottom:1px solid #000; height:35px; width:120px; margin:0 auto;"></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- FIRMAS DE LIQUIDACIÓN PARA IMPRESIÓN -->
        <div class="print-signatures">
            <div style="text-align:center; width:220px;">
                <div style="border-top:1px solid #000; padding-top:6px; font-size:12px; font-weight:700;">
                    Administración / Propietario
                </div>
                <div style="font-size:10px; color:#555;">Firma y Sello</div>
            </div>

            <div style="text-align:center; width:220px;">
                <div style="border-top:1px solid #000; padding-top:6px; font-size:12px; font-weight:700;">
                    Conformidad de Liquidación
                </div>
                <div style="font-size:10px; color:#555;">Fecha: <?= date('d/m/Y') ?></div>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <!-- ESPACIO INFORMATIVO PARA PLAN BÁSICO (LOCK/UPGRADE BANNER) -->
    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <div class="card no-print" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color:#fff; border-radius:14px; padding:28px; border:1px solid #334155; margin-top:30px; margin-bottom:30px; box-shadow:0 4px 20px rgba(15,23,42,0.15);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:20px;">
            <div style="max-width:640px;">
                <div style="display:inline-flex; align-items:center; gap:8px; background:rgba(180,147,99,0.18); border:1px solid rgba(180,147,99,0.4); color:#e2c599; padding:4px 12px; border-radius:20px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:12px;">
                    <i class="fas fa-lock"></i> Exclusivo para Planes Profesional y Pro
                </div>
                <h3 style="font-size:22px; font-weight:800; margin-bottom:10px; color:#ffffff;">
                    Liquidación y Control de Comisiones del Equipo
                </h3>
                <p style="font-size:13.5px; color:#cbd5e1; line-height:1.6; margin-bottom:18px;">
                    Tu negocio actualmente utiliza el <strong>Plan <?= htmlspecialchars($plan_activo['nombre'] ?? 'Básico') ?></strong>, ideal para barberos independientes.
                    Al dar el paso a los planes <strong>Profesional</strong> o <strong>Pro</strong> podrás calcular automáticamente las comisiones de todo tu equipo de trabajo según las personas atendidas y la facturación generada por cada miembro.
                </p>

                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:10px; margin-bottom:22px;">
                    <div style="font-size:12px; color:#f1f5f9; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-check-circle" style="color:#b49363;"></i> Cálculo automático por personas atendidas
                    </div>
                    <div style="font-size:12px; color:#f1f5f9; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-check-circle" style="color:#b49363;"></i> Porcentajes de comisión ajustables por barbero
                    </div>
                    <div style="font-size:12px; color:#f1f5f9; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-check-circle" style="color:#b49363;"></i> Margen neto automático para el negocio
                    </div>
                    <div style="font-size:12px; color:#f1f5f9; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-check-circle" style="color:#b49363;"></i> Reportes de pago y recibos imprimibles
                    </div>
                </div>

                <?php
                $wa_upgrade_msg = urlencode("Hola! Deseo actualizar mi suscripción de " . ($nombre_sucursal ?: 'mi barbería') . " a un Plan con Control de Comisiones de Equipo.");
                $wa_upgrade_url = "https://wa.me/584120000000?text=" . $wa_upgrade_msg;
                ?>
                <a href="<?= $wa_upgrade_url ?>" target="_blank"
                   style="background:#b49363; color:#fff; text-decoration:none; padding:11px 22px; border-radius:8px; font-weight:700; font-size:13px; display:inline-flex; align-items:center; gap:8px; box-shadow:0 4px 14px rgba(180,147,99,0.35);">
                    <i class="fab fa-whatsapp"></i> Consultar y Actualizar a Plan Profesional o Pro
                </a>
            </div>

            <div style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.12); border-radius:12px; padding:20px; min-width:220px; text-align:center;">
                <div style="font-size:11px; text-transform:uppercase; color:#94a3b8; font-weight:700; margin-bottom:6px;">Planes Recomendados</div>
                <div style="font-size:24px; font-weight:800; color:#b49363; margin-bottom:4px;">Profesional & Pro</div>
                <div style="font-size:12px; color:#cbd5e1; margin-bottom:14px;">Desde $30/mes</div>
                <div style="font-size:11px; color:#94a3b8; border-top:1px solid rgba(255,255,255,0.1); padding-top:12px; line-height:1.5;">
                    Permite hasta barberos ilimitados, Club VIP de puntos, y la suite completa de comisiones.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- SCRIPT DE INTERACTIVIDAD (CHART.JS Y CALCULADORA EN VIVO) -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Inicialización de Chart.js
    const ctx = document.getElementById('chartEvolucion');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [
                    {
                        label: 'Personas Atendidas',
                        data: <?= json_encode($chart_atendidas) ?>,
                        borderColor: '#b49363',
                        backgroundColor: 'rgba(180, 147, 99, 0.1)',
                        borderWidth: 2,
                        tension: 0.35,
                        fill: true,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Facturación ($)',
                        data: <?= json_encode($chart_ingresos) ?>,
                        borderColor: '#0f172a',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        borderDash: [4, 4],
                        tension: 0.35,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                if (context.datasetIndex === 0) {
                                    return 'Personas atendidas: ' + context.parsed.y;
                                } else {
                                    return 'Facturación: $' + context.parsed.y.toFixed(2);
                                }
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10 } }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        beginAtZero: true,
                        ticks: { stepSize: 1, font: { size: 10 } }
                    },
                    y1: {
                        type: 'linear',
                        display: false,
                        position: 'right',
                        beginAtZero: true,
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
    }

    // Inicializar cálculo inicial de comisiones si la sección existe
    if (document.getElementById('seccion-comisiones')) {
        recalcularComisiones();
    }
});

// Función para cambiar preset de porcentaje
function setPct(val) {
    const input = document.getElementById('pct_comision_input');
    if (input) {
        input.value = val;
        actualizarPctBase(val);
    }
    document.querySelectorAll('.pct-preset-btn').forEach(b => {
        if (parseInt(b.textContent) === parseInt(val)) {
            b.classList.add('active');
        } else {
            b.classList.remove('active');
        }
    });
}

// Cuando cambia el porcentaje global
function actualizarPctBase(val) {
    const num = parseFloat(val) || 0;
    // Actualizar todos los inputs individuales que no hayan sido fijados manualmente
    document.querySelectorAll('.barbero-pct-input').forEach(input => {
        if (!input.dataset.manualOverride) {
            input.value = num;
        }
    });
    recalcularComisiones();
}

// Cuando un barbero tiene un porcentaje personalizado
function onBarberoPctChange(input) {
    input.dataset.manualOverride = '1';
    recalcularComisiones();
}

// Cambiar modo de cálculo (% o fijo por persona)
function toggleModoComision(modo) {
    const wrapPct = document.getElementById('wrap_porcentaje_input');
    const wrapFijo = document.getElementById('wrap_monto_fijo_input');
    if (modo === 'porcentaje') {
        if (wrapPct) wrapPct.style.display = 'block';
        if (wrapFijo) wrapFijo.style.display = 'none';
    } else {
        if (wrapPct) wrapPct.style.display = 'none';
        if (wrapFijo) wrapFijo.style.display = 'block';
    }
    recalcularComisiones();
}

// CÁLCULO PRINCIPAL DE COMISIONES EN TIEMPO REAL
function recalcularComisiones() {
    const modo = document.querySelector('input[name="modo_calc"]:checked')?.value || 'porcentaje';
    const pctBase = parseFloat(document.getElementById('pct_comision_input')?.value) || 0;
    const montoFijo = parseFloat(document.getElementById('tarifa_persona_input')?.value) || 0;

    let totalPersonas = 0;
    let totalFacturado = 0;
    let totalComision = 0;

    const rows = document.querySelectorAll('.barbero-comision-row');
    rows.forEach(row => {
        const atendidas = parseInt(row.dataset.atendidas) || 0;
        const facturado = parseFloat(row.dataset.facturado) || 0;
        const pctInput = row.querySelector('.barbero-pct-input');

        let pctBarbero = pctBase;
        if (pctInput) {
            pctBarbero = parseFloat(pctInput.value) || 0;
        }

        let comision = 0;
        const modoLabel = row.querySelector('.barbero-modo-label');

        if (modo === 'porcentaje') {
            comision = facturado * (pctBarbero / 100);
            if (modoLabel) modoLabel.textContent = pctBarbero + '%';
        } else {
            comision = atendidas * montoFijo;
            if (modoLabel) modoLabel.textContent = '$' + montoFijo.toFixed(2) + ' / pers.';
        }

        const margen = Math.max(0, facturado - comision);

        // Actualizar valores en la fila
        const elCom = row.querySelector('.barbero-comision-valor');
        if (elCom) {
            elCom.textContent = '$' + comision.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        const elMargen = row.querySelector('.barbero-margen-valor');
        if (elMargen) {
            elMargen.textContent = '$' + margen.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        totalPersonas += atendidas;
        totalFacturado += facturado;
        totalComision += comision;
    });

    const margenNegocio = Math.max(0, totalFacturado - totalComision);

    // Actualizar tarjetas de resumen
    const elTotPers = document.getElementById('resumen_total_personas');
    if (elTotPers) elTotPers.textContent = totalPersonas.toLocaleString();

    const elTotFact = document.getElementById('resumen_total_facturado');
    if (elTotFact) elTotFact.textContent = '$' + totalFacturado.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    const elTotCom = document.getElementById('resumen_total_comisiones');
    if (elTotCom) elTotCom.textContent = '$' + totalComision.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    const elMargenNeg = document.getElementById('resumen_margen_barberia');
    if (elMargenNeg) elMargenNeg.textContent = '$' + margenNegocio.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    const elPctEf = document.getElementById('resumen_pct_efectivo');
    if (elPctEf) {
        if (modo === 'porcentaje') {
            elPctEf.textContent = pctBase + '% del total facturado';
        } else {
            elPctEf.textContent = '$' + montoFijo.toFixed(2) + ' por persona atendida';
        }
    }
}

// Impresión de liquidación
function imprimirLiquidacion() {
    window.print();
}
</script>
