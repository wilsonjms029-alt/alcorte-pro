<?php
declare(strict_types=1);

$action = api_route_sub() ?: (api_input()['action'] ?? '');
$input = api_input();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === 'agendar' && $method === 'POST') {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower($input['t'] ?? $_GET['t'] ?? ''));
    $sucursal_id = store_id_from_token($conn, $token);
    if (!$sucursal_id) {
        api_err('Tienda no válida', 404);
    }

    api_require_csrf($input);

    $res_svc = $conn->prepare('SELECT nombre FROM servicios WHERE activo = 1 AND sucursal_id = ?');
    $res_svc->bind_param('i', $sucursal_id);
    $res_svc->execute();
    $servicios_validos = [];
    $r = $res_svc->get_result();
    while ($row = $r->fetch_assoc()) {
        $servicios_validos[] = $row['nombre'];
    }
    $res_svc->close();

    $nombre = trim($input['cliente_nombre'] ?? '');
    $telefono = trim($input['cliente_telefono'] ?? '');
    $barbero = (int) ($input['barbero_id'] ?? 0);
    $servicio = trim($input['servicio'] ?? '');
    $fecha = trim($input['fecha'] ?? '');
    $hora = trim($input['hora'] ?? '');
    $metodo = trim($input['metodo_pago'] ?? '');
    $referencia = trim($input['referencia_pago'] ?? '');
    $tel_digitos = preg_replace('/\D/', '', $telefono);

    if (!$nombre || !$telefono || !$fecha || !$hora || !$metodo || !$servicio || !$barbero) {
        api_err('Por favor, completa todos los campos requeridos.');
    }
    if (strlen($tel_digitos) < 7 || strlen($tel_digitos) > 15) {
        api_err('El número de teléfono no parece válido.');
    }
    if (!in_array($servicio, $servicios_validos, true)) {
        api_err('El servicio seleccionado no es válido.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha < date('Y-m-d')) {
        api_err('La fecha no puede ser anterior a hoy.');
    }

    $stmt_b = $conn->prepare(
        'SELECT nombre, hora_inicio, hora_fin, almuerzo_inicio, almuerzo_fin, sucursal_id FROM barberos WHERE id = ? AND activo = 1'
    );
    $stmt_b->bind_param('i', $barbero);
    $stmt_b->execute();
    $bdata = $stmt_b->get_result()->fetch_assoc();
    $stmt_b->close();

    if (!$bdata) {
        api_err('El especialista seleccionado no está disponible.');
    }
    if ((int) ($bdata['sucursal_id'] ?? 0) !== (int) $sucursal_id) {
        api_err('El especialista no pertenece a esta tienda.');
    }

    $t = strtotime($hora);
    $ini = strtotime($bdata['hora_inicio']);
    $fin = strtotime($bdata['hora_fin']);
    $almi = strtotime($bdata['almuerzo_inicio']);
    $almf = strtotime($bdata['almuerzo_fin']);
    $hoy = date('Y-m-d');

    if ($t === false || $t < $ini || $t >= $fin) {
        api_err(
            'Ese horario está fuera del turno de ' . $bdata['nombre'] . ' (' . date('h:i A', $ini) . ' – ' . date('h:i A', $fin) . ').'
        );
    }
    if ($t >= $almi && $t < $almf) {
        api_err($bdata['nombre'] . ' está en almuerzo a esa hora. Elige otro horario.');
    }
    if ($fecha === $hoy && $t < strtotime(date('H:i'))) {
        api_err('Esa hora ya pasó. Elige un horario futuro.');
    }

    $stmt_chk = $conn->prepare('SELECT id FROM citas WHERE barbero_id = ? AND fecha = ? AND hora = ? AND estado != \'cancelada\'');
    $stmt_chk->bind_param('iss', $barbero, $fecha, $hora);
    $stmt_chk->execute();
    if ($stmt_chk->get_result()->num_rows > 0) {
        $stmt_chk->close();
        api_err('Ese cupo ya fue reservado. Por favor elige otra hora.');
    }
    $stmt_chk->close();

    $estado_pago = ($metodo === 'Efectivo') ? 'verificado' : 'pendiente';
    $suc_cita = (int) ($bdata['sucursal_id'] ?? 1) ?: 1;
    $stmt = $conn->prepare(
        'INSERT INTO citas (barbero_id, cliente_nombre, cliente_telefono, servicio, fecha, hora, metodo_pago, referencia_pago, estado_pago, sucursal_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issssssssi', $barbero, $nombre, $tel_digitos, $servicio, $fecha, $hora, $metodo, $referencia, $estado_pago, $suc_cita);

    if (!$stmt->execute()) {
        $stmt->close();
        api_err('Ese cupo ya fue reservado. Por favor elige otra hora.');
    }
    $stmt->close();

    $fecha_fmt = date('d/m/Y', strtotime($fecha));
    $hora_fmt = date('h:i A', strtotime($hora));
    api_ok([
        'fecha' => $fecha_fmt,
        'hora' => $hora_fmt,
    ], "¡Turno solicitado! Te esperamos el {$fecha_fmt} a las {$hora_fmt}.");
}

