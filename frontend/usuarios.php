<?php
require_once '../backend/config/config.php';

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'superadmin') {
    header("Location: ../index.php");
    exit;
}

$page = isset($_GET['page']) ? $_GET['page'] : 'lista';
$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';
$edit_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$edit_user = null;

if ($page == 'editar' && $edit_id) {
    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $edit_user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - AlCorte Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8fafc; color: #1e293b; }
        .text-gold { color: #b49363; }
        .bg-gold { background-color: #b49363; }
    </style>
</head>
<body class="font-sans antialiased">
    <div class="min-h-screen flex">
        <aside class="w-64 bg-white border-r border-slate-200 shadow-sm flex flex-col">
            <div class="p-6 border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <i class="fa-solid fa-scissors text-2xl text-gold"></i>
                    <span class="font-bold text-sm tracking-wider uppercase">AlCorte Pro</span>
                </div>
            </div>
            <nav class="p-4 space-y-1 flex-1">
                <a href="usuarios.php?page=lista" class="flex items-center gap-3 px-4 py-3 text-sm font-semibold rounded-lg <?php echo $page == 'lista' ? 'text-slate-900 bg-slate-100 border-l-4 border-gold' : 'text-slate-500 hover:text-slate-900 hover:bg-slate-50'; ?>">
                    <i class="fa-solid fa-list w-5"></i> Usuarios
                </a>
                <a href="usuarios.php?page=crear" class="flex items-center gap-3 px-4 py-3 text-sm font-semibold rounded-lg <?php echo $page == 'crear' ? 'text-slate-900 bg-slate-100 border-l-4 border-gold' : 'text-slate-500 hover:text-slate-900 hover:bg-slate-50'; ?>">
                    <i class="fa-solid fa-user-plus w-5"></i> Crear Usuario
                </a>
            </nav>
            <div class="p-4 border-t border-slate-100">
                <a href="superadmin.php" class="flex items-center gap-3 px-4 py-3 text-sm font-semibold text-slate-600 hover:text-slate-900">
                    <i class="fa-solid fa-arrow-left"></i> Volver
                </a>
            </div>
        </aside>

        <main class="flex-1 overflow-y-auto">
            <div class="p-8 max-w-6xl mx-auto">
                <?php if (!empty($msg)): ?>
                    <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg text-green-800 text-sm">
                        <i class="fa-solid fa-circle-check mr-2"></i> <?php echo $msg; ?>
                    </div>
                <?php endif; ?>

                <?php if ($page == 'lista'): ?>
                    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
                        <div class="p-6 border-b border-slate-200">
                            <h2 class="text-xl font-bold text-slate-900">Gestión de Usuarios</h2>
                            <p class="text-sm text-slate-600 mt-1">Administra los usuarios del sistema</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-slate-200 bg-slate-50">
                                        <th class="px-6 py-3 text-left text-xs font-bold text-slate-600 uppercase">Nombre</th>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-slate-600 uppercase">Usuario</th>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-slate-600 uppercase">Rol</th>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-slate-600 uppercase">Tienda</th>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-slate-600 uppercase">Barbero</th>
                                        <th class="px-6 py-3 text-left text-xs font-bold text-slate-600 uppercase">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">
                                    <?php
                                    $users = $conn->query("SELECT u.*, s.nombre as sucursal_nombre, b.nombre as barbero_nombre FROM usuarios u LEFT JOIN sucursales s ON u.sucursal_id = s.id LEFT JOIN barberos b ON u.barbero_id = b.id ORDER BY u.fecha_registro DESC");
                                    if ($users && $users->num_rows > 0) {
                                        while ($u = $users->fetch_assoc()) {
                                            ?>
                                            <tr class="hover:bg-slate-50">
                                                <td class="px-6 py-4 font-semibold text-slate-900"><?php echo htmlspecialchars($u['nombre']); ?></td>
                                                <td class="px-6 py-4 text-slate-600 font-mono"><?php echo htmlspecialchars($u['usuario']); ?></td>
                                                <td class="px-6 py-4">
                                                    <span class="px-2 py-1 text-xs font-bold rounded <?php
                                                        if ($u['rol'] == 'superadmin') echo 'bg-purple-100 text-purple-800';
                                                        elseif ($u['rol'] == 'admin') echo 'bg-blue-100 text-blue-800';
                                                        elseif ($u['rol'] == 'gerente') echo 'bg-amber-100 text-amber-800';
                                                        elseif ($u['rol'] == 'barbero') echo 'bg-emerald-100 text-emerald-800';
                                                        else echo 'bg-slate-100 text-slate-800';
                                                    ?>"><?php echo htmlspecialchars($u['rol']); ?></span>
                                                </td>
                                                <td class="px-6 py-4 text-slate-600"><?php echo htmlspecialchars($u['sucursal_nombre'] ?? '-'); ?></td>
                                                <td class="px-6 py-4 text-slate-600"><?php echo htmlspecialchars($u['barbero_nombre'] ?? '-'); ?></td>
                                                <td class="px-6 py-4 text-right space-x-2">
                                                    <a href="usuarios.php?page=editar&id=<?php echo $u['id']; ?>" class="px-3 py-1 bg-blue-600 text-white text-xs font-bold rounded hover:bg-blue-700">
                                                        <i class="fa-solid fa-pen mr-1"></i> Editar
                                                    </a>
                                                    <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                                        <form action="../backend/processing/usuarios.php" method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este usuario?')">
                                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                                            <input type="hidden" name="action" value="eliminar_usuario">
                                                            <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                                            <button type="submit" class="px-3 py-1 bg-rose-600 text-white text-xs font-bold rounded hover:bg-rose-700">
                                                                <i class="fa-solid fa-trash mr-1"></i> Eliminar
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php
                                        }
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($page == 'crear' || $page == 'editar'): ?>
                    <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-8 max-w-2xl">
                        <h2 class="text-xl font-bold text-slate-900 mb-6">
                            <?php echo $page == 'crear' ? 'Crear Nuevo Usuario' : 'Editar Usuario'; ?>
                        </h2>

                        <form action="../backend/processing/usuarios.php" method="POST" class="space-y-6">
                            <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                            <input type="hidden" name="action" value="<?php echo $page == 'crear' ? 'crear_usuario' : 'editar_usuario'; ?>">
                            <?php if ($page == 'editar'): ?>
                                <input type="hidden" name="id" value="<?php echo $edit_user['id']; ?>">
                            <?php endif; ?>

                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Nombre Completo *</label>
                                <input type="text" name="nombre" value="<?php echo htmlspecialchars($edit_user['nombre'] ?? ''); ?>" required class="w-full px-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-gold">
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Nombre de Usuario *</label>
                                <input type="text" name="usuario" value="<?php echo htmlspecialchars($edit_user['usuario'] ?? ''); ?>" required <?php echo $page == 'editar' ? 'readonly' : ''; ?> class="w-full px-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-gold">
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">
                                    Contraseña <?php echo $page == 'crear' ? '*' : '(dejar en blanco para mantener)'; ?>
                                </label>
                                <input type="password" name="password" <?php echo $page == 'crear' ? 'required' : ''; ?> class="w-full px-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-gold">
                                <p class="text-xs text-slate-500 mt-1">Mínimo 8 caracteres</p>
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Teléfono</label>
                                <input type="tel" name="telefono" value="<?php echo htmlspecialchars($edit_user['telefono'] ?? ''); ?>" class="w-full px-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-gold">
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-2">Rol *</label>
                                <select name="rol" id="rol" onchange="toggleRolFields()" required class="w-full px-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-gold">
                                    <option value="">Selecciona un rol</option>
                                    <option value="superadmin" <?php echo (($edit_user['rol'] ?? '') == 'superadmin') ? 'selected' : ''; ?>>SuperAdmin</option>
                                    <option value="admin" <?php echo (($edit_user['rol'] ?? '') == 'admin') ? 'selected' : ''; ?>>Admin</option>
                                    <option value="gerente" <?php echo (($edit_user['rol'] ?? '') == 'gerente') ? 'selected' : ''; ?>>Gerente de Tienda</option>
                                    <option value="barbero" <?php echo (($edit_user['rol'] ?? '') == 'barbero') ? 'selected' : ''; ?>>Barbero</option>
                                    <option value="cliente" <?php echo (($edit_user['rol'] ?? '') == 'cliente') ? 'selected' : ''; ?>>Cliente</option>
                                </select>
                            </div>

                            <div id="field_sucursal" style="display:none;">
                                <label class="block text-sm font-bold text-slate-700 mb-2">Tienda *</label>
                                <select name="sucursal_id" class="w-full px-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-gold">
                                    <option value="">Selecciona tienda</option>
                                    <?php
                                    $sucursales = $conn->query("SELECT * FROM sucursales WHERE activo = 1 AND id > 1 ORDER BY nombre");
                                    while ($suc = $sucursales->fetch_assoc()) {
                                        $selected = (($edit_user['sucursal_id'] ?? 0) == $suc['id']) ? 'selected' : '';
                                        echo "<option value=\"{$suc['id']}\" {$selected}>" . htmlspecialchars($suc['nombre']) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <div id="field_barbero" style="display:none;">
                                <label class="block text-sm font-bold text-slate-700 mb-2">Barbero *</label>
                                <select name="barbero_id" class="w-full px-4 py-2 border border-slate-200 rounded-lg focus:outline-none focus:border-gold">
                                    <option value="">Selecciona barbero</option>
                                    <?php
                                    $barberos = $conn->query("SELECT * FROM barberos WHERE activo = 1 ORDER BY nombre");
                                    while ($barb = $barberos->fetch_assoc()) {
                                        $selected = (($edit_user['barbero_id'] ?? 0) == $barb['id']) ? 'selected' : '';
                                        echo "<option value=\"{$barb['id']}\" {$selected}>" . htmlspecialchars($barb['nombre']) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>

                            <div class="flex gap-4 pt-6 border-t border-slate-200">
                                <button type="submit" class="flex-1 px-6 py-3 bg-gold text-white font-bold rounded-lg hover:bg-opacity-90">
                                    <i class="fa-solid fa-save mr-2"></i> <?php echo $page == 'crear' ? 'Crear Usuario' : 'Actualizar Usuario'; ?>
                                </button>
                                <a href="usuarios.php?page=lista" class="flex-1 px-6 py-3 bg-slate-200 text-slate-900 font-bold rounded-lg text-center hover:bg-slate-300">
                                    Cancelar
                                </a>
                            </div>
                        </form>
                    </div>

                    <script>
                        function toggleRolFields() {
                            const rol = document.getElementById('rol').value;
                            document.getElementById('field_sucursal').style.display = ['admin','gerente','barbero'].includes(rol) ? 'block' : 'none';
                            document.getElementById('field_barbero').style.display = (rol === 'barbero') ? 'block' : 'none';
                        }
                        toggleRolFields();
                    </script>
                <?php endif; ?>
            </div>
        </main>
    </div>
</body>
</html>

