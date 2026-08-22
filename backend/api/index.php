<?php
/**
 * Router API AlCorte Pro — /api/v1/{recurso}
 */
declare(strict_types=1);

define('ALCORTE_API_REQUEST', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/helpers.php';

$route = trim($_GET['route'] ?? '', '/');
if ($route === '' && isset($_SERVER['PATH_INFO'])) {
    $route = trim($_SERVER['PATH_INFO'], '/');
}
$parts = $route !== '' ? explode('/', $route) : [];
$resource = $parts[0] ?? '';
$sub = $parts[1] ?? '';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($resource) {
        case 'auth':
            require __DIR__ . '/handlers/auth.php';
            break;

        case 'admin':
            if ($method !== 'POST') {
                api_err('Método no permitido', 405);
            }
            require_once __DIR__ . '/../processing/admin.php';
            api_err('Acción no reconocida', 400);
            break;

        case 'superadmin':
            if ($method !== 'POST') {
                api_err('Método no permitido', 405);
            }
            require_once __DIR__ . '/../processing/superadmin.php';
            api_err('Acción no reconocida', 400);
            break;

        case 'usuarios':
            if ($method !== 'POST') {
                api_err('Método no permitido', 405);
            }
            require_once __DIR__ . '/../processing/usuarios.php';
            api_err('Acción no reconocida', 400);
            break;

        case 'barbero':
            if ($method !== 'POST') {
                api_err('Método no permitido', 405);
            }
            require_once __DIR__ . '/../processing/barbero.php';
            api_err('Acción no reconocida', 400);
            break;

        case 'public':
            require __DIR__ . '/handlers/public.php';
            break;

        case 'club':
            require __DIR__ . '/handlers/club.php';
            break;

        case 'bloqueos':
            require __DIR__ . '/handlers/bloqueos.php';
            break;

        case 'mis_citas':
            require __DIR__ . '/handlers/mis_citas.php';
            break;

        case 'cancelar_cita':
            if ($method !== 'POST') {
                api_err('Método no permitido', 405);
            }
            require __DIR__ . '/handlers/cancelar_cita.php';
            break;

        case '':
            api_ok(['version' => '1.0', 'name' => 'AlCorte Pro API']);
            break;

        default:
            api_err('Recurso no encontrado', 404);
    }
} catch (Throwable $e) {
    error_log('API error: ' . $e->getMessage());
    api_err('Error interno del servidor', 500);
}
