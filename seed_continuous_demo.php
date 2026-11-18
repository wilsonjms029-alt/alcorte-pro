<?php
require_once 'backend/config/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    echo "=== INICIANDO SIMULACIÓN DE 3 TIENDAS ACTIVAS ===\n\n";

    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    $conn->query("TRUNCATE TABLE citas");
    $conn->query("TRUNCATE TABLE barberos");
    $conn->query("TRUNCATE TABLE servicios");
    if ($conn->query("SHOW TABLES LIKE 'productos'")->num_rows > 0) $conn->query("TRUNCATE TABLE productos");
    if ($conn->query("SHOW TABLES LIKE 'pedidos'")->num_rows > 0) $conn->query("TRUNCATE TABLE pedidos");
    if ($conn->query("SHOW TABLES LIKE 'pedidos_items'")->num_rows > 0) $conn->query("TRUNCATE TABLE pedidos_items");
    $conn->query("TRUNCATE TABLE pagos_suscripcion");
    $conn->query("TRUNCATE TABLE suscripciones");
    $conn->query("TRUNCATE TABLE configuracion");
    $conn->query("DELETE FROM usuarios WHERE rol != 'superadmin'");
    $conn->query("DELETE FROM sucursales WHERE id NOT IN (1, 2, 3)");

    $super_pw = password_hash('admin1234', PASSWORD_BCRYPT);
    $conn->query("INSERT INTO usuarios (id, usuario, password, nombre, rol)
                  VALUES (1, 'admin', '$super_pw', 'Super Administrador', 'superadmin')
                  ON DUPLICATE KEY UPDATE password = '$super_pw'");

    // 1. SUCURSALES
    $sucursales_data = [
        1 => [
            'nombre' => 'Barbería Clásica Leo',
            'direccion' => 'Av. Francisco de Miranda, Edif. Torre Centro, Local 4, Chacao',
            'token' => md5('token_leo_basico_1'),
            'activo' => 1
        ],
        2 => [
            'nombre' => 'Aura Spa & Wellness',
            'direccion' => 'Calle Los Almendros, C.C. La Pradera, Nivel Spa 2, Las Mercedes',
            'token' => md5('token_auraspa_prof_2'),
            'activo' => 1
        ],
        3 => [
            'nombre' => 'King Barber Club',
            'direccion' => 'Av. Principal de El Rosal, Edif. Platinum, Planta Baja',
            'token' => md5('token_kingbarber_pro_3'),
            'activo' => 1
        ]
    ];

    foreach ($sucursales_data as $id => $s) {
        $stmt = $conn->prepare("INSERT INTO sucursales (id, nombre, direccion, token, activo) VALUES (?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE nombre=?, direccion=?, token=?, activo=?");
        $stmt->bind_param("isssisssi", $id, $s['nombre'], $s['direccion'], $s['token'], $s['activo'],
                                       $s['nombre'], $s['direccion'], $s['token'], $s['activo']);
        $stmt->execute();
        $stmt->close();
    }
    echo "✓ 3 Sucursales configuradas (2 Barberías y 1 Spa)\n";

    // 2. CONFIGURACIÓN
    $configs = [
        1 => [
            'nombre_negocio' => 'Barbería Clásica Leo',
            'telefono_contacto' => '0414-1112233',
            'direccion' => 'Av. Francisco de Miranda, Local 4, Chacao',
            'color_primario' => '#b49363',
            'estado_efectivo' => '1',
            'estado_pago_movil' => '1',
            'banco_nombre' => 'Banesco',
            'banco_telefono' => '04141112233',
            'banco_ci' => 'V-18765432',
            'estado_zelle' => '0',
        ],
        2 => [
            'nombre_negocio' => 'Aura Spa & Wellness',
            'telefono_contacto' => '0424-5556677',
            'direccion' => 'Calle Los Almendros, Las Mercedes',
            'color_primario' => '#0d9488',
            'color_secundario' => '#0f766e',
            'estado_efectivo' => '1',
            'estado_pago_movil' => '1',
            'banco_nombre' => 'Mercantil',
            'banco_telefono' => '04245556677',
            'banco_ci' => 'J-409876541',
            'estado_zelle' => '1',
            'zelle_email' => 'pagos@auraspa.com',
        ],
        3 => [
            'nombre_negocio' => 'King Barber Club',
            'telefono_contacto' => '0412-9998877',
            'direccion' => 'Av. Principal de El Rosal, Edif Platinum PB',
            'color_primario' => '#b49363',
            'color_secundario' => '#0f172a',
            'estado_efectivo' => '1',
            'estado_pago_movil' => '1',
            'banco_nombre' => 'Bancaribe',
            'banco_telefono' => '04129998877',
            'banco_ci' => 'J-501234567',
            'estado_zelle' => '1',
            'zelle_email' => 'kingbarber@vipzelle.com',
        ]
    ];

    foreach ($configs as $suc_id => $vals) {
        foreach ($vals as $k => $v) {
            $stmt = $conn->prepare("INSERT INTO configuracion (clave, valor, sucursal_id) VALUES (?, ?, ?)
                                    ON DUPLICATE KEY UPDATE valor = ?");
            $stmt->bind_param("ssis", $k, $v, $suc_id, $v);
            $stmt->execute();
            $stmt->close();
        }
    }
    echo "✓ Configuración de tiendas completada\n";

    // 3. SUSCRIPCIONES Y PAGOS
    $suscripciones_init = [
        1 => ['plan_id' => 1, 'monto' => 10.00, 'metodo' => 'Transferencia', 'ref' => 'PM-981245'],
        2 => ['plan_id' => 2, 'monto' => 30.00, 'metodo' => 'Zelle',         'ref' => 'ZEL-558912'],
        3 => ['plan_id' => 3, 'monto' => 70.00, 'metodo' => 'Zelle',         'ref' => 'ZEL-778841'],
    ];

    $hoy = date('Y-m-d');
    $vence = date('Y-m-d', strtotime('+30 days'));

    foreach ($suscripciones_init as $suc_id => $sub) {
        $stmt = $conn->prepare("INSERT INTO suscripciones (sucursal_id, plan_id, fecha_inicio, fecha_vencimiento, estado) VALUES (?, ?, ?, ?, 'activo')");
        $stmt->bind_param("iiss", $suc_id, $sub['plan_id'], $hoy, $vence);
        $stmt->execute();
        $stmt->close();

        $stmt_p = $conn->prepare("INSERT INTO pagos_suscripcion (sucursal_id, plan_id, monto, fecha_pago, metodo, referencia, notas) VALUES (?, ?, ?, ?, ?, ?, 'Pago de mensualidad verificado')");
        $stmt_p->bind_param("iidsss", $suc_id, $sub['plan_id'], $sub['monto'], $hoy, $sub['metodo'], $sub['ref']);
        $stmt_p->execute();
        $stmt_p->close();
    }
    echo "✓ Suscripciones y pagos registrados (Básico $10, Profesional $30, Pro $70)\n";

    // 4. USUARIOS
    $usuarios_tienda = [
        ['usuario' => 'leo',     'pass' => 'leo123',  'nombre' => 'Leonardo Silva (Barbería Leo)',  'rol' => 'admin', 'sucursal_id' => 1],
        ['usuario' => 'auraspa', 'pass' => 'spa123',  'nombre' => 'Valeria Gómez (Aura Spa)',       'rol' => 'admin', 'sucursal_id' => 2],
        ['usuario' => 'deivy',   'pass' => 'king123', 'nombre' => 'Deivy González (King Barber)',   'rol' => 'admin', 'sucursal_id' => 3],
    ];

    foreach ($usuarios_tienda as $u) {
        $pw = password_hash($u['pass'], PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO usuarios (usuario, password, nombre, rol, sucursal_id) VALUES (?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE password = ?, nombre = ?, rol = ?, sucursal_id = ?");
        $stmt->bind_param("ssssisssi", $u['usuario'], $pw, $u['nombre'], $u['rol'], $u['sucursal_id'],
                                       $pw, $u['nombre'], $u['rol'], $u['sucursal_id']);
        $stmt->execute();
        $stmt->close();
    }
    echo "✓ Usuarios administradores de tienda creados (leo/leo123, auraspa/spa123, deivy/king123)\n";

    // 5. EQUIPO / PERSONAL
    $personal_data = [
        // Tienda 1: Básico (1 barbero)
        ['id' => 1, 'nombre' => 'Leonardo Silva', 'foto' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=150', 'sucursal_id' => 1],

        // Tienda 2: Spa Profesional (3 terapeutas)
        ['id' => 2, 'nombre' => 'Valeria Gómez', 'foto' => 'https://images.unsplash.com/photo-1544005313-94ddf0286df2?w=150', 'sucursal_id' => 2],
        ['id' => 3, 'nombre' => 'Camila Rivas',  'foto' => 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=150', 'sucursal_id' => 2],
        ['id' => 4, 'nombre' => 'Diego Mendoza', 'foto' => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=150', 'sucursal_id' => 2],

        // Tienda 3: Gran Barbería Pro (5 barberos)
        ['id' => 5, 'nombre' => 'Carlos Reyes (Master)',   'foto' => 'https://images.unsplash.com/photo-1519085360753-af0119f7cbe7?w=150', 'sucursal_id' => 3],
        ['id' => 6, 'nombre' => 'Deivy González',          'foto' => 'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?w=150', 'sucursal_id' => 3],
        ['id' => 7, 'nombre' => 'Alexander Padrón (Fade)', 'foto' => 'https://images.unsplash.com/photo-1492562080023-ab3db95bfbce?w=150', 'sucursal_id' => 3],
        ['id' => 8, 'nombre' => 'Sebastián Mora',          'foto' => 'https://images.unsplash.com/photo-1522075469751-3a6694fb2f61?w=150', 'sucursal_id' => 3],
        ['id' => 9, 'nombre' => 'Andrés Castillo',         'foto' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150', 'sucursal_id' => 3],
    ];

    foreach ($personal_data as $p) {
        $stmt = $conn->prepare("INSERT INTO barberos (id, nombre, foto_url, hora_inicio, hora_fin, almuerzo_inicio, almuerzo_fin, activo, sucursal_id)
                                VALUES (?, ?, ?, '09:00:00', '19:00:00', '13:00:00', '14:00:00', 1, ?)");
        $stmt->bind_param("issi", $p['id'], $p['nombre'], $p['foto'], $p['sucursal_id']);
        $stmt->execute();
        $stmt->close();
    }
    echo "✓ Personal de equipo registrado\n";

    // 6. SERVICIOS
    $servicios_data = [
        // Tienda 1: Barbería Leo
        ['nombre' => 'Corte Clásico',              'precio' => '10.00', 'duracion' => '30 min', 'sucursal_id' => 1],
        ['nombre' => 'Afeitado Toalla Caliente',   'precio' => '8.00',  'duracion' => '25 min', 'sucursal_id' => 1],
        ['nombre' => 'Combo Corte y Barba',        'precio' => '15.00', 'duracion' => '50 min', 'sucursal_id' => 1],
        ['nombre' => 'Perfilado de Barba Express', 'precio' => '6.00',  'duracion' => '20 min', 'sucursal_id' => 1],

        // Tienda 2: Aura Spa & Wellness
        ['nombre' => 'Masaje Relajante Antiestrés 60m', 'precio' => '35.00', 'duracion' => '60 min', 'sucursal_id' => 2],
        ['nombre' => 'Limpieza Facial Profunda',        'precio' => '25.00', 'duracion' => '45 min', 'sucursal_id' => 2],
        ['nombre' => 'Terapia con Piedras Volcánicas',  'precio' => '45.00', 'duracion' => '75 min', 'sucursal_id' => 2],
        ['nombre' => 'Exfoliación Corporal Hidratante', 'precio' => '30.00', 'duracion' => '50 min', 'sucursal_id' => 2],
        ['nombre' => 'Drenaje Linfático Manual',        'precio' => '40.00', 'duracion' => '60 min', 'sucursal_id' => 2],

        // Tienda 3: King Barber Club
        ['nombre' => 'Fade & Degrade Master',       'precio' => '12.00', 'duracion' => '35 min', 'sucursal_id' => 3],
        ['nombre' => 'Perfilado y Pigmentación Barba','precio' => '10.00', 'duracion' => '25 min', 'sucursal_id' => 3],
        ['nombre' => 'Ritual VIP King AlCorte',     'precio' => '25.00', 'duracion' => '60 min', 'sucursal_id' => 3],
        ['nombre' => 'Black Mask + Vapor Ozono',    'precio' => '15.00', 'duracion' => '30 min', 'sucursal_id' => 3],
        ['nombre' => 'Corte Infantil Estilizado',   'precio' => '10.00', 'duracion' => '30 min', 'sucursal_id' => 3],
    ];

    foreach ($servicios_data as $s) {
        $stmt = $conn->prepare("INSERT INTO servicios (nombre, duracion, precio, activo, sucursal_id) VALUES (?, ?, ?, 1, ?)");
        $stmt->bind_param("sssi", $s['nombre'], $s['duracion'], $s['precio'], $s['sucursal_id']);
        $stmt->execute();
        $stmt->close();
    }
    echo "✓ Servicios configurados para las 3 tiendas\n";

    // 7. PRODUCTOS PARA PLAN PRO
    if ($conn->query("SHOW TABLES LIKE 'productos'")->num_rows > 0) {
        $productos_king = [
            ['nombre' => 'Pomada Fijación Mate King', 'precio' => 14.00, 'stock' => 28, 'sucursal_id' => 3],
            ['nombre' => 'Aceite Esencial Crecimiento Barba', 'precio' => 18.00, 'stock' => 15, 'sucursal_id' => 3],
            ['nombre' => 'Cera Texturizadora Voluminizadora', 'precio' => 12.00, 'stock' => 20, 'sucursal_id' => 3],
        ];
        foreach ($productos_king as $pr) {
            $stmt = $conn->prepare("INSERT INTO productos (nombre, precio, stock, activo, sucursal_id) VALUES (?, ?, ?, 1, ?)");
            $stmt->bind_param("sdii", $pr['nombre'], $pr['precio'], $pr['stock'], $pr['sucursal_id']);
            $stmt->execute();
            $stmt->close();
        }
        echo "✓ Productos configurados para King Barber (Plan Pro)\n";
    }

    // 8. CITAS
    $clientes_nombres = [
        'Marcos Pérez', 'Alejandro Colmenares', 'Gabriel Rodríguez', 'Fernando Castillo', 'Daniel Villegas',
        'Juan Carlos Mendoza', 'Enrique Gómez', 'Víctor Morales', 'Andrés Eloy Blanco', 'Miguel Ángel Torres',
        'Sofía Navarro', 'Mariana Albornoz', 'Elena Briceño', 'Valeria Hernández', 'Lucía Santander',
        'Carolina Fuentes', 'Daniela Salazar', 'Paola Márquez', 'Gabriela Benítez', 'Andrea Chacón',
        'Rodrigo Quintero', 'Javier Peña', 'Roberto Gil', 'Esteban Suárez', 'César Delgado'
    ];
    $metodos_lista = ['Efectivo', 'Pago Móvil', 'Pago Móvil', 'Zelle', 'Efectivo'];
    $horas_posibles = ['09:30:00', '10:15:00', '11:00:00', '11:45:00', '14:15:00', '15:00:00', '15:45:00', '16:30:00', '17:15:00', '18:00:00'];
    $dias_offset = [-20, -18, -15, -12, -10, -8, -6, -5, -4, -3, -2, -1, 0, 0, 0, 1];

    $total_citas_gen = 0;

    // A) Tienda 1: Barbería Leo (24 citas)
    $servicios_leo = ['Corte Clásico', 'Afeitado Toalla Caliente', 'Combo Corte y Barba', 'Perfilado de Barba Express'];
    for ($i = 0; $i < 24; $i++) {
        $cli = $clientes_nombres[$i % count($clientes_nombres)];
        $tel = '0414-' . sprintf("%07d", 1000000 + $i * 73);
        $svc = $servicios_leo[$i % count($servicios_leo)];
        $off = $dias_offset[$i % count($dias_offset)];
        $fecha = date('Y-m-d', strtotime("$off days"));
        $hora = $horas_posibles[$i % count($horas_posibles)];
        $metodo = $metodos_lista[$i % count($metodos_lista)];

        if ($off < 0) {
            $estado = ($i == 4) ? 'cancelada' : 'completada';
            $pago   = ($estado == 'cancelada') ? 'pendiente' : 'verificado';
        } elseif ($off == 0) {
            $estado = ($i % 2 == 0) ? 'completada' : 'programada';
            $pago   = ($estado == 'completada') ? 'verificado' : 'pendiente';
        } else {
            $estado = 'programada';
            $pago   = 'pendiente';
        }

        $stmt = $conn->prepare("INSERT INTO citas (barbero_id, cliente_nombre, cliente_telefono, servicio, fecha, hora, metodo_pago, estado_pago, estado, sucursal_id)
                                VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->bind_param("ssssssss", $cli, $tel, $svc, $fecha, $hora, $metodo, $pago, $estado);
        $stmt->execute();
        $stmt->close();
        $total_citas_gen++;
    }

    // B) Tienda 2: Aura Spa (48 citas)
    $terapeutas_spa = [2, 3, 4];
    $servicios_spa  = [
        'Masaje Relajante Antiestrés 60m', 'Limpieza Facial Profunda',
        'Terapia con Piedras Volcánicas', 'Exfoliación Corporal Hidratante', 'Drenaje Linfático Manual'
    ];
    for ($i = 0; $i < 48; $i++) {
        $cli = $clientes_nombres[($i + 5) % count($clientes_nombres)];
        $tel = '0424-' . sprintf("%07d", 2000000 + $i * 89);
        $svc = $servicios_spa[$i % count($servicios_spa)];
        $terapeuta_id = $terapeutas_spa[$i % count($terapeutas_spa)];
        $off = $dias_offset[$i % count($dias_offset)];
        $fecha = date('Y-m-d', strtotime("$off days"));
        $hora = $horas_posibles[($i + 2) % count($horas_posibles)];
        $metodo = $metodos_lista[($i + 1) % count($metodos_lista)];

        if ($off < 0) {
            $estado = ($i == 7 || $i == 23) ? 'cancelada' : 'completada';
            $pago   = ($estado == 'cancelada') ? 'pendiente' : 'verificado';
        } elseif ($off == 0) {
            $estado = ($i % 3 == 0) ? 'completada' : 'programada';
            $pago   = ($estado == 'completada') ? 'verificado' : 'pendiente';
        } else {
            $estado = 'programada';
            $pago   = 'pendiente';
        }

        $stmt = $conn->prepare("INSERT INTO citas (barbero_id, cliente_nombre, cliente_telefono, servicio, fecha, hora, metodo_pago, estado_pago, estado, sucursal_id)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 2)");
        $stmt->bind_param("issssssss", $terapeuta_id, $cli, $tel, $svc, $fecha, $hora, $metodo, $pago, $estado);
        $stmt->execute();
        $stmt->close();
        $total_citas_gen++;
    }

    // C) Tienda 3: King Barber (75 citas)
    $barberos_king = [5, 6, 7, 8, 9];
    $servicios_king = [
        'Fade & Degrade Master', 'Perfilado y Pigmentación Barba',
        'Ritual VIP King AlCorte', 'Black Mask + Vapor Ozono', 'Corte Infantil Estilizado'
    ];
    for ($i = 0; $i < 75; $i++) {
        $cli = $clientes_nombres[($i + 10) % count($clientes_nombres)];
        $tel = '0412-' . sprintf("%07d", 3000000 + $i * 61);
        $svc = $servicios_king[$i % count($servicios_king)];
        $barbero_id = $barberos_king[$i % count($barberos_king)];
        $off = $dias_offset[$i % count($dias_offset)];
        $fecha = date('Y-m-d', strtotime("$off days"));
        $hora = $horas_posibles[($i + 3) % count($horas_posibles)];
        $metodo = $metodos_lista[($i + 2) % count($metodos_lista)];

        if ($off < 0) {
            $estado = ($i == 11 || $i == 33 || $i == 54) ? 'cancelada' : 'completada';
            $pago   = ($estado == 'cancelada') ? 'pendiente' : 'verificado';
        } elseif ($off == 0) {
            $estado = ($i % 2 == 0) ? 'completada' : 'programada';
            $pago   = ($estado == 'completada') ? 'verificado' : 'pendiente';
        } else {
            $estado = 'programada';
            $pago   = 'pendiente';
        }

        $stmt = $conn->prepare("INSERT INTO citas (barbero_id, cliente_nombre, cliente_telefono, servicio, fecha, hora, metodo_pago, estado_pago, estado, sucursal_id)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 3)");
        $stmt->bind_param("issssssss", $barbero_id, $cli, $tel, $svc, $fecha, $hora, $metodo, $pago, $estado);
        $stmt->execute();
        $stmt->close();
        $total_citas_gen++;
    }

    $conn->query("SET FOREIGN_KEY_CHECKS = 1");

    echo "✓ $total_citas_gen Citas generadas exitosamente\n";
    echo "\n=== SIMULACIÓN COMPLETADA CON ÉXITO ===\n";

} catch (Throwable $e) {
    echo "ERROR DETALLADO: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine() . "\n";
}
