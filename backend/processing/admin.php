<?php
if (defined('ALCORTE_ADMIN_PROCESSING_LOADED')) {
    return;
}
define('ALCORTE_ADMIN_PROCESSING_LOADED', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../api/helpers.php';

$allowed_roles = ['admin', 'superadmin', 'gerente'];
if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], $allowed_roles)) {
    if (defined('ALCORTE_API_REQUEST')) {
        api_err('No autorizado', 401);
    }
    header("Location: ../../");
    exit;
}

$scope_id = api_scope_sucursal_id();
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

// --- CRUD: CLIENTES ---
if ($action == 'add_cliente') {
    if (!csrf_validate()) {
        api_admin_finish('Error de seguridad', 'clientes');
    }
    csrf_regenerate();

    $nombre = trim($_POST['nombre']);
    $telefono = trim($_POST['telefono']);
    $puntos = intval($_POST['puntos']);

    $stmt = $conn->prepare("INSERT INTO clientes (telefono, nombre, puntos, ultima_visita) VALUES (?, ?, ?, CURDATE()) ON DUPLICATE KEY UPDATE nombre=?, puntos=?");
    $stmt->bind_param("ssisi", $telefono, $nombre, $puntos, $nombre, $puntos);
    $stmt->execute();
    $stmt->close();
    api_admin_finish('Cliente guardado correctamente', 'clientes');
}

if ($action == 'delete_cliente') {
    if (!csrf_validate()) {
        api_admin_finish('Error de seguridad', 'clientes');
    }
    csrf_regenerate();

    $telefono = trim((string) ($_REQUEST['id'] ?? $_POST['id'] ?? $_GET['id'] ?? ''));
    if ($telefono === '') {
        api_admin_finish('Cliente no válido', 'clientes');
    }
    $stmt = $conn->prepare("DELETE FROM clientes WHERE telefono = ?");
    $stmt->bind_param("s", $telefono);
    $stmt->execute();
    $stmt->close();
    api_admin_finish('Cliente eliminado de la base de datos', 'clientes');
}

