<?php
/**
 * Configuración central de AlCorte Pro
 * - Conexión a base de datos
 * - Manejo de errores seguro (no se filtran rutas ni SQL al navegador)
 * - Sesión y utilidades CSRF
 */

// ─────────── Timezone ───────────
date_default_timezone_set('America/Caracas');

// ─────────── Manejo de errores (producción-segura) ───────────
error_reporting(E_ALL);
ini_set('display_errors', '0');   // nunca mostrar errores crudos al usuario
ini_set('log_errors', '1');

// ─────────── Sesión ───────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
ini_set('error_log', $logDir . '/php-error.log');

/** Página de error amigable para fallos no controlados. */
function alcorte_friendly_error(): void
{
    if (!headers_sent()) {
        http_response_code(500);
    }
    // Si la petición espera JSON (API), responder JSON.
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isJson = stripos($accept, 'application/json') !== false
        || stripos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
    if ($isJson) {
        echo json_encode(['error' => true, 'mensaje' => 'Ocurrió un error en el servidor.']);
        return;
    }
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>Error — AlCorte Pro</title>'
        . '<style>body{font-family:Inter,system-ui,-apple-system,sans-serif;background:#f9fafb;color:#111827;'
        . 'display:flex;min-height:100vh;margin:0;align-items:center;justify-content:center}'
        . '.box{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:40px;max-width:420px;'
        . 'text-align:center;box-shadow:0 4px 16px rgba(0,0,0,.06)}'
        . '.ic{font-size:42px;color:#b49363;margin-bottom:16px}'
        . 'h1{font-size:20px;margin:0 0 8px}p{color:#6b7280;font-size:14px;line-height:1.5;margin:0 0 20px}'
        . 'a{display:inline-block;background:#18181b;color:#fff;text-decoration:none;padding:10px 20px;'
        . 'border-radius:8px;font-size:14px;font-weight:600}</style></head>'
        . '<body><div class="box"><div class="ic">&#9986;</div>'
        . '<h1>Algo salió mal</h1>'
        . '<p>Tuvimos un inconveniente procesando tu solicitud. Por favor, intenta de nuevo en un momento.</p>'
        . '<a href="javascript:history.back()">← Volver</a></div></body></html>';
}

// Excepciones no capturadas → log + página amigable
set_exception_handler(function (\Throwable $e) {
    error_log('Excepción no capturada: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    alcorte_friendly_error();
});

// Errores fatales (parse/E_ERROR) → página amigable
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            alcorte_friendly_error();
        }
    }
});

// ─────────── Conexión a base de datos ───────────
$host = "127.0.0.1";
$user = "root";
$pass = "";
$db   = "barberia_db";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // excepciones en errores SQL

