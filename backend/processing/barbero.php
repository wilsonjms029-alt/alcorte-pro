<?php
require_once '../config/config.php';
require_once __DIR__ . '/../api/helpers.php';

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'barbero') {
    if (defined('ALCORTE_API_REQUEST')) {
        api_err('No autorizado', 401);
    }
    header('Location: ../../');
    exit;
}

$is_admin_viewing = isset($_SESSION['rol']) && in_array($_SESSION['rol'], ['admin', 'superadmin', 'gerente'], true);
$can_manage_citas = $is_admin_viewing;
$id_barbero = (int) ($_SESSION['barbero_id'] ?? 0);

$action = $_REQUEST['action'] ?? 'update_estado';

if ($action === 'update_estado' && isset($_POST['cita_id'], $_POST['nuevo_estado'])) {
    if (!$can_manage_citas) {
        api_barbero_finish('Acción no permitida');
    }
    if (!csrf_validate()) {
        api_barbero_finish('Error de seguridad');
    }
    csrf_regenerate();

    $cita_id = (int) $_POST['cita_id'];
    $nuevo_estado = $_POST['nuevo_estado'];

    if (in_array($nuevo_estado, ['completada', 'cancelada'], true)) {
        $up = $conn->prepare('UPDATE citas SET estado = ? WHERE id = ? AND barbero_id = ?');
        $up->bind_param('sii', $nuevo_estado, $cita_id, $id_barbero);
        $up->execute();
        $up->close();
        $txt = $nuevo_estado === 'completada' ? 'Cita marcada como completada' : 'Cita cancelada';
        api_barbero_finish($txt);
    }
    api_barbero_finish('Estado no válido');
}

if (defined('ALCORTE_API_REQUEST')) {
    api_err('Acción no reconocida', 400);
}

header('Location: ../../frontend/barbero.php');
exit;