// --- CRUD: PERSONAL / BARBEROS ---
if ($action == 'add_barbero') {
    if (!csrf_validate()) {
        api_admin_finish('Error de seguridad', 'personal');
    }
    csrf_regenerate();

    $nombre          = trim($_POST['nombre']);
    $hora_inicio     = $_POST['hora_inicio'];
    $hora_fin        = $_POST['hora_fin'];
    $almuerzo_inicio = $_POST['almuerzo_inicio'];
    $almuerzo_fin    = $_POST['almuerzo_fin'];
    $sucursal_id     = ($scope_id !== null) ? $scope_id : intval($_POST['sucursal_id'] ?? 0);
    if ($sucursal_id <= 1) {
        $row = $conn->query("SELECT MIN(id) AS id FROM sucursales WHERE id > 1")->fetch_assoc();
        $sucursal_id = intval($row['id'] ?? 0) ?: 1;
    }

    // Verificar límite de barberos según plan activo
    $plan = get_plan_sucursal($conn, $sucursal_id);
    if (!$plan) {
        $plan = [
            'nombre'       => 'Básico',
            'max_barberos' => 1
        ];
    }
    if ($plan['max_barberos'] > 0) {
        $cnt_stmt = $conn->prepare("SELECT COUNT(*) as total FROM barberos WHERE sucursal_id = ?");
        $cnt_stmt->bind_param("i", $sucursal_id);
        $cnt_stmt->execute();
        $total_barberos = $cnt_stmt->get_result()->fetch_assoc()['total'];
        $cnt_stmt->close();
        if ($total_barberos >= $plan['max_barberos']) {
            $msg = "Tu plan {$plan['nombre']} permite máximo {$plan['max_barberos']} barbero(s). Mejora tu plan para agregar más.";
            api_admin_finish($msg, 'personal');
        }
    }

    $barb_usuario    = trim($_POST['barb_usuario'] ?? '');
    $barb_password   = trim($_POST['barb_password'] ?? '');

    $uploaded = api_handle_upload('foto_file', 'barberos');
    $foto_url = $uploaded
        ?? (trim($_POST['foto_url'] ?? '') ?: 'https://ui-avatars.com/api/?background=333&color=fff&name=' . urlencode($nombre));

    $stmt = $conn->prepare("INSERT INTO barberos (nombre, foto_url, hora_inicio, hora_fin, almuerzo_inicio, almuerzo_fin, activo, sucursal_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
    $stmt->bind_param("ssssssi", $nombre, $foto_url, $hora_inicio, $hora_fin, $almuerzo_inicio, $almuerzo_fin, $sucursal_id);
    $stmt->execute();
    $nuevo_barbero_id = $stmt->insert_id;
    $stmt->close();

    // Crear usuario si se proporcionaron credenciales
    if ($barb_usuario !== '' && $barb_password !== '') {
        if (strlen($barb_password) < 8) {
            api_admin_finish('Barbero creado pero la contraseña debe tener mínimo 8 caracteres', 'personal');
        }
        $hash = password_hash($barb_password, PASSWORD_BCRYPT);
        $stmt2 = $conn->prepare("INSERT INTO usuarios (usuario, password, nombre, rol, sucursal_id, barbero_id) VALUES (?, ?, ?, 'barbero', ?, ?)");
        $stmt2->bind_param("sssii", $barb_usuario, $hash, $nombre, $sucursal_id, $nuevo_barbero_id);
        if (!$stmt2->execute()) {
            $stmt2->close();
            api_admin_finish('Barbero creado pero el usuario ya existe', 'personal');
        }
        $stmt2->close();
        api_admin_finish('Barbero y usuario creados exitosamente', 'personal');
    } else {
        api_admin_finish('Barbero registrado exitosamente', 'personal');
    }
}

if ($action == 'edit_barbero') {
    if (!csrf_validate()) {
        api_admin_finish('Error de seguridad', 'personal');
    }
    csrf_regenerate();

    $id = intval($_POST['id']);
    $nombre = trim($_POST['nombre']);
    $hora_inicio = $_POST['hora_inicio'];
    $hora_fin = $_POST['hora_fin'];
    $almuerzo_inicio = $_POST['almuerzo_inicio'];
    $almuerzo_fin = $_POST['almuerzo_fin'];
    $activo   = isset($_POST['activo']) ? 1 : 0;
    $uploaded = api_handle_upload('foto_file', 'barberos');
    $foto_url_new = $uploaded ?? (trim($_POST['foto_url'] ?? '') ?: null);

    if ($scope_id !== null) {
        if ($foto_url_new) {
            $stmt = $conn->prepare("UPDATE barberos SET nombre=?, hora_inicio=?, hora_fin=?, almuerzo_inicio=?, almuerzo_fin=?, activo=?, foto_url=? WHERE id=? AND sucursal_id=?");
            $stmt->bind_param("sssssisii", $nombre, $hora_inicio, $hora_fin, $almuerzo_inicio, $almuerzo_fin, $activo, $foto_url_new, $id, $scope_id);
        } else {
            $stmt = $conn->prepare("UPDATE barberos SET nombre=?, hora_inicio=?, hora_fin=?, almuerzo_inicio=?, almuerzo_fin=?, activo=? WHERE id=? AND sucursal_id=?");
            $stmt->bind_param("sssssiii", $nombre, $hora_inicio, $hora_fin, $almuerzo_inicio, $almuerzo_fin, $activo, $id, $scope_id);
        }
    } else {
        if ($foto_url_new) {
            $stmt = $conn->prepare("UPDATE barberos SET nombre=?, hora_inicio=?, hora_fin=?, almuerzo_inicio=?, almuerzo_fin=?, activo=?, foto_url=? WHERE id=?");
            $stmt->bind_param("sssssisi", $nombre, $hora_inicio, $hora_fin, $almuerzo_inicio, $almuerzo_fin, $activo, $foto_url_new, $id);
        } else {
            $stmt = $conn->prepare("UPDATE barberos SET nombre=?, hora_inicio=?, hora_fin=?, almuerzo_inicio=?, almuerzo_fin=?, activo=? WHERE id=?");
            $stmt->bind_param("sssssii", $nombre, $hora_inicio, $hora_fin, $almuerzo_inicio, $almuerzo_fin, $activo, $id);
        }
    }
    $stmt->execute();
    $stmt->close();
    api_admin_finish('Barbero actualizado', 'personal');
}

if ($action == 'delete_barbero') {
    if (!csrf_validate()) {
        api_admin_finish('Error de seguridad', 'personal');
    }
    csrf_regenerate();

    $id = intval($_POST['id']);

    // Eliminar usuario vinculado al barbero
    $stmt = $conn->prepare("DELETE FROM usuarios WHERE barbero_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // Eliminar el barbero
    if ($scope_id !== null) {
        $stmt = $conn->prepare("DELETE FROM barberos WHERE id = ? AND sucursal_id = ?");
        $stmt->bind_param("ii", $id, $scope_id);
    } else {
        $stmt = $conn->prepare("DELETE FROM barberos WHERE id = ?");
        $stmt->bind_param("i", $id);
    }
    $stmt->execute();
    $stmt->close();
    api_admin_finish('Barbero y usuario eliminados correctamente', 'personal');
}

// --- CRUD: SERVICIOS ---
if ($action == 'add_servicio') {
    if (!csrf_validate()) { api_admin_finish('Error de seguridad', 'servicios'); }
    csrf_regenerate();
    $nombre     = trim($_POST['svc_nombre'] ?? '');
    $duracion   = trim($_POST['svc_duracion'] ?? '30 min');
    $precio     = trim($_POST['svc_precio'] ?? '');
    $icono      = trim($_POST['svc_icono'] ?? 'fas fa-cut');
    $imagen_url  = api_upload_or_post_url('svc_imagen_file', 'servicios', 'svc_imagen');
    $descripcion = trim($_POST['svc_descripcion'] ?? '');
    $orden       = intval($_POST['svc_orden'] ?? 0);
    $suc_id      = ($scope_id !== null) ? $scope_id : 1;
    if ($nombre) {
        $stmt = $conn->prepare("INSERT INTO servicios (nombre, duracion, precio, icono, imagen_url, descripcion, orden, sucursal_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssii", $nombre, $duracion, $precio, $icono, $imagen_url, $descripcion, $orden, $suc_id);
        $stmt->execute(); $stmt->close();
    }
    api_admin_finish('Servicio agregado', 'servicios');
}

if ($action == 'edit_servicio') {
    if (!csrf_validate()) { api_admin_finish('Error de seguridad', 'servicios'); }
    csrf_regenerate();
    $id         = intval($_POST['svc_id']);
    $nombre     = trim($_POST['svc_nombre'] ?? '');
    $duracion   = trim($_POST['svc_duracion'] ?? '30 min');
    $precio     = trim($_POST['svc_precio'] ?? '');
    $icono      = trim($_POST['svc_icono'] ?? 'fas fa-cut');
    $imagen_url  = api_upload_or_post_url('svc_imagen_file', 'servicios', 'svc_imagen');
    $descripcion = trim($_POST['svc_descripcion'] ?? '');
    $activo      = isset($_POST['svc_activo']) ? 1 : 0;
    $orden       = intval($_POST['svc_orden'] ?? 0);
    $suc_id      = ($scope_id !== null) ? $scope_id : 1;
    $stmt = $conn->prepare("UPDATE servicios SET nombre=?, duracion=?, precio=?, icono=?, imagen_url=?, descripcion=?, activo=?, orden=? WHERE id=? AND sucursal_id=?");
    $stmt->bind_param("ssssssiiii", $nombre, $duracion, $precio, $icono, $imagen_url, $descripcion, $activo, $orden, $id, $suc_id);
    $stmt->execute(); $stmt->close();
    api_admin_finish('Servicio actualizado', 'servicios');
}

if ($action == 'delete_servicio') {
    if (!csrf_validate()) { api_admin_finish('Error de seguridad', 'servicios'); }
    csrf_regenerate();
    $id     = intval($_POST['svc_id']);
    $suc_id = ($scope_id !== null) ? $scope_id : 1;
    $stmt = $conn->prepare("DELETE FROM servicios WHERE id=? AND sucursal_id=?");
    $stmt->bind_param("ii", $id, $suc_id); $stmt->execute(); $stmt->close();
    api_admin_finish('Servicio eliminado', 'servicios');
}

// --- CRUD: BLOQUEOS DE HORARIO ---
if ($action == 'add_bloqueo') {
    if (!csrf_validate()) { api_admin_finish('Error de seguridad', 'personal'); }
    csrf_regenerate();
    $barbero_id    = intval($_POST['bloqueo_barbero_id']);
    $fecha         = trim($_POST['bloqueo_fecha']);
    $dia_completo  = isset($_POST['bloqueo_dia_completo']) ? 1 : 0;
    $hora_inicio   = $dia_completo ? null : (trim($_POST['bloqueo_hora_inicio'] ?? '') ?: null);
    $hora_fin      = $dia_completo ? null : (trim($_POST['bloqueo_hora_fin'] ?? '') ?: null);
    $motivo        = trim($_POST['bloqueo_motivo'] ?? '');
    $suc_id        = ($scope_id !== null) ? $scope_id : 1;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha < date('Y-m-d')) {
        api_admin_finish('Fecha inválida', 'personal');
    }

    $stmt = $conn->prepare("INSERT INTO bloqueos_horario (barbero_id, fecha, hora_inicio, hora_fin, dia_completo, motivo, sucursal_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssisi", $barbero_id, $fecha, $hora_inicio, $hora_fin, $dia_completo, $motivo, $suc_id);
    $stmt->execute(); $stmt->close();
    api_admin_finish('Bloqueo registrado', 'personal');
}

if ($action == 'delete_bloqueo') {
    if (!csrf_validate()) { api_admin_finish('Error de seguridad', 'personal'); }
    csrf_regenerate();
    $id     = intval($_POST['bloqueo_id']);
    $suc_id = ($scope_id !== null) ? $scope_id : 1;
    $stmt = $conn->prepare("DELETE FROM bloqueos_horario WHERE id = ? AND sucursal_id = ?");
    $stmt->bind_param("ii", $id, $suc_id); $stmt->execute(); $stmt->close();
    api_admin_finish('Bloqueo eliminado', 'personal');
}

// --- ACTUALIZAR CONFIGURACIÓN GENERAL Y MÉTODOS DE PAGO ---
if ($action == 'update_sys_settings') {
    if (!csrf_validate()) {
        api_admin_finish('Error de seguridad', 'ajustes');
    }
    csrf_regenerate();

    $conf_sucursal = ($scope_id !== null) ? $scope_id : 1;

    $estado_pago_movil = isset($_POST['estado_pago_movil']) ? '1' : '0';
    $estado_zelle = isset($_POST['estado_zelle']) ? '1' : '0';
    $estado_binance = isset($_POST['estado_binance']) ? '1' : '0';
    $estado_paypal = isset($_POST['estado_paypal']) ? '1' : '0';
    $estado_efectivo = isset($_POST['estado_efectivo']) ? '1' : '0';

    $banco_nombre = trim($_POST['banco_nombre'] ?? '');
    $banco_telefono = trim($_POST['banco_telefono'] ?? '');
    $banco_ci = trim($_POST['banco_ci'] ?? '');
    $zelle_email = trim($_POST['zelle_email'] ?? '');
    $binance_pay_id = trim($_POST['binance_pay_id'] ?? '');
    $paypal_email = trim($_POST['paypal_email'] ?? '');

    $uploaded_logo = api_handle_upload('logo_file', 'logos');
    $logo_url_input = trim($_POST['logo_url'] ?? '');
    $logo_url = $uploaded_logo ?? ($logo_url_input !== '' ? $logo_url_input : null);

    $payload = [
        'nombre_negocio'     => trim($_POST['nombre_negocio'] ?? ''),
        'estado_pago_movil'  => $estado_pago_movil,
        'estado_zelle'       => $estado_zelle,
        'estado_binance'     => $estado_binance,
        'estado_paypal'      => $estado_paypal,
        'estado_efectivo'    => $estado_efectivo,
        'banco_nombre'       => $banco_nombre,
        'banco_telefono'     => $banco_telefono,
        'banco_ci'           => $banco_ci,
        'zelle_email'        => $zelle_email,
        'binance_pay_id'     => $binance_pay_id,
        'paypal_email'       => $paypal_email,
        'direccion'          => trim($_POST['direccion'] ?? ''),
        'horario'            => trim($_POST['horario'] ?? ''),
        'contacto'           => trim($_POST['contacto'] ?? ''),
        'wa_plantilla'       => trim($_POST['wa_plantilla'] ?? ''),
        'politica_reserva'   => trim($_POST['politica_reserva'] ?? ''),
        'color_primario'     => preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color_primario'] ?? '') ? $_POST['color_primario'] : '#1a3461',
        'color_acento'       => preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color_acento']   ?? '') ? $_POST['color_acento']   : '#c49a4a',
    ];

    if ($logo_url !== null) {
        $payload['logo_url'] = $logo_url;
    }

    $stmt = $conn->prepare("INSERT INTO configuracion (clave, valor, sucursal_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE valor = ?");
    foreach ($payload as $clave => $valor) {
        $stmt->bind_param("ssss", $clave, $valor, $conf_sucursal, $valor);
        $stmt->execute();
    }
    $stmt->close();

    // Actualizar también el nombre de la sucursal en la tabla sucursales
    $nombre_negocio = trim($_POST['nombre_negocio'] ?? '');
    if ($nombre_negocio !== '') {
        $stmt_suc = $conn->prepare("UPDATE sucursales SET nombre = ? WHERE id = ?");
        $stmt_suc->bind_param("si", $nombre_negocio, $conf_sucursal);
        $stmt_suc->execute();
        $stmt_suc->close();
    }

    api_admin_finish('Configuración guardada correctamente', 'ajustes');
}

// --- CRUD: PRODUCTOS (Plan Pro) ---
if ($action == 'add_producto') {
    if (!csrf_validate()) { api_admin_finish('Error de seguridad', 'productos'); }
    csrf_regenerate();
    $nombre     = trim($_POST['prod_nombre'] ?? '');
    $descripcion = trim($_POST['prod_descripcion'] ?? '');
    $precio     = floatval($_POST['prod_precio'] ?? 0);
    $stock      = intval($_POST['prod_stock'] ?? 0);
    $imagen_url = api_upload_or_post_url('prod_imagen_file', 'productos', 'prod_imagen');
    $suc_id     = ($scope_id !== null) ? $scope_id : 1;
    if ($nombre) {
        $stmt = $conn->prepare("INSERT INTO productos (nombre, descripcion, precio, stock, imagen_url, sucursal_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssdisi", $nombre, $descripcion, $precio, $stock, $imagen_url, $suc_id);
        $stmt->execute(); $stmt->close();
    }
    api_admin_finish('Producto agregado', 'productos');
}

if ($action == 'edit_producto') {
    if (!csrf_validate()) { api_admin_finish('Error de seguridad', 'productos'); }
    csrf_regenerate();
    $id         = intval($_POST['prod_id']);
    $nombre     = trim($_POST['prod_nombre'] ?? '');
    $descripcion = trim($_POST['prod_descripcion'] ?? '');
    $precio     = floatval($_POST['prod_precio'] ?? 0);
    $stock      = intval($_POST['prod_stock'] ?? 0);
    $activo     = isset($_POST['prod_activo']) ? 1 : 0;
    $imagen_url = api_upload_or_post_url('prod_imagen_file', 'productos', 'prod_imagen');
    $suc_id     = ($scope_id !== null) ? $scope_id : 1;
    if ($imagen_url) {
        $stmt = $conn->prepare("UPDATE productos SET nombre=?, descripcion=?, precio=?, stock=?, imagen_url=?, activo=? WHERE id=? AND sucursal_id=?");
        $stmt->bind_param("ssdisiii", $nombre, $descripcion, $precio, $stock, $imagen_url, $activo, $id, $suc_id);
    } else {
        $stmt = $conn->prepare("UPDATE productos SET nombre=?, descripcion=?, precio=?, stock=?, activo=? WHERE id=? AND sucursal_id=?");
        $stmt->bind_param("ssdiiii", $nombre, $descripcion, $precio, $stock, $activo, $id, $suc_id);
    }
    $stmt->execute(); $stmt->close();
    api_admin_finish('Producto actualizado', 'productos');
}

if ($action == 'delete_producto') {
    if (!csrf_validate()) { api_admin_finish('Error de seguridad', 'productos'); }
    csrf_regenerate();
    $id     = intval($_POST['prod_id']);
    $suc_id = ($scope_id !== null) ? $scope_id : 1;
    $stmt = $conn->prepare("DELETE FROM productos WHERE id=? AND sucursal_id=?");
    $stmt->bind_param("ii", $id, $suc_id); $stmt->execute(); $stmt->close();
    api_admin_finish('Producto eliminado', 'productos');
}
if ($action == 'aprobar_pago_pedido') {
    if (!csrf_validate()) { api_admin_finish('Error de seguridad', 'pedidos'); }
    csrf_regenerate();
    $pedido_id = intval($_POST['pedido_id'] ?? 0);
    $suc_id = ($scope_id !== null) ? $scope_id : 1;
    $stmt = $conn->prepare("UPDATE pedidos SET estado_pago = 'verificado' WHERE id = ? AND sucursal_id = ?");
    $stmt->bind_param("ii", $pedido_id, $suc_id);
    $stmt->execute();
    $stmt->close();
    api_admin_finish('Pago aprobado', 'pedidos');
}

if ($action == 'completar_pedido') {
    if (!csrf_validate()) { api_admin_finish('Error de seguridad', 'pedidos'); }
    csrf_regenerate();
    $pedido_id = intval($_POST['pedido_id'] ?? 0);
    $suc_id = ($scope_id !== null) ? $scope_id : 1;
    $stmt = $conn->prepare("UPDATE pedidos SET estado = 'completado' WHERE id = ? AND sucursal_id = ?");
    $stmt->bind_param("ii", $pedido_id, $suc_id);
    $stmt->execute();
    $stmt->close();
    api_admin_finish('Pedido completado', 'pedidos');
}

if ($action == 'cancelar_pedido') {
    if (!csrf_validate()) { api_admin_finish('Error de seguridad', 'pedidos'); }
    csrf_regenerate();
    $pedido_id = intval($_POST['pedido_id'] ?? 0);
    $suc_id = ($scope_id !== null) ? $scope_id : 1;
    
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id = ? AND sucursal_id = ?");
        $stmt->bind_param("ii", $pedido_id, $suc_id);
        $stmt->execute();
        $stmt->close();
        
        $stmt_det = $conn->prepare("SELECT producto_id, cantidad FROM pedido_detalles WHERE pedido_id = ?");
        $stmt_det->bind_param("i", $pedido_id);
        $stmt_det->execute();
        $detalles = $stmt_det->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_det->close();
        
        $stmt_stk = $conn->prepare("UPDATE productos SET stock = stock + ? WHERE id = ?");
        foreach ($detalles as $det) {
            $stmt_stk->bind_param("ii", $det['cantidad'], $det['producto_id']);
            $stmt_stk->execute();
        }
        $stmt_stk->close();
        
        $conn->commit();
        api_admin_finish('Pedido cancelado', 'pedidos');
    } catch (\Exception $e) {
        $conn->rollback();
        api_admin_finish('Error al cancelar pedido', 'pedidos');
    }
}