try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '-04:00'");
} catch (\Throwable $e) {
    error_log('Error de conexión BD: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    die("<div style='background:#fee2e2;color:#991b1b;padding:20px;font-family:sans-serif;border-radius:8px;margin:20px'>
        <strong>Servicio no disponible.</strong> No se pudo conectar con la base de datos.
        <br><small>Verifica que MySQL esté corriendo en XAMPP y que exista la base de datos <b>barberia_db</b>.</small>
    </div>");
}

// ─────────── Migraciones automáticas ───────────
try {
    mysqli_report(MYSQLI_REPORT_OFF);

    $r = $conn->query("SHOW COLUMNS FROM sucursales LIKE 'token'");
    if ($r && $r->num_rows === 0) {
        $conn->query("ALTER TABLE sucursales ADD COLUMN token VARCHAR(64) UNIQUE AFTER direccion");
    }
    $rows = $conn->query("SELECT id FROM sucursales WHERE token IS NULL OR token = ''");
    if ($rows) {
        while ($row = $rows->fetch_assoc()) {
            $tok = bin2hex(random_bytes(16));
            $st  = $conn->prepare("UPDATE sucursales SET token = ? WHERE id = ?");
            $st->bind_param("si", $tok, $row['id']);
            $st->execute();
            $st->close();
        }
    }
    $r2 = $conn->query("SHOW COLUMNS FROM servicios LIKE 'imagen_url'");
    if ($r2 && $r2->num_rows === 0) {
        $conn->query("ALTER TABLE servicios ADD COLUMN imagen_url VARCHAR(500) DEFAULT NULL AFTER icono");
    }

    // Migrar tabla planes: reemplazar max_citas_mes por nivel
    $r3 = $conn->query("SHOW COLUMNS FROM planes LIKE 'nivel'");
    if ($r3 && $r3->num_rows === 0) {
        $conn->query("ALTER TABLE planes ADD COLUMN nivel TINYINT NOT NULL DEFAULT 1 AFTER max_barberos");
        // Asignar nivel según max_barberos existente
        $conn->query("UPDATE planes SET nivel = CASE WHEN max_barberos <= 1 THEN 1 WHEN max_barberos <= 5 THEN 2 ELSE 3 END");
    }
    // Eliminar columna max_citas_mes si aún existe
    $r4 = $conn->query("SHOW COLUMNS FROM planes LIKE 'max_citas_mes'");
    if ($r4 && $r4->num_rows > 0) {
        $conn->query("ALTER TABLE planes DROP COLUMN max_citas_mes");
    }
    // Actualizar planes por defecto a los nuevos valores
    $conn->query("UPDATE planes SET nombre='Básico', precio_mensual=10.00, max_barberos=1, nivel=1, descripcion='Para barberos independientes' WHERE id=1 AND precio_mensual=29.99");
    $conn->query("UPDATE planes SET nombre='Profesional', precio_mensual=30.00, max_barberos=5, nivel=2, descripcion='Para barberías con equipo de trabajo' WHERE id=2 AND precio_mensual=59.99");
    $conn->query("UPDATE planes SET nombre='Pro', precio_mensual=70.00, max_barberos=0, nivel=3, descripcion='Para grandes barberías sin límites de personal' WHERE id=3 AND precio_mensual=99.99");

    // Columna estado en citas
    $r_estado = $conn->query("SHOW COLUMNS FROM citas LIKE 'estado'");
    if ($r_estado && $r_estado->num_rows === 0) {
        $conn->query("ALTER TABLE citas ADD COLUMN estado ENUM('programada','completada','cancelada') NOT NULL DEFAULT 'programada' AFTER estado_pago");
    }

    // Tabla bloqueos_horario
    $conn->query("CREATE TABLE IF NOT EXISTS bloqueos_horario (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Tabla productos (plan Pro)
    $conn->query("CREATE TABLE IF NOT EXISTS productos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL,
        descripcion VARCHAR(255) DEFAULT '',
        precio DECIMAL(10,2) NOT NULL DEFAULT 0,
        stock INT NOT NULL DEFAULT 0,
        imagen_url VARCHAR(500) DEFAULT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        sucursal_id INT NOT NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Tabla pedidos (plan Pro)
    $conn->query("CREATE TABLE IF NOT EXISTS pedidos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sucursal_id INT NOT NULL,
        cliente_nombre VARCHAR(100) NOT NULL,
        cliente_telefono VARCHAR(20) NOT NULL,
        metodo_pago VARCHAR(50) NOT NULL,
        referencia_pago VARCHAR(50) NOT NULL,
        estado_pago VARCHAR(20) DEFAULT 'pendiente',
        estado VARCHAR(20) DEFAULT 'pendiente',
        total DECIMAL(10,2) NOT NULL DEFAULT 0,
        fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Tabla pedido_detalles (plan Pro)
    $conn->query("CREATE TABLE IF NOT EXISTS pedido_detalles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pedido_id INT NOT NULL,
        producto_id INT NOT NULL,
        nombre_producto VARCHAR(150) NOT NULL,
        cantidad INT NOT NULL DEFAULT 1,
        precio_unitario DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
} catch (\Throwable $e) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    error_log('Migración: ' . $e->getMessage());
}


// ─────────── Utilidades CSRF ───────────
function csrf_generate(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_validate(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function csrf_regenerate(): void {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/** Ruta pública base del proyecto (ej. /tmp-evanys-mobile/alcorte-pro o vacío en vhost). */
function project_base_url(): string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $root = realpath(dirname(__DIR__, 2));
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($root && $docRoot && str_starts_with($root, $docRoot)) {
        $rel = str_replace('\\', '/', substr($root, strlen($docRoot)));
        $cached = ($rel === '' || $rel === '/') ? '' : rtrim($rel, '/');
    } else {
        $cached = '';
    }
    return $cached;
}

/** URL pública de un archivo en uploads/. */
function upload_public_url(string $subdir, string $filename): string {
    $base = project_base_url();
    $path = 'uploads/' . trim($subdir, '/') . '/' . $filename;
    return ($base ? $base . '/' : '/') . $path;
}

/** Normaliza el token público de tienda (32 hex). */
function store_token_normalize(string $raw): string {
    return preg_replace('/[^a-f0-9]/', '', strtolower($raw));
}

/** Resuelve sucursal_id desde token de tienda; null si inválido. */
function store_id_from_token(mysqli $conn, string $raw_token): ?int {
    $token = store_token_normalize($raw_token);
    if (strlen($token) !== 32) {
        return null;
    }
    $stmt = $conn->prepare("SELECT id FROM sucursales WHERE token = ? AND activo = 1 LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int) $row['id'] : null;
}

/** Exige sesión SuperAdmin (setup, seed, scripts de mantenimiento). */
function require_superadmin_web(): void {
    if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'superadmin') {
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>403</title></head>'
            . '<body style="font-family:sans-serif;padding:40px;color:#334155">'
            . '<h1>Acceso denegado</h1><p>Esta operación requiere SuperAdmin.</p>'
            . '<p><a href="../../frontend/superadmin.php">Ir al panel</a></p></body></html>';
        exit;
    }
}

// ─────────── Plan de sucursal ───────────
function get_plan_sucursal(mysqli $conn, int $sucursal_id): ?array {
    $stmt = $conn->prepare(
        "SELECT p.id, p.nombre, p.nivel, p.max_barberos, p.precio_mensual
         FROM suscripciones s
         JOIN planes p ON s.plan_id = p.id
         WHERE s.sucursal_id = ? AND s.estado = 'activo' AND s.fecha_vencimiento >= CURDATE()
         ORDER BY s.id DESC LIMIT 1"
    );
    $stmt->bind_param("i", $sucursal_id);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$plan) return null;
    $nivel = intval($plan['nivel']);
    return [
        'id'                 => intval($plan['id']),
        'nombre'             => $plan['nombre'],
        'nivel'              => $nivel,
        'max_barberos'       => intval($plan['max_barberos']),
        'precio_mensual'     => $plan['precio_mensual'],
        'has_club_vip'       => $nivel >= 2,
        'has_custom_colors'  => $nivel >= 2,
        'has_service_images' => $nivel >= 2,
        'has_productos'      => $nivel >= 3,
    ];
}