if ($action === 'pedido' && $method === 'POST') {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower($input['t'] ?? $_GET['t'] ?? ''));
    $sucursal_id = store_id_from_token($conn, $token);
    if (!$sucursal_id) {
        api_err('Tienda no válida', 404);
    }

    $plan_activo = get_plan_sucursal($conn, $sucursal_id);
    if (!$plan_activo || !$plan_activo['has_productos']) {
        api_err('Esta tienda no tiene productos habilitados.', 403);
    }

    api_require_csrf($input);

    $nombre = trim($input['cliente_nombre'] ?? '');
    $telefono = trim($input['cliente_telefono'] ?? '');
    $metodo = trim($input['metodo_pago'] ?? '');
    $referencia = trim($input['referencia_pago'] ?? '');
    $cart_json = trim($input['cart_items'] ?? '');
    $tel_digitos = preg_replace('/\D/', '', $telefono);
    $cart_items = json_decode($cart_json, true);

    if (!$nombre || !$telefono || !$metodo || empty($cart_items)) {
        api_err('Por favor, completa todos los campos del pedido.');
    }
    if (strlen($tel_digitos) < 7 || strlen($tel_digitos) > 15) {
        api_err('El número de teléfono no parece válido.');
    }

    $total = 0;
    $items_to_save = [];

    foreach ($cart_items as $item) {
        $pid = (int) ($item['id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }

        $stmt_p = $conn->prepare('SELECT nombre, precio, stock FROM productos WHERE id = ? AND sucursal_id = ? AND activo = 1');
        $stmt_p->bind_param('ii', $pid, $sucursal_id);
        $stmt_p->execute();
        $pdata = $stmt_p->get_result()->fetch_assoc();
        $stmt_p->close();

        if (!$pdata || $pdata['stock'] < $qty) {
            api_err('Uno o más productos no cuentan con stock suficiente (' . ($pdata['nombre'] ?? 'Producto') . ').');
        }

        $precio_uni = (float) $pdata['precio'];
        $total += $precio_uni * $qty;
        $items_to_save[] = [
            'id' => $pid,
            'nombre' => $pdata['nombre'],
            'qty' => $qty,
            'precio' => $precio_uni,
        ];
    }

    if (count($items_to_save) === 0) {
        api_err('El pedido está vacío.');
    }

    $estado_pago = ($metodo === 'Efectivo') ? 'verificado' : 'pendiente';
    $conn->begin_transaction();

    try {
        $stmt_ped = $conn->prepare(
            'INSERT INTO pedidos (sucursal_id, cliente_nombre, cliente_telefono, metodo_pago, referencia_pago, estado_pago, total) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt_ped->bind_param('isssssd', $sucursal_id, $nombre, $tel_digitos, $metodo, $referencia, $estado_pago, $total);
        $stmt_ped->execute();
        $pedido_id = $stmt_ped->insert_id;
        $stmt_ped->close();

        $stmt_det = $conn->prepare(
            'INSERT INTO pedido_detalles (pedido_id, producto_id, nombre_producto, cantidad, precio_unitario) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt_stk = $conn->prepare('UPDATE productos SET stock = stock - ? WHERE id = ?');

        foreach ($items_to_save as $it) {
            $stmt_det->bind_param('iisid', $pedido_id, $it['id'], $it['nombre'], $it['qty'], $it['precio']);
            $stmt_det->execute();
            $stmt_stk->bind_param('ii', $it['qty'], $it['id']);
            $stmt_stk->execute();
        }
        $stmt_det->close();
        $stmt_stk->close();
        $conn->commit();

        api_ok(['total' => $total, 'pedido_id' => $pedido_id, 'clear_cart' => true],
            '¡Tu pedido ha sido registrado con éxito! Total a pagar: $' . number_format($total, 2));
    } catch (Throwable $e) {
        $conn->rollback();
        api_err('Ocurrió un error al procesar el pedido. Intenta de nuevo.', 500);
    }
}

api_err('Acción pública no válida', 400);
