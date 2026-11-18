<?php
/**
 * AlCorte Pro — Panel de Equipo y Promotores (SuperAdmin)
 * Gestión de promotores de campo, comisiones por alquiler y códigos de invitación con 10% de descuento.
 */

// Calcular totales de equipo para KPIs
$total_miembros = count($promotores_arr);
$promotores_activos = 0;
$total_tiendas_captadas = 0;
$total_comisiones_acumuladas = 0.0;
$total_comisiones_pendientes = 0.0;

foreach ($promotores_arr as $prm) {
    if (!empty($prm['activo'])) $promotores_activos++;
    $total_tiendas_captadas += intval($prm['total_tiendas'] ?? 0);
    $total_comisiones_acumuladas += floatval($prm['total_comisiones'] ?? 0);
    $total_comisiones_pendientes += floatval($prm['comisiones_pendientes'] ?? 0);
}
?>

<div class="page-header">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px">
        <div>
            <h1 class="page-title">Equipo & <span>Promotores</span></h1>
            <p class="page-subtitle">Red comercial en campo, códigos de invitación con 10% de descuento para tiendas y liquidación de comisiones por alquiler.</p>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button type="button" class="btn btn-ghost" onclick="toggleCargoModal()">
                <i class="fas fa-briefcase"></i> Gestionar Cargos
            </button>
            <button type="button" class="btn btn-gold" id="btnTogglePromotor" onclick="togglePromotorForm()">
                <i class="fas fa-user-plus"></i> Nuevo Promotor / Miembro
            </button>
        </div>
    </div>
</div>

<!-- KPIs DEL EQUIPO -->
<div class="kpi-grid" style="margin-bottom:24px">
    <div class="stat-card gold">
        <div class="stat-label">Promotores Activos</div>
        <div class="stat-value"><?php echo $promotores_activos; ?> <span style="font-size:13px;font-weight:600;color:#94a3b8">/ <?php echo $total_miembros; ?> total</span></div>
    </div>
    <div class="stat-card green">
        <div class="stat-label">Tiendas Alquiladas / Captadas</div>
        <div class="stat-value"><?php echo $total_tiendas_captadas; ?></div>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">Comisiones Generadas ($)</div>
        <div class="stat-value">$<?php echo number_format($total_comisiones_acumuladas, 2); ?></div>
    </div>
    <div class="stat-card rose">
        <div class="stat-label">Comisiones por Pagar ($)</div>
        <div class="stat-value">$<?php echo number_format($total_comisiones_pendientes, 2); ?></div>
    </div>
</div>

<!-- INFO BANNER REGLA DE NEGOCIO -->
<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:14px 18px;margin-bottom:24px;display:flex;align-items:flex-start;gap:12px;font-size:13px;color:#92400e;line-height:1.5">
    <i class="fas fa-bullhorn" style="font-size:18px;margin-top:2px;color:#d97706"></i>
    <div>
        <strong>Regla Comercial de Promotores & Descuento en Alquiler:</strong><br>
        Al salir al mercado, los promotores ofrecen el servicio a las tiendas con su <strong>Código de Invitación único</strong>. 
        Al usar el código, la tienda recibe automáticamente un <strong>10% de DESCUENTO</strong> en el alquiler mensual de su plan. 
        Si la tienda entra directamente a la página web sin promotor, <strong>no tiene descuento (paga tarifa plena)</strong>. 
        Al concretar el alquiler, el promotor recibe su porcentaje de comisión asignado.
    </div>
</div>

