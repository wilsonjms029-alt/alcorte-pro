<?php
/**
 * Helpers HTTP para la API AlCorte Pro.
 */

function api_route_parts(): array
{
    $route = trim($_GET['route'] ?? '', '/');
    if ($route === '' && isset($_SERVER['PATH_INFO'])) {
        $route = trim((string) $_SERVER['PATH_INFO'], '/');
    }
    return $route !== '' ? explode('/', $route) : [];
}

function api_route_sub(): string
{
    return api_route_parts()[1] ?? '';
}

function api_json(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_ok(array $data = [], string $message = ''): void
{
    $out = ['ok' => true];
    if ($message !== '') {
        $out['message'] = $message;
    }
    if ($data !== []) {
        $out['data'] = $data;
    }
    api_json($out);
}

function api_err(string $message, int $status = 400, array $extra = []): void
{
    api_json(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}

function api_input(): array
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        return $_GET;
    }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    return array_merge($_GET, $_POST);
}

function api_require_csrf(array $input): void
{
    $token = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    $_POST['csrf_token'] = $token;
    if (!csrf_validate()) {
        api_err('Token de seguridad inválido. Recarga la página.', 403);
    }
    csrf_regenerate();
}

function api_scope_sucursal_id(): ?int
{
    $rol = $_SESSION['rol'] ?? '';
    if ($rol === 'superadmin') {
        $suc = (int) ($_SESSION['admin_suc'] ?? 0);
        return $suc > 0 ? $suc : null;
    }
    if (in_array($rol, ['admin', 'gerente'], true)) {
        return (int) ($_SESSION['sucursal_id'] ?? 0) ?: null;
    }
    return null;
}

function api_handle_upload(string $field, string $subdir): ?string
{
    if (empty($_FILES[$field]['tmp_name'])) {
        return null;
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        error_log("Upload {$field}: error code {$file['error']}");
        return null;
    }

    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) {
        return null;
    }

    $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $mime_ok = false;
    if (function_exists('mime_content_type')) {
        $mime_ok = in_array(mime_content_type($file['tmp_name']), $allowed_mime, true);
    }
    if (!$mime_ok && function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $mime_ok = in_array($detected, $allowed_mime, true);
        }
    }
    if (!$mime_ok) {
        $mime_ok = in_array($ext, $allowed_ext, true);
    }
    if (!$mime_ok) {
        return null;
    }

    $name = uniqid('img_', true) . '.' . $ext;
    $dir  = dirname(__DIR__, 2) . '/uploads/' . trim($subdir, '/') . '/';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        return null;
    }
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
        return null;
    }
    return upload_public_url($subdir, $name);
}

/** Redirección legacy (processing) o JSON (API). */
function api_admin_finish(string $msg, string $page = 'citas', array $extra = []): void
{
    if (defined('ALCORTE_API_REQUEST')) {
        api_ok(array_merge(['page' => $page], $extra), $msg);
    }
    $qs = 'page=' . rawurlencode($page) . '&msg=' . rawurlencode($msg);
    foreach ($extra as $k => $v) {
        $qs .= '&' . rawurlencode($k) . '=' . rawurlencode((string) $v);
    }
    header('Location: ../../frontend/admin.php?' . $qs);
    exit;
}

function api_superadmin_finish(string $msg, string $page = 'dashboard'): void
{
    if (defined('ALCORTE_API_REQUEST')) {
        api_ok(['page' => $page], $msg);
    }
    header('Location: ../../frontend/superadmin.php?page=' . rawurlencode($page) . '&msg=' . rawurlencode($msg));
    exit;
}

function api_usuarios_finish(string $msg, string $page = 'usuarios'): void
{
    if (defined('ALCORTE_API_REQUEST')) {
        api_ok(['page' => $page], $msg);
    }
    header('Location: ../../frontend/superadmin.php?page=' . rawurlencode($page) . '&msg=' . rawurlencode($msg));
    exit;
}

function api_require_roles(array $roles): void
{
    if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], $roles, true)) {
        api_err('No autorizado', 401);
    }
}

function api_barbero_finish(string $msg): void
{
    if (defined('ALCORTE_API_REQUEST')) {
        api_ok([], $msg);
    }
    header('Location: ../../frontend/barbero.php?msg=' . rawurlencode($msg));
    exit;
}

function api_base_path(): string
{
    return project_base_url();
}
