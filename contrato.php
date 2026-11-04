<?php
require_once './backend/config/config.php';

// Configuración general
$whatsapp_soporte = '584120000000'; // Tu número de WhatsApp aquí (código de país sin signos)
$nombre_plataforma = 'AlCorte Pro';

// Si se envía formulario de firma digital
$firma_completada = false;
$cert_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'firmar_contrato') {
    $nombre_negocio = trim($_POST['nombre_negocio'] ?? '');
    $representante  = trim($_POST['representante'] ?? '');
    $documento      = trim($_POST['documento'] ?? '');
    $telefono       = trim($_POST['telefono'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $categoria      = trim($_POST['categoria'] ?? 'Belleza y Bienestar');
    $acepto         = isset($_POST['acepto_terminos']) && $_POST['acepto_terminos'] === '1';

    if (!empty($nombre_negocio) && !empty($representante) && !empty($documento) && $acepto) {
        $codigo_certificado = 'ACP-' . strtoupper(substr(md5($nombre_negocio . $documento . time()), 0, 10));
        $fecha_firma = date('d/m/Y h:i A');
        $ip_cliente = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $cert_data = [
            'codigo' => $codigo_certificado,
            'nombre_negocio' => $nombre_negocio,
            'representante' => $representante,
            'documento' => $documento,
            'telefono' => $telefono,
            'email' => $email,
            'categoria' => $categoria,
            'fecha' => $fecha_firma,
            'ip' => $ip_cliente
        ];
        $firma_completada = true;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contrato y Términos de Servicio — <?= htmlspecialchars($nombre_plataforma) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Playfair+Display:ital,wght@0,600;1,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --gold-primary: #D4AF37;
            --gold-secondary: #AA8C2C;
            --gold-glow: rgba(212, 175, 55, 0.25);
            --dark-bg: #07090e;
            --dark-card: #0f131c;
            --border-color: rgba(212, 175, 55, 0.18);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--dark-bg);
            color: var(--text-main);
            line-height: 1.7;
            padding: 30px 16px 80px 16px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        .header-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: #fff;
            font-size: 20px;
            font-weight: 700;
        }
        .brand-logo i {
            color: var(--gold-primary);
            font-size: 24px;
        }

        .btn-link {
            color: var(--gold-primary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: opacity 0.2s;
        }
        .btn-link:hover { opacity: 0.8; text-decoration: underline; }

        .contract-card {
            background: var(--dark-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.6);
            position: relative;
            overflow: hidden;
        }

        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(212, 175, 55, 0.12);
            border: 1px solid var(--gold-primary);
            color: var(--gold-primary);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 6px 14px;
            border-radius: 999px;
            margin-bottom: 16px;
        }

        h1.title {
            font-size: 32px;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #fff 60%, var(--gold-primary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        p.subtitle {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 30px;
        }

        .contract-body {
            background: rgba(255,255,255,0.02);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 14px;
            padding: 24px 28px;
            font-size: 14px;
            color: #cbd5e1;
            margin-bottom: 36px;
            max-height: 480px;
            overflow-y: auto;
        }

        .contract-body::-webkit-scrollbar {
            width: 6px;
        }
        .contract-body::-webkit-scrollbar-thumb {
            background: rgba(212, 175, 55, 0.3);
            border-radius: 10px;
        }

        .contract-body h3 {
            color: var(--gold-primary);
            font-size: 16px;
            margin: 22px 0 10px 0;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .contract-body h3:first-child { margin-top: 0; }

        .contract-body p, .contract-body ul {
            margin-bottom: 12px;
        }

        .contract-body ul {
            padding-left: 20px;
        }
        .contract-body li {
            margin-bottom: 6px;
        }

        /* FORM */
        .sign-box {
            background: rgba(212, 175, 55, 0.04);
            border: 1.5px dashed var(--gold-primary);
            border-radius: 16px;
            padding: 30px;
        }

        .sign-box h2 {
            font-size: 20px;
            margin-bottom: 8px;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sign-box h2 i { color: var(--gold-primary); }

        .sign-box p.sign-desc {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 24px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-control {
            width: 100%;
            padding: 12px 14px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 10px;
            color: #fff;
            font-family: inherit;
            font-size: 14px;
            transition: all 0.2s;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--gold-primary);
            background: rgba(255,255,255,0.08);
            box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.15);
        }

        .checkbox-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin: 22px 0 26px 0;
            background: rgba(255,255,255,0.02);
            padding: 14px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.08);
        }

        .checkbox-row input[type="checkbox"] {
            margin-top: 4px;
            width: 18px;
            height: 18px;
            accent-color: var(--gold-primary);
            cursor: pointer;
        }

        .checkbox-row label {
            font-size: 13px;
            color: #e2e8f0;
            cursor: pointer;
            line-height: 1.5;
        }

        .btn-submit {
            background: linear-gradient(135deg, var(--gold-primary), var(--gold-secondary));
            color: #0b0f19;
            border: none;
            padding: 15px 30px;
            font-size: 15px;
            font-weight: 700;
            border-radius: 12px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 4px 20px var(--gold-glow);
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(212,175,55,0.35);
        }

        /* CERTIFICADO */
        .cert-card {
            background: #ffffff;
            color: #0f172a;
            border-radius: 16px;
            padding: 40px;
            border: 4px double #d4af37;
            position: relative;
            box-shadow: 0 20px 45px rgba(0,0,0,0.4);
            margin-bottom: 24px;
        }

        .cert-header {
            text-align: center;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 20px;
            margin-bottom: 24px;
        }
        .cert-header h2 {
            font-family: 'Playfair Display', serif;
            font-size: 26px;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .cert-header p {
            color: #64748b;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .cert-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            font-size: 14px;
            margin-bottom: 24px;
        }

        .cert-item strong {
            display: block;
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .cert-item span {
            font-weight: 600;
            color: #1e293b;
        }

        .cert-badge {
            background: #ecfdf5;
            border: 1px solid #10b981;
            color: #065f46;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .cert-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-print {
            background: #0f172a;
            color: #fff;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-whatsapp-cert {
            background: #25D366;
            color: #fff;
            text-decoration: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        @media print {
            body { background: #fff; color: #000; padding: 0; }
            .header-nav, .contract-body, .sign-box, .cert-actions { display: none; }
            .contract-card { border: none; box-shadow: none; padding: 0; background: #fff; }
            .cert-card { border: 2px solid #000; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-nav">
            <a href="index.php" class="brand-logo">
                <i class="fas fa-calendar-check"></i>
                <span><?= htmlspecialchars($nombre_plataforma) ?></span>
            </a>
            <a href="index.php" class="btn-link">
                <i class="fas fa-arrow-left"></i> Volver a la página principal
            </a>
        </div>

        <div class="contract-card">
            <span class="badge-status"><i class="fas fa-file-contract"></i> Acuerdo de Servicio SaaS</span>
            <h1 class="title">Contrato de Suscripción y Prestación de Servicios</h1>
            <p class="subtitle">Términos generales para negocios de Belleza, Estética, Barbería, Spas, Uñas y Cuidado Personal.</p>

            <?php if ($firma_completada): ?>
                <!-- VISTA DE CERTIFICADO DE ACEPTACIÓN -->
                <div class="cert-card" id="certificadoArea">
                    <div class="cert-header">
                        <h2>Certificado de Aceptación Digital</h2>
                        <p>Contrato de Servicio — <?= htmlspecialchars($nombre_plataforma) ?></p>
                    </div>

                    <div class="cert-badge">
                        <i class="fas fa-check-circle"></i> CONTRATO ACEPTADO Y VALIDADO DIGITALMENTE
                    </div>

                    <div class="cert-details">
                        <div class="cert-item">
                            <strong>Código de Validación:</strong>
                            <span style="font-family:monospace; color:#b45309;"><?= htmlspecialchars($cert_data['codigo']) ?></span>
                        </div>
                        <div class="cert-item">
                            <strong>Fecha y Hora de Firma:</strong>
                            <span><?= htmlspecialchars($cert_data['fecha']) ?></span>
                        </div>
                        <div class="cert-item">
                            <strong>Negocio / Razón Comercial:</strong>
                            <span><?= htmlspecialchars($cert_data['nombre_negocio']) ?></span>
                        </div>
                        <div class="cert-item">
                            <strong>Sector / Especialidad:</strong>
                            <span><?= htmlspecialchars($cert_data['categoria']) ?></span>
                        </div>
                        <div class="cert-item">
                            <strong>Representante Legal / Titular:</strong>
                            <span><?= htmlspecialchars($cert_data['representante']) ?></span>
                        </div>
                        <div class="cert-item">
                            <strong>Documento de Identidad / RIF:</strong>
                            <span><?= htmlspecialchars($cert_data['documento']) ?></span>
                        </div>
                        <div class="cert-item">
                            <strong>Teléfono / WhatsApp:</strong>
                            <span><?= htmlspecialchars($cert_data['telefono'] ?: 'No especificado') ?></span>
                        </div>
                        <div class="cert-item">
                            <strong>Correo Electrónico:</strong>
                            <span><?= htmlspecialchars($cert_data['email'] ?: 'No especificado') ?></span>
                        </div>
                    </div>

                    <p style="font-size:12px; color:#64748b; line-height:1.5; border-top:1px solid #e2e8f0; padding-top:12px;">
                        El titular ha manifestado expresamente su conformidad con las cláusulas de prestación del servicio mensual en la nube, uso de enlace de reservas para clientes, confidencialidad de base de datos y políticas de renovación.
                    </p>
                </div>

                <div class="cert-actions">
                    <button onclick="window.print()" class="btn-print">
                        <i class="fas fa-print"></i> Imprimir / Guardar en PDF
                    </button>
                    <?php
                        $msg_wa = "📄 *Constancia de Aceptación de Contrato - " . $nombre_plataforma . "*\n\n"
                                . "• *Código:* " . $cert_data['codigo'] . "\n"
                                . "• *Negocio:* " . $cert_data['nombre_negocio'] . "\n"
                                . "• *Titular:* " . $cert_data['representante'] . "\n"
                                . "• *Documento:* " . $cert_data['documento'] . "\n"
                                . "• *Fecha:* " . $cert_data['fecha'] . "\n\n"
                                . "He aceptado digitalmente los términos y condiciones del servicio.";
                        $wa_cert_url = "https://wa.me/" . $whatsapp_soporte . "?text=" . urlencode($msg_wa);
                    ?>
                    <a href="<?= htmlspecialchars($wa_cert_url) ?>" target="_blank" class="btn-whatsapp-cert">
                        <i class="fab fa-whatsapp"></i> Notificar Aceptación a Soporte por WhatsApp
                    </a>
                    <a href="login.php" class="btn-print" style="background:#b49363; text-decoration:none;">
                        <i class="fas fa-sign-in-alt"></i> Ir a Iniciar Sesión
                    </a>
                </div>

            <?php else: ?>

                <!-- TEXTO DEL CONTRATO -->
                <div class="contract-body">
                    <h3>1. Partes y Objeto del Contrato</h3>
                    <p>El presente documento regula los términos de uso del software como servicio (SaaS) denominado <strong><?= htmlspecialchars($nombre_plataforma) ?></strong>, provisto al suscriptor (en adelante "El Negocio"), facultándolo para el uso de la plataforma digital de gestión de citas, especialistas, clientes, comisiones y catálogo comercial.</p>

                    <h3>2. Ámbito de Aplicación</h3>
                    <p>La plataforma está diseñada de forma integral para todo negocio basado en reservas y atención por turnos, incluyendo pero no limitándose a:</p>
                    <ul>
                        <li>Salones de Belleza, Peluquerías y Barberías.</li>
                        <li>Spas, Centros de Relajación, Masajes y Terapias.</li>
                        <li>Salones de Uñas, Manicuristas y Pedicuristas independientes o en equipo.</li>
                        <li>Estudios de Pestañas, Cejas, Microblading y Cosmetología Facial.</li>
                        <li>Estudios de Tatuajes, Body Art y Piercing.</li>
                        <li>Consultorios de Estética, Nutrición y Bienestar.</li>
                    </ul>

                    <h3>3. Enlace Exclusivo para Clientes</h3>
                    <p>Cada negocio contratante recibe un enlace único y cifrado (token digital) que puede difundir en sus redes sociales (Instagram, TikTok, WhatsApp Business, código QR físico). Mediante este enlace, sus clientes pueden reservar citas en tiempo real las 24 horas del día sin necesidad de descargar aplicaciones adicionales.</p>

                    <h3>4. Modalidad de Pago y Vigencia de la Suscripción</h3>
                    <ul>
                        <li>El servicio se contrata bajo la modalidad de <strong>mensualidad anticipada (30 días de servicio)</strong> según el plan seleccionado (Básico, Profesional o Pro).</li>
                        <li>El negocio se compromete a reportar el comprobante de pago de su cuota mensual previo a la fecha de vencimiento.</li>
                        <li>Al confirmarse el pago por parte de administración, la cuenta y el enlace de reservas para clientes se mantienen activos ininterrumpidamente.</li>
                    </ul>

                    <h3>5. Disponibilidad del Servicio y Soporte Técnico</h3>
                    <p>El proveedor garantiza una disponibilidad óptima del servidor en la nube, copias de seguridad de la base de datos y soporte técnico vía WhatsApp para resolver dudas operativas o incidencias técnicas.</p>

                    <h3>6. Privacidad y Propiedad de los Datos</h3>
                    <p>Toda la información registrada por El Negocio (listas de clientes, números de teléfono, historial de citas, balance de caja y rendimiento de los especialistas) es de estricta propiedad y confidencialidad de El Negocio. La plataforma no comercializa ni divulga datos a terceros bajo ninguna circunstancia.</p>

                    <h3>7. Suspensión Temporal por Falta de Pago</h3>
                    <p>En caso de no renovarse el mes contratado a la fecha de corte, el sistema podrá pausar temporalmente el acceso al enlace de reservas de clientes y al panel administrativo, hasta tanto el negocio reporte su comprobante de pago correspondiente. Los datos y registros históricos no serán eliminados durante el período de gracia.</p>
                </div>

                <!-- FORMULARIO DE FIRMA DIGITAL -->
                <div class="sign-box">
                    <h2><i class="fas fa-signature"></i> Aceptación Digital del Contrato</h2>
                    <p class="sign-desc">Completa tus datos para formalizar la activación o renovación de tu servicio con validez digital inmediata:</p>

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="firmar_contrato">

                        <div class="form-grid">
                            <div class="form-group">
                                <label>Nombre del Negocio / Marca *</label>
                                <input type="text" name="nombre_negocio" class="form-control" placeholder="Ej. Spa Armonía / Nails Studio 54" required>
                            </div>
                            <div class="form-group">
                                <label>Categoría / Sector *</label>
                                <select name="categoria" class="form-control">
                                    <option value="Spa & Bienestar">Spa & Bienestar</option>
                                    <option value="Salón de Uñas / Manicura">Salón de Uñas / Manicura</option>
                                    <option value="Peluquería / Salón de Belleza">Peluquería / Salón de Belleza</option>
                                    <option value="Barbería">Barbería</option>
                                    <option value="Cejas & Pestañas">Cejas & Pestañas</option>
                                    <option value="Estética / Cuidado Facial">Estética / Cuidado Facial</option>
                                    <option value="Estudio de Tatuajes">Estudio de Tatuajes</option>
                                    <option value="Otro Servicio por Cita">Otro Servicio por Cita</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Nombre del Representante / Titular *</label>
                                <input type="text" name="representante" class="form-control" placeholder="Tu nombre y apellido" required>
                            </div>
                            <div class="form-group">
                                <label>Cédula de Identidad / RIF / Pasaporte *</label>
                                <input type="text" name="documento" class="form-control" placeholder="Ej. V-18234567 o J-50123456-0" required>
                            </div>
                            <div class="form-group">
                                <label>WhatsApp / Teléfono *</label>
                                <input type="tel" name="telefono" class="form-control" placeholder="Ej. +58 412 1234567" required>
                            </div>
                            <div class="form-group">
                                <label>Correo Electrónico</label>
                                <input type="email" name="email" class="form-control" placeholder="negocio@ejemplo.com">
                            </div>
                        </div>

                        <div class="checkbox-row">
                            <input type="checkbox" name="acepto_terminos" id="acepto_terminos" value="1" required>
                            <label for="acepto_terminos">
                                <strong>Declaro que he leído, entiendo y acepto expresamente las condiciones</strong> del Contrato de Prestación de Servicios SaaS AlCorte Pro para la gestión digital de mi negocio y citas de clientes.
                            </label>
                        </div>

                        <button type="submit" class="btn-submit">
                            <i class="fas fa-check-circle"></i> Aceptar y Firmar Contrato Digitalmente
                        </button>
                    </form>
                </div>

            <?php endif; ?>

        </div>
    </div>
</body>
</html>