<!-- MODAL / FORMULARIO COLAPSABLE GESTIONAR CARGOS -->
<div id="cargoModalWrap" style="display:none;margin-bottom:24px;">
    <div class="card" style="border:1.5px solid #d4af37">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
            <h3><i class="fas fa-briefcase" style="color:#d4af37;margin-right:6px"></i> Cargos del Equipo Comercial</h3>
            <button type="button" onclick="toggleCargoModal()" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:18px"><i class="fas fa-times"></i></button>
        </div>
        <div class="card-body">
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px">
                <?php foreach ($cargos_arr as $cg): ?>
                    <span style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;padding:6px 12px;border-radius:20px;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:6px">
                        <i class="fas fa-check-circle" style="color:#10b981;font-size:10px"></i> <?php echo htmlspecialchars($cg['nombre']); ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <form action="../api/v1/superadmin" method="POST" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                <input type="hidden" name="action" value="add_cargo">
                <div style="flex:1;min-width:220px">
                    <label class="form-label">Nombre del Nuevo Cargo</label>
                    <input type="text" name="nombre_cargo" class="form-input" placeholder="Ej: Promotor/a Senior, Asesor Comercial, Líder de Zona" required>
                </div>
                <div style="flex:1.5;min-width:260px">
                    <label class="form-label">Descripción / Función (Opcional)</label>
                    <input type="text" name="descripcion_cargo" class="form-input" placeholder="Breve descripción de sus responsabilidades">
                </div>
                <button type="submit" class="btn btn-gold" style="height:44px"><i class="fas fa-plus"></i> Agregar Cargo</button>
            </form>
        </div>
    </div>
</div>

