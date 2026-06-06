<?php
/**
 * Configuración central de AlCorte Pro
 * - Conexión a base de datos
 * - Manejo de errores seguro (no se filtran rutas ni SQL al navegador)
 * - Sesión y utilidades CSRF
 */

// ─────────── Manejo de errores (producción-segura) ───────────
error_reporting(E_ALL);
ini_set('display_errors', '0');   // nunca mostrar errores crudos al usuario
ini_set('log_errors', '1');

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

// ─────────── Sesión ───────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