// --- VERIFICAR PAGO DE CITA ---
if ($action === 'verificar_cita') {
    if (!csrf_validate()) {
        api_admin_finish('Error de seguridad', 'citas');
    }
    csrf_regenerate();

    $id_cita = (int) ($_POST['verificar_id'] ?? 0);
    if ($id_cita <= 0) {
        api_admin_finish('Cita no válida', 'citas');
    }

    if ($scope_id !== null) {
        $check = $conn->prepare('SELECT id FROM citas WHERE id = ? AND sucursal_id = ?');
        $check->bind_param('ii', $id_cita, $scope_id);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            $check->close();
            api_admin_finish('Acceso denegado', 'citas');
        }
        $check->close();
    }

    $stmt_up = $conn->prepare('UPDATE citas SET estado_pago = \'verificado\' WHERE id = ?');
    $stmt_up->bind_param('i', $id_cita);
    $stmt_up->execute();
    $stmt_up->close();

    $extra = [];
    $stmt_sel = $conn->prepare('SELECT cliente_telefono, cliente_nombre, servicio, fecha, hora FROM citas WHERE id = ?');
    $stmt_sel->bind_param('i', $id_cita);
    $stmt_sel->execute();
    $res_c = $stmt_sel->get_result();

    $cita_info = $res_c ? $res_c->fetch_assoc() : null;
    if ($cita_info) {
        $tel = $cita_info['cliente_telefono'];
        $nom = $cita_info['cliente_nombre'];

        $stmt_ins = $conn->prepare(
            'INSERT INTO clientes (telefono, nombre, puntos, ultima_visita) VALUES (?, ?, 1, CURDATE())
             ON DUPLICATE KEY UPDATE puntos = puntos + 1, ultima_visita = CURDATE()'
        );
        $stmt_ins->bind_param('ss', $tel, $nom);
        $stmt_ins->execute();
        $stmt_ins->close();

        $extra = [
            'wa_tel' => $tel,
            'wa_nom' => $nom,
            'wa_svc' => $cita_info['servicio'],
            'wa_fecha' => date('d/m/Y', strtotime($cita_info['fecha'])),
            'wa_hora' => date('h:i A', strtotime($cita_info['hora'])),
        ];
    }
    $stmt_sel->close();

    api_admin_finish('Pago verificado', 'citas', $extra);
}

if (defined('ALCORTE_API_REQUEST')) {
    api_err('Acción no reconocida', 400);
}