<!-- FORMULARIO COLAPSABLE REGISTRAR / EDITAR PROMOTOR -->
<div id="promotorFormWrap" style="display:none;margin-bottom:24px">
    <div class="card" style="border:1.5px solid #d4af37">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
            <h3 id="prmFormTitle"><i class="fas fa-user-plus" style="color:#d4af37;margin-right:6px"></i> Registrar Nuevo Miembro del Equipo</h3>
            <button type="button" onclick="closePromotorForm()" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:18px"><i class="fas fa-times"></i></button>
        </div>
        <div class="card-body">
            <form action="../api/v1/superadmin" method="POST" id="formPromotor">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                <input type="hidden" id="prm_action" name="action" value="add_promotor">
                <input type="hidden" id="prm_id" name="id" value="">

                <div class="grid-equal" style="margin-bottom:14px">
                    <div class="form-group">
                        <label class="form-label">Nombre y Apellido *</label>
                        <input type="text" id="prm_nombre" name="nombre" class="form-input" placeholder="Ej. Valeria Santos" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">C.I. / Documento de Identidad</label>
                        <input type="text" id="prm_documento" name="documento" class="form-input" placeholder="Ej. V-25.123.456">
                    </div>
                </div>

                <div class="grid-equal" style="margin-bottom:14px">
                    <div class="form-group">
                        <label class="form-label">Teléfono / WhatsApp *</label>
                        <input type="text" id="prm_telefono" name="telefono" class="form-input" placeholder="Ej. 0414-1234567" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Correo Electrónico</label>
                        <input type="email" id="prm_email" name="email" class="form-input" placeholder="correo@ejemplo.com">
                    </div>
                </div>

                <div class="grid-equal" style="margin-bottom:14px">
                    <div class="form-group">
                        <label class="form-label">Cargo Asignado *</label>
                        <select id="prm_cargo" name="cargo" class="form-select" required>
                            <?php foreach ($cargos_arr as $cg): ?>
                                <option value="<?php echo htmlspecialchars($cg['nombre']); ?>"><?php echo htmlspecialchars($cg['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">
                            Código de Invitación Único * 
                            <button type="button" onclick="generarCodigoRandom()" style="background:none;border:none;color:#d97706;font-size:11px;font-weight:700;cursor:pointer;margin-left:6px">
                                <i class="fas fa-magic"></i> Autogenerar
                            </button>
                        </label>
                        <input type="text" id="prm_codigo" name="codigo_invitacion" class="form-input" placeholder="Ej. VALERIA10, PROMO-LEO" style="text-transform:uppercase;font-weight:800;letter-spacing:1px" required oninput="this.value=this.value.toUpperCase()">
                        <small style="font-size:11px;color:#64748b">Este código lo entrega el promotor a la tienda para activar el 10% de descuento.</small>
                    </div>
                </div>

                <div class="grid-equal" style="margin-bottom:14px">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-tags" style="color:#059669;margin-right:4px"></i> % Descuento para la Tienda *</label>
                        <div style="display:flex;align-items:center;gap:8px">
                            <input type="number" step="0.01" min="0" max="100" id="prm_descuento" name="porcentaje_descuento" class="form-input" value="10.00" style="font-weight:800;color:#059669" required>
                            <span style="font-weight:900;color:#059669;font-size:16px">%</span>
                        </div>
                        <small style="font-size:11px;color:#64748b">Porcentaje de descuento que recibe la tienda al contratar con el código de este promotor.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-percentage" style="color:#d97706;margin-right:4px"></i> % Comisión para el Promotor *</label>
                        <div style="display:flex;align-items:center;gap:8px">
                            <input type="number" step="0.01" min="0" max="100" id="prm_comision" name="porcentaje_comision" class="form-input" value="15.00" style="font-weight:800;color:#d97706" required>
                            <span style="font-weight:900;color:#d97706;font-size:16px">%</span>
                        </div>
                        <small style="font-size:11px;color:#64748b">Porcentaje que le queda al promotor por cada alquiler mensual concretado.</small>
                    </div>
                </div>

                <div class="grid-equal" style="margin-bottom:14px">
                    <div class="form-group" id="grp_prm_activo" style="display:none">
                        <label class="form-label">Estado del Promotor</label>
                        <select id="prm_activo" name="activo" class="form-select">
                            <option value="1">Activo (Habilitado para comisionar)</option>
                            <option value="0">Inactivo (Pausado)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Notas / Observaciones</label>
                        <input type="text" id="prm_notas" name="notas" class="form-input" placeholder="Zona de trabajo asignada, acuerdos especiales, etc.">
                    </div>
                </div>

                <div style="display:flex;gap:10px">
                    <button type="submit" class="btn btn-gold" id="btnSubmitPrm"><i class="fas fa-save"></i> Guardar Promotor</button>
                    <button type="button" class="btn btn-ghost" onclick="closePromotorForm()">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- LISTADO DE PROMOTORES -->
<div class="card" style="margin-bottom:24px">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h3><i class="fas fa-users-cog" style="color:#d4af37;margin-right:6px"></i> Equipo Comercial & Promotores Registrados</h3>
        <span style="font-size:12px;color:#64748b;font-weight:700"><?php echo count($promotores_arr); ?> miembros</span>
    </div>
    <div class="card-body" style="padding:0">
        <?php if (empty($promotores_arr)): ?>
            <div style="text-align:center;padding:50px;color:#94a3b8">
                <i class="fas fa-user-friends" style="font-size:40px;margin-bottom:12px;display:block;opacity:.4"></i>
                <p style="font-weight:700">No hay promotores registrados aún.</p>
                <p style="font-size:13px;margin-top:4px">Agrega tu primer promotor/a para activar el programa de invitaciones y comisiones.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:left">
                    <thead>
                        <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.05em">
                            <th style="padding:14px 18px">Miembro / Contacto</th>
                            <th style="padding:14px 18px">Cargo</th>
                            <th style="padding:14px 18px">Código Invitación</th>
                            <th style="padding:14px 18px;text-align:center">% Desc. Tienda</th>
                            <th style="padding:14px 18px;text-align:center">% Comisión</th>
                            <th style="padding:14px 18px;text-align:center">Tiendas</th>
                            <th style="padding:14px 18px">Total Ganado</th>
                            <th style="padding:14px 18px">Por Pagar</th>
                            <th style="padding:14px 18px">Estado</th>
                            <th style="padding:14px 18px;text-align:right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($promotores_arr as $p): ?>
                        <tr style="border-bottom:1px solid #f1f5f9;transition:background .15s">
                            <td style="padding:14px 18px">
                                <div style="font-weight:800;color:#0f172a"><?php echo htmlspecialchars($p['nombre']); ?></div>
                                <div style="font-size:11px;color:#64748b;margin-top:2px">
                                    <?php if ($p['documento']): ?><span>C.I. <?php echo htmlspecialchars($p['documento']); ?> • </span><?php endif; ?>
                                    <?php if ($p['telefono']): ?>
                                        <a href="https://wa.me/<?php echo preg_replace('/\D/','',$p['telefono']); ?>" target="_blank" style="color:#059669;text-decoration:none;font-weight:600">
                                            <i class="fab fa-whatsapp"></i> <?php echo htmlspecialchars($p['telefono']); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="padding:14px 18px">
                                <span style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700">
                                    <?php echo htmlspecialchars($p['cargo']); ?>
                                </span>
                            </td>
                            <td style="padding:14px 18px">
                                <div style="display:inline-flex;align-items:center;gap:6px">
                                    <span style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:4px 10px;border-radius:6px;font-family:monospace;font-weight:900;font-size:12px;letter-spacing:.05em">
                                        <?php echo htmlspecialchars($p['codigo_invitacion']); ?>
                                    </span>
                                    <button type="button" onclick="copiarTexto('<?php echo htmlspecialchars($p['codigo_invitacion'], ENT_QUOTES); ?>')" title="Copiar código" style="background:#f1f5f9;border:1px solid #cbd5e1;padding:4px 8px;border-radius:6px;cursor:pointer;color:#475569;font-size:11px">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            </td>
                            <td style="padding:14px 18px;text-align:center">
                                <span style="background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;padding:3px 8px;border-radius:6px;font-weight:800;font-size:12px">
                                    -<?php echo number_format((float)($p['porcentaje_descuento'] ?? 10), 1); ?>%
                                </span>
                                <div style="font-size:10px;color:#64748b;margin-top:2px">ahorro cliente</div>
                            </td>
                            <td style="padding:14px 18px;text-align:center">
                                <span style="background:#fef3c7;color:#b45309;border:1px solid #fde68a;padding:3px 8px;border-radius:6px;font-weight:800;font-size:12px">
                                    <?php echo number_format((float)($p['porcentaje_comision'] ?? 15), 1); ?>%
                                </span>
                                <div style="font-size:10px;color:#64748b;margin-top:2px">pago promotor</div>
                            </td>
                            <td style="padding:14px 18px;text-align:center">
                                <span style="background:#eff6ff;color:#1d4ed8;font-weight:900;padding:4px 10px;border-radius:999px;font-size:12px">
                                    <?php echo intval($p['total_tiendas']); ?>
                                </span>
                            </td>
                            <td style="padding:14px 18px;font-weight:900;color:#15803d">
                                $<?php echo number_format((float)($p['total_comisiones'] ?? 0), 2); ?>
                            </td>
                            <td style="padding:14px 18px;font-weight:900;color:<?php echo floatval($p['comisiones_pendientes']) > 0 ? '#b91c1c' : '#94a3b8'; ?>">
                                $<?php echo number_format((float)($p['comisiones_pendientes'] ?? 0), 2); ?>
                            </td>
                            <td style="padding:14px 18px">
                                <?php if (!empty($p['activo'])): ?>
                                    <span style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700">Activo</span>
                                <?php else: ?>
                                    <span style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:14px 18px;text-align:right">
                                <div style="display:inline-flex;gap:6px">
                                    <button type="button" class="btn btn-ghost btn-sm" onclick='editarPromotor(<?php echo json_encode($p, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)'>
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form action="../api/v1/superadmin" method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar a este miembro del equipo?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                        <input type="hidden" name="action" value="delete_promotor">
                                        <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- HISTORIAL DE COMISIONES GENERADAS -->
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <h3><i class="fas fa-hand-holding-usd" style="color:#d4af37;margin-right:6px"></i> Historial de Comisiones Generadas por Alquiler</h3>
        <span style="font-size:12px;color:#64748b;font-weight:700"><?php echo count($comisiones_arr); ?> registros</span>
    </div>
    <div class="card-body" style="padding:0">
        <?php if (empty($comisiones_arr)): ?>
            <div style="text-align:center;padding:40px;color:#94a3b8">
                <i class="fas fa-receipt" style="font-size:36px;margin-bottom:10px;display:block;opacity:.4"></i>
                <p style="font-weight:700">Sin comisiones generadas todavía.</p>
                <p style="font-size:12px;margin-top:4px">Las comisiones se generan automáticamente al registrar o renovar el alquiler de tiendas con código de promotor/a.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:left">
                    <thead>
                        <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.05em">
                            <th style="padding:12px 16px">Fecha</th>
                            <th style="padding:12px 16px">Promotor(a)</th>
                            <th style="padding:12px 16px">Tienda Afiliada</th>
                            <th style="padding:12px 16px">Plan & Base</th>
                            <th style="padding:12px 16px">Desc. Tienda</th>
                            <th style="padding:12px 16px">Monto Pagado</th>
                            <th style="padding:12px 16px">Comisión Promotor</th>
                            <th style="padding:12px 16px">Estado</th>
                            <th style="padding:12px 16px;text-align:right">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($comisiones_arr as $cm): ?>
                        <tr style="border-bottom:1px solid #f1f5f9">
                            <td style="padding:12px 16px;color:#64748b;font-size:12px">
                                <?php echo date('d/m/Y', strtotime($cm['fecha_registro'])); ?>
                            </td>
                            <td style="padding:12px 16px">
                                <div style="font-weight:800;color:#0f172a"><?php echo htmlspecialchars($cm['promotor_nombre']); ?></div>
                                <span style="font-size:10px;background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:4px;font-family:monospace;font-weight:700">
                                    <?php echo htmlspecialchars($cm['promotor_codigo']); ?>
                                </span>
                            </td>
                            <td style="padding:12px 16px;font-weight:700;color:#0f172a">
                                <?php echo htmlspecialchars($cm['sucursal_nombre']); ?>
                            </td>
                            <td style="padding:12px 16px">
                                <div><?php echo htmlspecialchars($cm['plan_nombre'] ?? 'Plan'); ?></div>
                                <div style="font-size:11px;color:#94a3b8">$<?php echo number_format((float)($cm['monto_plan'] ?? 0), 2); ?></div>
                            </td>
                            <td style="padding:12px 16px">
                                <span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:800">
                                    -<?php echo number_format((float)($cm['descuento_porcentaje'] ?? 0), 0); ?>%
                                </span>
                            </td>
                            <td style="padding:12px 16px;font-weight:800;color:#0f172a">
                                $<?php echo number_format((float)($cm['monto_pagado'] ?? 0), 2); ?>
                            </td>
                            <td style="padding:12px 16px">
                                <span style="font-weight:900;color:#15803d;font-size:14px">$<?php echo number_format((float)($cm['monto_comision'] ?? 0), 2); ?></span>
                                <div style="font-size:10px;color:#64748b">(<?php echo number_format((float)($cm['comision_porcentaje'] ?? 0), 1); ?>%)</div>
                            </td>
                            <td style="padding:12px 16px">
                                <?php if ($cm['estado'] === 'pagado'): ?>
                                    <span style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:800">
                                        <i class="fas fa-check"></i> Pagada
                                    </span>
                                    <?php if ($cm['fecha_pago_comision']): ?>
                                        <div style="font-size:10px;color:#64748b;margin-top:2px"><?php echo date('d/m/Y', strtotime($cm['fecha_pago_comision'])); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="background:#fffbeb;color:#b45309;border:1px solid #fde68a;padding:3px 8px;border-radius:6px;font-size:11px;font-weight:800">
                                        <i class="fas fa-clock"></i> Pendiente
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px 16px;text-align:right">
                                <?php if ($cm['estado'] === 'pendiente'): ?>
                                    <form action="../api/v1/superadmin" method="POST" style="display:inline" onsubmit="return promptPagoComision(this)">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrf_generate(); ?>">
                                        <input type="hidden" name="action" value="pagar_comision">
                                        <input type="hidden" name="comision_id" value="<?php echo $cm['id']; ?>">
                                        <input type="hidden" name="referencia_pago" value="" id="ref_pago_<?php echo $cm['id']; ?>">
                                        <button type="submit" class="btn btn-gold btn-sm" title="Marcar como pagada al promotor">
                                            <i class="fas fa-check"></i> Liquidar
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#94a3b8;font-size:11px"><i class="fas fa-check-double" style="color:#10b981"></i> Liquidado</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function togglePromotorForm() {
    const wrap = document.getElementById('promotorFormWrap');
    const isHidden = (wrap.style.display === 'none' || wrap.style.display === '');
    if (isHidden) {
        resetPromotorForm();
        wrap.style.display = 'block';
        wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        document.getElementById('btnTogglePromotor').innerHTML = '<i class="fas fa-times"></i> Cerrar Formulario';
    } else {
        closePromotorForm();
    }
}

function closePromotorForm() {
    document.getElementById('promotorFormWrap').style.display = 'none';
    document.getElementById('btnTogglePromotor').innerHTML = '<i class="fas fa-user-plus"></i> Nuevo Promotor / Miembro';
}

function resetPromotorForm() {
    document.getElementById('formPromotor').reset();
    document.getElementById('prm_action').value = 'add_promotor';
    document.getElementById('prm_id').value = '';
    document.getElementById('prmFormTitle').innerHTML = '<i class="fas fa-user-plus" style="color:#d4af37;margin-right:6px"></i> Registrar Nuevo Miembro del Equipo';
    document.getElementById('btnSubmitPrm').innerHTML = '<i class="fas fa-save"></i> Guardar Promotor';
    document.getElementById('grp_prm_activo').style.display = 'none';
    document.getElementById('prm_descuento').value = '10.00';
    document.getElementById('prm_comision').value = '15.00';
}

function editarPromotor(prm) {
    document.getElementById('prm_action').value = 'edit_promotor';
    document.getElementById('prm_id').value = prm.id;
    document.getElementById('prm_nombre').value = prm.nombre || '';
    document.getElementById('prm_documento').value = prm.documento || '';
    document.getElementById('prm_telefono').value = prm.telefono || '';
    document.getElementById('prm_email').value = prm.email || '';
    document.getElementById('prm_cargo').value = prm.cargo || 'Promotor/a';
    document.getElementById('prm_codigo').value = prm.codigo_invitacion || '';
    document.getElementById('prm_descuento').value = prm.porcentaje_descuento || '10.00';
    document.getElementById('prm_comision').value = prm.porcentaje_comision || '15.00';
    document.getElementById('prm_notas').value = prm.notas || '';
    document.getElementById('prm_activo').value = prm.activo || '1';

    document.getElementById('prmFormTitle').innerHTML = '<i class="fas fa-user-edit" style="color:#d4af37;margin-right:6px"></i> Editar Miembro: ' + (prm.nombre || '');
    document.getElementById('btnSubmitPrm').innerHTML = '<i class="fas fa-save"></i> Actualizar Miembro';
    document.getElementById('grp_prm_activo').style.display = 'block';

    const wrap = document.getElementById('promotorFormWrap');
    wrap.style.display = 'block';
    wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    document.getElementById('btnTogglePromotor').innerHTML = '<i class="fas fa-times"></i> Cerrar Formulario';
}

function toggleCargoModal() {
    const wrap = document.getElementById('cargoModalWrap');
    wrap.style.display = (wrap.style.display === 'none' || wrap.style.display === '') ? 'block' : 'none';
    if (wrap.style.display === 'block') {
        wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function generarCodigoRandom() {
    const nombre = document.getElementById('prm_nombre').value.trim();
    let base = 'PROMO';
    if (nombre) {
        const parts = nombre.split(' ');
        base = parts[0].toUpperCase().replace(/[^A-Z]/g, '').slice(0, 7);
    }
    const num = Math.floor(10 + Math.random() * 90);
    document.getElementById('prm_codigo').value = base + num;
}

function copiarTexto(texto) {
    navigator.clipboard.writeText(texto).then(() => {
        alert('Código copiado al portapapeles: ' + texto);
    });
}

function promptPagoComision(formElem) {
    const ref = prompt("Ingresa el N° de referencia o comprobante de pago de la comisión al promotor/a:", "PAGO-COM-" + Math.floor(1000 + Math.random() * 9000));
    if (ref === null) return false;
    formElem.querySelector('input[name="referencia_pago"]').value = ref.trim() || 'Pago de comisión verificado';
    return true;
}
</script>
