<?php
require_once '../config/config.php';

$errores = [];
$ok = [];

$queries = [
    "planes" => "CREATE TABLE IF NOT EXISTS planes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        precio_mensual DECIMAL(10,2) NOT NULL DEFAULT 0,
        max_barberos INT NOT NULL DEFAULT 1,
        nivel TINYINT NOT NULL DEFAULT 1,
        descripcion TEXT,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "suscripciones" => "CREATE TABLE IF NOT EXISTS suscripciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sucursal_id INT NOT NULL,
        plan_id INT NOT NULL,
        fecha_inicio DATE NOT NULL,
        fecha_vencimiento DATE NOT NULL,
        estado ENUM('activo','vencido','suspendido') NOT NULL DEFAULT 'activo',
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE,
        FOREIGN KEY (plan_id) REFERENCES planes(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "servicios" => "CREATE TABLE IF NOT EXISTS servicios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        duracion VARCHAR(50) DEFAULT '30 min',
        precio VARCHAR(50) DEFAULT '',
        icono VARCHAR(80) DEFAULT 'fas fa-cut',
        imagen_url VARCHAR(500) DEFAULT NULL,
        descripcion VARCHAR(255) DEFAULT '',
        activo TINYINT(1) NOT NULL DEFAULT 1,
        sucursal_id INT NOT NULL DEFAULT 1,
        orden INT NOT NULL DEFAULT 0,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "bloqueos_horario" => "CREATE TABLE IF NOT EXISTS bloqueos_horario (
        id INT AUTO_INCREMENT PRIMARY KEY,
        barbero_id INT NOT NULL,
        fecha DATE NOT NULL,
        hora_inicio TIME DEFAULT NULL,
        hora_fin TIME DEFAULT NULL,
        dia_completo TINYINT(1) NOT NULL DEFAULT 0,
        motivo VARCHAR(200) DEFAULT '',
        sucursal_id INT NOT NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (barbero_id) REFERENCES barberos(id) ON DELETE CASCADE,
        INDEX idx_barbero_fecha (barbero_id, fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "pagos_suscripcion" => "CREATE TABLE IF NOT EXISTS pagos_suscripcion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sucursal_id INT NOT NULL,
        plan_id INT,
        monto DECIMAL(10,2) NOT NULL,
        fecha_pago DATE NOT NULL,
        metodo ENUM('Zelle','Efectivo','Transferencia','PayPal','Otro') NOT NULL DEFAULT 'Zelle',
        referencia VARCHAR(200),
        notas TEXT,
        registrado_por INT,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
        FOREIGN KEY (plan_id) REFERENCES planes(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

foreach ($queries as $tabla => $sql) {
    if ($conn->query($sql)) {
        $ok[] = $tabla;
    } else {
        $errores[] = "$tabla: " . $conn->error;
    }
}

// Migrar columna imagen_url en servicios si no existe
$cols = $conn->query("SHOW COLUMNS FROM servicios LIKE 'imagen_url'");
if ($cols && $cols->num_rows === 0) {
    $conn->query("ALTER TABLE servicios ADD COLUMN imagen_url VARCHAR(500) DEFAULT NULL AFTER icono");
}

// Migrar columna descripcion en servicios si no existe
$cols_desc = $conn->query("SHOW COLUMNS FROM servicios LIKE 'descripcion'");
if ($cols_desc && $cols_desc->num_rows === 0) {
    $conn->query("ALTER TABLE servicios ADD COLUMN descripcion VARCHAR(255) DEFAULT '' AFTER imagen_url");
}

// Migrar columna token en sucursales si no existe + generar tokens para filas existentes
$cols2 = $conn->query("SHOW COLUMNS FROM sucursales LIKE 'token'");
if ($cols2 && $cols2->num_rows === 0) {
    $conn->query("ALTER TABLE sucursales ADD COLUMN token VARCHAR(64) UNIQUE AFTER direccion");
    $rows = $conn->query("SELECT id FROM sucursales WHERE token IS NULL OR token = ''");
    if ($rows) while ($r = $rows->fetch_assoc()) {
        $tok = bin2hex(random_bytes(16));
        $st  = $conn->prepare("UPDATE sucursales SET token = ? WHERE id = ?");
        $st->bind_param("si", $tok, $r['id']);
        $st->execute(); $st->close();
    }
}

// Insertar planes por defecto si no existen
$count = $conn->query("SELECT COUNT(*) as c FROM planes")->fetch_assoc()['c'];
if ($count == 0) {
    $conn->query("INSERT INTO planes (nombre, precio_mensual, max_barberos, nivel, descripcion) VALUES
        ('Básico', 10.00, 1, 1, 'Para barberos independientes'),
        ('Profesional', 30.00, 5, 2, 'Para barberías con equipo de trabajo'),
        ('Pro', 70.00, 0, 3, 'Para grandes barberías sin límites de personal')
    ");
    $ok[] = "3 planes por defecto insertados";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Setup BD - AlCorte</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: Inter, sans-serif; background: #f9fafb; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .box { background: white; border-radius: 12px; padding: 32px; max-width: 500px; width: 90%; box-shadow: 0 4px 12px rgba(0,0,0,.08); }
        h2 { font-size: 20px; font-weight: 700; margin-bottom: 20px; color: #111827; }
        .ok { background: #ecfdf5; border: 1px solid #d1fae5; border-radius: 8px; padding: 12px 16px; color: #065f46; margin-bottom: 8px; font-size: 14px; }
        .err { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; color: #991b1b; margin-bottom: 8px; font-size: 14px; }
        .btn { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #b49363; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px; }
    </style>
</head>
<body>
    <div class="box">
        <h2>Setup de Base de Datos</h2>
        <?php foreach ($ok as $msg): ?>
            <div class="ok">✓ <?php echo htmlspecialchars($msg); ?></div>
        <?php endforeach; ?>
        <?php foreach ($errores as $err): ?>
            <div class="err">✗ <?php echo htmlspecialchars($err); ?></div>
        <?php endforeach; ?>
        <a href="../../frontend/superadmin.php" class="btn">← Ir al Panel SuperAdmin</a>
    </div>
</body>
</html>
