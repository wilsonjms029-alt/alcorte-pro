<?php
/**
 * AlCorte Pro — Landing Page Comercial
 * Plataforma Integral de Gestión de Citas para Negocios de Belleza & Bienestar
 */
require_once './backend/config/config.php';

// === CONFIGURACIÓN DE CONTACTO Y PAGOS ===
$config_saas = [
    'whatsapp_ventas'   => '584120000000', // Tu WhatsApp aquí (código de país sin +, ej: 584121234567)
    'whatsapp_display'  => '+58 412 000 0000',
    'nombre_app'        => 'AlCorte Pro',
    'pago_movil' => [
        'banco'     => 'Banco Mercantil (0105)',
        'telefono'  => '0412-0000000',
        'ci_rif'    => 'V-00.000.000',
        'titular'   => 'AlCorte Pro C.A.'
    ],
    'zelle' => [
        'email'     => 'pagos@alcortepro.com',
        'titular'   => 'AlCorte Pro Services LLC'
    ],
    'transferencia' => [
        'banco'     => 'Banco Mercantil',
        'cuenta'    => '0105-0000-0000-0000-0000',
        'rif'       => 'J-00000000-0',
        'titular'   => 'AlCorte Pro C.A.'
    ],
    'binance' => [
        'pay_id'    => '123456789',
        'usdt_trc20'=> 'TXXXXXXXXXXXXXXXXXXXXXXXXXXXX'
    ]
];

// Cargar planes de la base de datos si existen
$planes = [];
try {
    $q = $conn->query("SELECT * FROM planes WHERE activo = 1 ORDER BY nivel ASC, precio_mensual ASC");
    if ($q && $q->num_rows > 0) {
        while ($row = $q->fetch_assoc()) {
            $planes[] = $row;
        }
    }
} catch (\Throwable $e) {}

if (empty($planes)) {
    $planes = [
        [
            'id' => 1,
            'nombre' => 'Básico',
            'precio_mensual' => 10.00,
            'max_barberos' => 1,
            'nivel' => 1,
            'descripcion' => 'Para profesionales independientes y especialistas individuales.'
        ],
        [
            'id' => 2,
            'nombre' => 'Profesional',
            'precio_mensual' => 30.00,
            'max_barberos' => 5,
            'nivel' => 2,
            'descripcion' => 'Para salones, spas y barberías con equipo de trabajo.'
        ],
        [
            'id' => 3,
            'nombre' => 'Pro',
            'precio_mensual' => 70.00,
            'max_barberos' => 0,
            'nivel' => 3,
            'descripcion' => 'Para grandes centros sin límite de personal e inventario completo.'
        ]
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($config_saas['nombre_app']) ?> — Sistema de Citas para Negocios de Belleza & Bienestar</title>
    <meta name="description" content="Automatiza las citas y clientes de tu Spa, Salón de Uñas, Barbería, Peluquería o Estudio Estético. Enlace de reservas 24/7 para tus clientes sin apps.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #d97706;
            --primary-light: #f59e0b;
            --primary-gradient: linear-gradient(135deg, #d97706 0%, #f59e0b 50%, #fbbf24 100%);
            --primary-glow: rgba(245, 158, 11, 0.35);
            --bg-light: #f8fafc;
            --bg-card: #ffffff;
            --text-dark: #0f172a;
            --text-slate: #334155;
            --text-muted: #64748b;
            --border-light: #e2e8f0;
            --whatsapp-green: #25D366;
            --whatsapp-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        * { margin:0; padding:0; box-sizing:border-box; scroll-behavior: smooth; }
        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-slate);
            line-height: 1.6;
            overflow-x: hidden;
            background-image: 
                radial-gradient(circle at 50% 0%, rgba(245, 158, 11, 0.12) 0%, transparent 50%),
                radial-gradient(circle at 85% 25%, rgba(244, 63, 94, 0.06) 0%, transparent 40%),
                radial-gradient(circle at 15% 35%, rgba(99, 102, 241, 0.05) 0%, transparent 40%);
        }

        .container { max-width: 1200px; margin: 0 auto; padding: 0 24px; }
        .text-primary { color: var(--primary); }
        .gradient-text {
            background: linear-gradient(135deg, #0f172a 30%, #d97706 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* HEADER / NAVBAR VÍVIDA */
        header.site-header {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            padding: 16px 0;
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.03);
            transition: all 0.3s ease;
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand-link {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--text-dark);
        }

        .brand-logo-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 20px;
            box-shadow: 0 6px 18px var(--primary-glow);
        }

        .brand-name {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: var(--text-dark);
        }

        .brand-tagline {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            display: block;
            font-weight: 600;
        }

        nav.nav-menu {
            display: flex;
            align-items: center;
            gap: 28px;
        }

        nav.nav-menu a {
            color: #334155;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: color 0.2s;
        }
        nav.nav-menu a:hover { color: var(--primary); }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-outline-login {
            background: #ffffff;
            border: 1.5px solid #cbd5e1;
            color: var(--text-dark);
            padding: 9px 18px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
        }
        .btn-outline-login:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(217, 119, 6, 0.15);
        }

        .btn-primary-cta {
            background: var(--primary-gradient);
            color: #ffffff;
            padding: 10px 22px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            box-shadow: 0 6px 20px var(--primary-glow);
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary-cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(245, 158, 11, 0.45);
        }

        /* HERO SECTION VÍVIDA */
        section.hero {
            padding: 80px 0 60px 0;
            position: relative;
            text-align: center;
        }

        .hero-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #fef3c7;
            border: 1px solid #fde68a;
            padding: 8px 20px;
            border-radius: 999px;
            font-size: 13px;
            color: #b45309;
            font-weight: 700;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.15);
            animation: pulseSoft 3s infinite;
        }

        @keyframes pulseSoft {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.03); }
        }

        .hero h1 {
            font-size: 56px;
            font-weight: 900;
            line-height: 1.15;
            letter-spacing: -1.5px;
            max-width: 950px;
            margin: 0 auto 24px auto;
            color: var(--text-dark);
        }

        .hero p.lead {
            font-size: 20px;
            color: var(--text-muted);
            max-width: 780px;
            margin: 0 auto 36px auto;
            font-weight: 400;
            line-height: 1.6;
        }

        .hero-buttons {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            margin-bottom: 45px;
            flex-wrap: wrap;
        }

        /* NICHES CHIPS CON COLOR Y VITALIDAD */
        .hero-niches {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 12px;
            max-width: 950px;
            margin: 0 auto;
        }

        .niche-chip {
            padding: 10px 18px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            text-decoration: none;
        }

        .niche-chip.spa { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .niche-chip.nails { background: #fdf2f8; color: #be185d; border: 1px solid #fbcfe8; }
        .niche-chip.barber { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .niche-chip.lashes { background: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe; }
        .niche-chip.tattoo { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .niche-chip.skincare { background: #fff1f2; color: #be123c; border: 1px solid #fecdd3; }

        .niche-chip:hover {
            transform: translateY(-3px) scale(1.04);
            box-shadow: 0 8px 16px rgba(0,0,0,0.08);
        }

        /* FLOATING NOTIFICATION BADGES EN HERO */
        .hero-float-wrapper {
            position: relative;
            max-width: 1050px;
            margin: 0 auto;
        }

        .floating-badge {
            position: absolute;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 12px 18px;
            box-shadow: 0 15px 35px -5px rgba(15, 23, 42, 0.12);
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 10;
            animation: floatSlow 5s ease-in-out infinite;
        }
        .floating-badge.badge-left {
            top: 20px;
            left: -20px;
        }
        .floating-badge.badge-right {
            bottom: 30px;
            right: -20px;
            animation-delay: 2.5s;
        }

        @keyframes floatSlow {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        .floating-badge-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        /* SHOWCASE SECTION */
        .preview-section {
            padding: 40px 0 80px 0;
            position: relative;
        }

        .showcase-card {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 32px;
            padding: 56px;
            box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.08);
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 50px;
            align-items: center;
            position: relative;
            overflow: hidden;
        }

        .showcase-card::before {
            content: '';
            position: absolute;
            top: -100px;
            right: -100px;
            width: 350px;
            height: 350px;
            background: radial-gradient(circle, rgba(245, 158, 11, 0.15) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .showcase-info h2 {
            font-size: 36px;
            font-weight: 900;
            margin-bottom: 16px;
            color: var(--text-dark);
            letter-spacing: -0.5px;
        }

        .showcase-info p {
            color: var(--text-slate);
            font-size: 16px;
            margin-bottom: 24px;
        }

        .feature-bullets {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-bottom: 32px;
        }

        .feature-bullets li {
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 15px;
            color: var(--text-slate);
        }

        .feature-bullets li i {
            color: #d97706;
            font-size: 15px;
            background: #fef3c7;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        /* MOCKUP PHONE VIBRANTE */
        .mockup-wrapper {
            display: flex;
            justify-content: center;
        }

        .mockup-phone {
            width: 320px;
            background: #ffffff;
            border: 10px solid #0f172a;
            border-radius: 44px;
            padding: 16px 12px;
            box-shadow: 0 30px 70px -10px rgba(15, 23, 42, 0.25), 0 0 30px rgba(245, 158, 11, 0.2);
            position: relative;
        }

        .mockup-phone::before {
            content: '';
            position: absolute;
            top: 10px;
            left: 50%;
            transform: translateX(-50%);
            width: 85px;
            height: 18px;
            background: #0f172a;
            border-radius: 12px;
            z-index: 10;
        }

        .phone-screen {
            background: #f8fafc;
            border-radius: 30px;
            padding: 24px 14px 16px 14px;
            color: var(--text-dark);
            font-size: 12px;
            border: 1px solid #e2e8f0;
        }

        .phone-brand {
            text-align: center;
            padding-bottom: 12px;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 14px;
        }
        .phone-brand h4 { font-size: 15px; font-weight: 800; color: #d97706; }
        .phone-brand span { font-size: 11px; color: var(--text-muted); font-weight: 500; }

        .phone-step {
            background: #ffffff;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 10px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }
        .phone-step strong { display: block; font-size: 11px; margin-bottom: 6px; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; }

        .phone-specialist {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .phone-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 700;
            font-size: 12px;
        }

        .phone-hours {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
            margin-top: 6px;
        }
        .hour-pill {
            background: #f1f5f9;
            padding: 6px;
            border-radius: 8px;
            text-align: center;
            font-size: 10px;
            font-weight: 600;
            color: var(--text-slate);
        }
        .hour-pill.selected {
            background: var(--primary-gradient);
            color: #ffffff;
            font-weight: 700;
            box-shadow: 0 2px 8px var(--primary-glow);
        }

        .btn-phone-reserve {
            background: var(--primary-gradient);
            color: #ffffff;
            font-weight: 800;
            padding: 10px;
            border-radius: 10px;
            text-align: center;
            margin-top: 12px;
            font-size: 12px;
            box-shadow: 0 4px 14px var(--primary-glow);
        }

        /* GRID SECTIONS (FEATURES) LUMINOSAS */
        section.features {
            padding: 90px 0;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
        }

        .section-header {
            text-align: center;
            max-width: 720px;
            margin: 0 auto 55px auto;
        }

        .section-header h2 {
            font-size: 40px;
            font-weight: 900;
            letter-spacing: -0.5px;
            margin-bottom: 14px;
            color: var(--text-dark);
        }

        .section-header p {
            color: var(--text-muted);
            font-size: 17px;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 28px;
        }

        .feature-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 24px;
            padding: 34px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.02);
            position: relative;
        }
        .feature-card:hover {
            border-color: #cbd5e1;
            transform: translateY(-6px);
            box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.08);
        }

        .feature-icon-box {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 22px;
        }

        .feature-card h3 {
            font-size: 21px;
            font-weight: 800;
            margin-bottom: 10px;
            color: var(--text-dark);
        }

        .feature-card p {
            color: var(--text-slate);
            font-size: 14px;
            line-height: 1.6;
        }

        /* SECTORES VIBRANTES */
        section.sectors {
            padding: 90px 0;
            background: #f8fafc;
        }

        .sectors-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
        }

        .sector-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 28px;
            transition: all 0.25s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.02);
        }
        .sector-box:hover {
            border-color: var(--primary);
            transform: translateY(-4px);
            box-shadow: 0 14px 30px -8px rgba(15, 23, 42, 0.08);
        }
        .sector-box h4 {
            font-size: 19px;
            font-weight: 800;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-dark);
        }
        .sector-box p { font-size: 14px; color: var(--text-muted); line-height: 1.5; }

        /* PLANES Y PRECIOS LUMINOSOS */
        section.pricing {
            padding: 100px 0;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            position: relative;
        }

        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 32px;
            max-width: 1100px;
            margin: 0 auto;
        }

        .plan-card {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 28px;
            padding: 42px 34px;
            display: flex;
            flex-direction: column;
            position: relative;
            transition: all 0.3s;
            box-shadow: 0 10px 30px -10px rgba(15, 23, 42, 0.04);
        }
        .plan-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.12);
        }

        .plan-card.featured {
            border: 2px solid #f59e0b;
            background: linear-gradient(180deg, #ffffff 0%, #fffdfa 100%);
            box-shadow: 0 20px 50px -10px rgba(245, 158, 11, 0.2);
        }

        .plan-badge {
            position: absolute;
            top: -14px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--primary-gradient);
            color: #ffffff;
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 6px 18px;
            border-radius: 999px;
            box-shadow: 0 4px 14px var(--primary-glow);
        }

        .plan-header {
            margin-bottom: 24px;
            text-align: center;
        }

        .plan-title {
            font-size: 26px;
            font-weight: 800;
            margin-bottom: 8px;
            color: var(--text-dark);
        }

        .plan-desc {
            font-size: 14px;
            color: var(--text-muted);
            min-height: 42px;
        }

        .plan-price {
            margin: 20px 0;
            text-align: center;
        }

        .plan-price .currency {
            font-size: 26px;
            font-weight: 700;
            color: #d97706;
            vertical-align: top;
        }

        .plan-price .amount {
            font-size: 56px;
            font-weight: 900;
            line-height: 1;
            letter-spacing: -1px;
            color: var(--text-dark);
        }

        .plan-price .period {
            font-size: 15px;
            color: var(--text-muted);
            margin-left: 4px;
            font-weight: 600;
        }

        .plan-features {
            list-style: none;
            margin: 24px 0 32px 0;
            display: flex;
            flex-direction: column;
            gap: 14px;
            flex-grow: 1;
        }

        .plan-features li {
            font-size: 14px;
            color: var(--text-slate);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .plan-features li i.check {
            color: #10b981;
            font-size: 15px;
            background: #ecfdf5;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .plan-features li i.cross {
            color: #94a3b8;
            font-size: 13px;
            background: #f1f5f9;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .plan-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .btn-plan-buy {
            background: var(--primary-gradient);
            color: #ffffff;
            border: none;
            padding: 15px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 14px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 16px var(--primary-glow);
        }
        .btn-plan-buy:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.45);
        }

        .btn-plan-renew {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            color: var(--text-slate);
            padding: 12px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-plan-renew:hover {
            background: #f1f5f9;
            color: var(--text-dark);
            border-color: #94a3b8;
        }

        /* MODAL DE CHECKOUT LUMINOSO Y MODERNO */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow-y: auto;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 28px;
            width: 100%;
            max-width: 680px;
            box-shadow: 0 30px 80px -10px rgba(15, 23, 42, 0.25);
            position: relative;
            padding: 38px;
            max-height: 92vh;
            overflow-y: auto;
            animation: modalFadeIn 0.3s ease-out;
            color: var(--text-dark);
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-close {
            position: absolute;
            top: 22px;
            right: 22px;
            background: #f1f5f9;
            border: none;
            color: #64748b;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        .modal-close:hover { background: #fee2e2; color: #ef4444; }

        .modal-header {
            margin-bottom: 22px;
        }
        .modal-header h3 {
            font-size: 24px;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 4px;
        }
        .modal-header p {
            color: var(--text-muted);
            font-size: 14px;
        }

        /* STEPPER */
        .steps-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
        }

        .step-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 800;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .step-item.active {
            color: #d97706;
        }
        .step-item.completed {
            color: #10b981;
        }

        .step-badge-num {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            color: inherit;
        }
        .step-item.active .step-badge-num {
            background: #fef3c7;
            color: #d97706;
        }
        .step-item.completed .step-badge-num {
            background: #10b981;
            color: #fff;
        }

        .step-arrow-divider {
            color: #cbd5e1;
            font-size: 11px;
        }

        /* CONTRATO SCROLL BOX */
        .contract-terms-box {
            background: #fffdfa;
            border: 1px solid #fde68a;
            border-radius: 14px;
            padding: 18px 20px;
            max-height: 180px;
            overflow-y: auto;
            font-size: 13px;
            color: var(--text-slate);
            line-height: 1.6;
            margin: 18px 0;
        }
        .contract-terms-box::-webkit-scrollbar { width: 6px; }
        .contract-terms-box::-webkit-scrollbar-thumb {
            background: #f59e0b;
            border-radius: 10px;
        }
        .contract-terms-box h4 {
            color: #b45309;
            font-size: 13px;
            font-weight: 800;
            margin: 12px 0 4px 0;
        }
        .contract-terms-box h4:first-child { margin-top: 0; }
        .contract-terms-box ul { padding-left: 20px; margin-bottom: 8px; }

        .checkbox-contract-accept {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin: 18px 0 24px 0;
            background: #fef3c7;
            border: 1px solid #fde68a;
            padding: 14px;
            border-radius: 12px;
        }
        .checkbox-contract-accept input[type="checkbox"] {
            margin-top: 3px;
            width: 18px;
            height: 18px;
            accent-color: #d97706;
            cursor: pointer;
        }
        .checkbox-contract-accept label {
            font-size: 13px;
            color: #92400e;
            cursor: pointer;
            line-height: 1.45;
        }

        .step-panel { display: none; }
        .step-panel.active { display: block; }

        /* TABS MÉTODOS DE PAGO */
        .pay-tabs {
            display: flex;
            gap: 8px;
            background: #f1f5f9;
            padding: 6px;
            border-radius: 12px;
            margin-bottom: 18px;
            overflow-x: auto;
        }

        .tab-btn {
            background: transparent;
            border: none;
            color: #64748b;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .tab-btn.active {
            background: #ffffff;
            color: #d97706;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }

        .pay-tab-content {
            display: none;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 22px;
        }
        .pay-tab-content.active { display: block; }

        .data-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
        }
        .data-row:last-child { border-bottom: none; }
        .data-row strong { color: #64748b; font-weight: 600; }
        .data-row span { color: var(--text-dark); font-weight: 700; font-family: monospace; font-size: 14px; }

        .btn-copy-small {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #d97706;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            margin-left: 8px;
            transition: all 0.2s;
        }
        .btn-copy-small:hover {
            background: #fef3c7;
            border-color: #f59e0b;
        }

        /* FORM EN MODAL */
        .form-grid-modal {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }

        .form-group-modal {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-group-modal.full { grid-column: span 2; }

        .form-group-modal label {
            font-size: 12px;
            color: #475569;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-input-modal {
            background: #f8fafc;
            border: 1.5px solid #cbd5e1;
            border-radius: 10px;
            padding: 11px 14px;
            color: var(--text-dark);
            font-size: 14px;
            font-family: inherit;
            transition: all 0.2s;
        }
        .form-input-modal:focus {
            outline: none;
            border-color: #d97706;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.15);
        }

        .btn-next-step {
            background: var(--primary-gradient);
            color: #ffffff;
            border: none;
            padding: 15px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 800;
            width: 100%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.2s;
            box-shadow: 0 4px 16px var(--primary-glow);
        }
        .btn-next-step:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.45);
        }

        .btn-whatsapp-send {
            background: var(--whatsapp-gradient);
            color: #ffffff;
            border: none;
            padding: 16px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 800;
            width: 100%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.2s;
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.35);
        }
        .btn-whatsapp-send:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.5);
        }

        .btn-prev-step {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 16px;
            transition: color 0.2s;
        }
        .btn-prev-step:hover {
            color: var(--primary);
        }

        .accepted-contract-badge {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }

        /* FOOTER LUMINOSO Y MODERNO */
        footer.site-footer {
            background: #0f172a;
            color: #cbd5e1;
            padding: 70px 0 35px 0;
            font-size: 14px;
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1.5fr;
            gap: 40px;
            margin-bottom: 45px;
        }

        .footer-col h4 {
            color: #ffffff;
            font-size: 16px;
            font-weight: 800;
            margin-bottom: 18px;
        }

        .footer-col ul {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .footer-col a {
            color: #94a3b8;
            text-decoration: none;
            transition: color 0.2s;
        }
        .footer-col a:hover { color: #f59e0b; }

        .footer-bottom {
            border-top: 1px solid rgba(255,255,255,0.08);
            padding-top: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            color: #64748b;
        }

        /* RESPONSIVE */
        @media (max-width: 900px) {
            nav.nav-menu { display: none; }
            .hero h1 { font-size: 40px; }
            .showcase-card { grid-template-columns: 1fr; padding: 32px; }
            .footer-grid { grid-template-columns: 1fr; gap: 30px; }
            .form-grid-modal { grid-template-columns: 1fr; }
            .form-group-modal.full { grid-column: span 1; }
            .floating-badge { display: none; }
        }
    </style>
</head>
<body>

    <!-- HEADER / NAVBAR -->
    <header class="site-header">
        <div class="container">
            <div class="header-content">
                <a href="index.php" class="brand-link">
                    <div class="brand-logo-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div>
                        <span class="brand-name"><?= htmlspecialchars($config_saas['nombre_app']) ?></span>
                        <span class="brand-tagline">Citas & Gestión Inteligente</span>
                    </div>
                </a>

                <nav class="nav-menu">
                    <a href="#beneficios">Beneficios</a>
                    <a href="#como-funciona">Cómo Funciona</a>
                    <a href="#sectores">Sectores</a>
                    <a href="#planes">Planes & Precios</a>
                </nav>

                <div class="header-actions">
                    <a href="login.php" class="btn-outline-login">
                        <i class="fas fa-arrow-right-to-bracket"></i> Iniciar Sesión
                    </a>
                    <a href="#planes" class="btn-primary-cta">
                        <i class="fas fa-bolt"></i> Adquirir Plan
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- HERO SECTION LUMINOSA & VÍVIDA -->
    <section class="hero">
        <div class="container">
            <div class="hero-float-wrapper">
                <!-- NOTIFICACIÓN FLOTANTE IZQUIERDA -->
                <div class="floating-badge badge-left">
                    <div class="floating-badge-icon" style="background:#ecfdf5; color:#059669;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div style="text-align:left;">
                        <div style="font-size:12px; font-weight:800; color:#0f172a;">¡Nueva cita confirmada!</div>
                        <div style="font-size:11px; color:#64748b;">Manicura Rusa · Hoy 3:30 PM</div>
                    </div>
                </div>

                <!-- NOTIFICACIÓN FLOTANTE DERECHA -->
                <div class="floating-badge badge-right">
                    <div class="floating-badge-icon" style="background:#fef3c7; color:#d97706;">
                        <i class="fas fa-star"></i>
                    </div>
                    <div style="text-align:left;">
                        <div style="font-size:12px; font-weight:800; color:#0f172a;">4.9 / 5.0 Calificación</div>
                        <div style="font-size:11px; color:#64748b;">Más de 3,500 clientes felices</div>
                    </div>
                </div>

                <div class="hero-pill">
                    <i class="fas fa-sparkles text-primary"></i> Plataforma SaaS de Citas y Reservas Online
                </div>
                <h1>
                    Automatiza las citas y clientes de tu <span class="gradient-text">Spa, Salón de Uñas, Peluquería o Barbería</span>
                </h1>
                <p class="lead">
                    Dile adiós a agendar por chats enredados. Tu propio enlace de reservas 24/7 para Instagram y WhatsApp sin descargas de apps para tus clientes.
                </p>

                <div class="hero-buttons">
                    <a href="#planes" class="btn-primary-cta" style="padding: 15px 34px; font-size: 16px;">
                        <i class="fas fa-rocket"></i> Ver Planes y Precios Mensuales
                    </a>
                    <a href="login.php" class="btn-outline-login" style="padding: 15px 28px; font-size: 16px;">
                        <i class="fas fa-columns"></i> Ya tengo cuenta (Iniciar Sesión)
                    </a>
                    <a href="https://wa.me/<?= $config_saas['whatsapp_ventas'] ?>?text=<?= urlencode('Hola! Quisiera más información sobre la plataforma de citas AlCorte Pro') ?>" target="_blank" class="btn-outline-login" style="color:#059669; border-color:#a7f3d0; background:#f0fdf4; padding: 15px 24px; font-size: 16px;">
                        <i class="fab fa-whatsapp"></i> Hablar con un Asesor
                    </a>
                </div>

                <!-- CATEGORÍAS VÍVIDAS -->
                <div class="hero-niches">
                    <a href="#sectores" class="niche-chip spa"><i class="fas fa-spa"></i> Spas & Masajes</a>
                    <a href="#sectores" class="niche-chip nails"><i class="fas fa-hand-sparkles"></i> Manicuristas & Uñas</a>
                    <a href="#sectores" class="niche-chip barber"><i class="fas fa-cut"></i> Barberías & Peluquerías</a>
                    <a href="#sectores" class="niche-chip lashes"><i class="fas fa-eye"></i> Cejas & Pestañas</a>
                    <a href="#sectores" class="niche-chip tattoo"><i class="fas fa-paint-brush"></i> Estudios de Tatuajes</a>
                    <a href="#sectores" class="niche-chip skincare"><i class="fas fa-heartbeat"></i> Estética & Skincare</a>
                </div>
            </div>
        </div>
    </section>

    <!-- LIVE DEMO / SHOWCASE SECTION -->
    <section class="preview-section" id="como-funciona">
        <div class="container">
            <div class="showcase-card">
                <div class="showcase-info">
                    <span class="hero-pill" style="margin-bottom:14px;"><i class="fas fa-link"></i> Enlace Único de Marca</span>
                    <h2>Así reservan tus clientes: en menos de 60 segundos</h2>
                    <p>
                        Al contratar tu mes de servicio, tu negocio obtiene un enlace exclusivo y seguro con tu logotipo, tus especialistas y tu catálogo de servicios con precios.
                    </p>

                    <ul class="feature-bullets">
                        <li>
                            <i class="fas fa-bolt"></i>
                            <span><strong>Cero descargas:</strong> Funciona directamente en el navegador del celular de tus clientes.</span>
                        </li>
                        <li>
                            <i class="fas fa-instagram"></i>
                            <span><strong>Pégalo en tu bio de Instagram:</strong> Tus seguidores hacen clic y ven tus turnos libres en tiempo real.</span>
                        </li>
                        <li>
                            <i class="fab fa-whatsapp"></i>
                            <span><strong>Compártelo por WhatsApp:</strong> Tus clientes eligen su horario sin esperas ni llamadas.</span>
                        </li>
                        <li>
                            <i class="fas fa-calendar-check"></i>
                            <span><strong>Agenda coordinada al instante:</strong> Cada reserva cae en tu panel y en el celular del especialista.</span>
                        </li>
                    </ul>

                    <a href="#planes" class="btn-primary-cta">
                        <i class="fas fa-check-circle"></i> Activar mi Negocio Ahora
                    </a>
                </div>

                <!-- PHONE MOCKUP -->
                <div class="mockup-wrapper">
                    <div class="mockup-phone">
                        <div class="phone-screen">
                            <div class="phone-brand">
                                <h4>Tu Negocio / Salón</h4>
                                <span>Citas en tiempo real · 24/7</span>
                            </div>

                            <div class="phone-step">
                                <strong>1. Especialista:</strong>
                                <div class="phone-specialist">
                                    <div class="phone-avatar"><i class="fas fa-user"></i></div>
                                    <div>
                                        <div style="font-weight:800; font-size:12px; color:#0f172a;">Valeria Ramos</div>
                                        <div style="font-size:10px; color:#64748b;">Especialista en Uñas & Spa</div>
                                    </div>
                                </div>
                            </div>

                            <div class="phone-step">
                                <strong>2. Servicio seleccionado:</strong>
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <span style="font-weight:600;">Manicura Rusa Spa</span>
                                    <span style="color:#d97706; font-weight:800;">$20.00</span>
                                </div>
                            </div>

                            <div class="phone-step">
                                <strong>3. Turno Disponible:</strong>
                                <div class="phone-hours">
                                    <div class="hour-pill">10:00 AM</div>
                                    <div class="hour-pill selected">11:30 AM</div>
                                    <div class="hour-pill">02:00 PM</div>
                                </div>
                            </div>

                            <div class="btn-phone-reserve">
                                <i class="fas fa-calendar-check"></i> Confirmar Reserva
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- BENEFICIOS / CARACTERÍSTICAS LUMINOSAS -->
    <section class="features" id="beneficios">
        <div class="container">
            <div class="section-header">
                <h2>Diseñado para hacer crecer tu negocio</h2>
                <p>Todas las herramientas que necesitas para gestionar tu equipo, fidelizar clientes y controlar tus ingresos.</p>
            </div>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon-box" style="background:#fef3c7; color:#d97706;">
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <h3>Agenda por Especialista</h3>
                    <p>Cada barbero, manicurista o terapeuta de tu equipo tiene su horario individual, cálculo de comisiones y agenda digital.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon-box" style="background:#fee2e2; color:#dc2626;">
                        <i class="fas fa-ban"></i>
                    </div>
                    <h3>Cero Solapamientos de Citas</h3>
                    <p>El sistema bloquea automáticamente turnos ocupados y descansos para que ningún cliente se quede esperando.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon-box" style="background:#ecfdf5; color:#059669;">
                        <i class="fas fa-coins"></i>
                    </div>
                    <h3>Tasa Oficial al Día (BCV / USD)</h3>
                    <p>Configura tus precios en dólares y el sistema calculará en tiempo real el monto exacto en moneda local con la tasa oficial.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon-box" style="background:#f5f3ff; color:#7c3aed;">
                        <i class="fas fa-crown"></i>
                    </div>
                    <h3>Club VIP & Fidelización</h3>
                    <p>Acumula puntos para tus clientes frecuentes y prémialos con descuentos en sus próximas visitas para que siempre regresen.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon-box" style="background:#eff6ff; color:#2563eb;">
                        <i class="fas fa-box-open"></i>
                    </div>
                    <h3>Catálogo de Productos</h3>
                    <p>Vende productos de cuidado personal, tratamientos o accesorios complementarios para aumentar el ticket promedio.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon-box" style="background:#fff1f2; color:#e11d48;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h3>Caja y Estadísticas Diarias</h3>
                    <p>Conoce exactamente cuánto ingresó hoy en efectivo, pago móvil o Zelle, y el rendimiento de cada colaborador.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- SECTORES -->
    <section class="sectors" id="sectores">
        <div class="container">
            <div class="section-header">
                <h2>Perfecto para cualquier servicio por cita</h2>
                <p>Nuestra plataforma se adapta a las necesidades y dinámica de tu especialidad.</p>
            </div>

            <div class="sectors-grid">
                <div class="sector-box">
                    <h4><i class="fas fa-spa" style="color:#059669;"></i> Spas & Centros de Masajes</h4>
                    <p>Cabinas, masajes relajantes, exfoliaciones y terapias corporales organizadas por duración y terapeuta.</p>
                </div>
                <div class="sector-box">
                    <h4><i class="fas fa-hand-sparkles" style="color:#db2777;"></i> Manicuristas & Nails</h4>
                    <p>Sistemas acrílicos, esculpidas, pedicura y semipermanente con tiempos de servicio definidos.</p>
                </div>
                <div class="sector-box">
                    <h4><i class="fas fa-cut" style="color:#2563eb;"></i> Barberías & Peluquerías</h4>
                    <p>Cortes, diseño de barba, colorimetría, secados y tratamientos capilares con turnos rápidos.</p>
                </div>
                <div class="sector-box">
                    <h4><i class="fas fa-eye" style="color:#7c3aed;"></i> Cejas & Pestañas</h4>
                    <p>Lifting, extensiones pelo a pelo, laminado y micropigmentación con control de anticipos.</p>
                </div>
                <div class="sector-box">
                    <h4><i class="fas fa-paint-brush" style="color:#d97706;"></i> Estudios de Tatuajes</h4>
                    <p>Sesiones largas por artista, cotizaciones previas y control de disponibilidad de sillón.</p>
                </div>
                <div class="sector-box">
                    <h4><i class="fas fa-stethoscope" style="color:#0284c7;"></i> Salud & Estética</h4>
                    <p>Consultorios de cosmetología, nutrición, fisioterapia y estética facial con citas privadas.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- PLANES Y PRECIOS LUMINOSOS -->
    <section class="pricing" id="planes">
        <div class="container">
            <div class="section-header">
                <span class="hero-pill"><i class="fas fa-tag"></i> Suscripción Mensual Transparente</span>
                <h2>Elige el plan ideal para tu negocio</h2>
                <p>Sin contratos forzosos a largo plazo. Paga mes a mes y renueva a tu propio ritmo.</p>
            </div>

            <div class="pricing-grid">
                <?php foreach ($planes as $idx => $p): 
                    $is_featured = ($p['nivel'] == 2 || $p['nombre'] === 'Profesional');
                    $precio = number_format((float)$p['precio_mensual'], 0);
                    $max_esp = $p['max_barberos'] == 0 ? 'Especialistas Ilimitados' : ($p['max_barberos'] == 1 ? '1 Especialista' : 'Hasta ' . $p['max_barberos'] . ' Especialistas');
                ?>
                <div class="plan-card <?= $is_featured ? 'featured' : '' ?>">
                    <?php if ($is_featured): ?>
                        <div class="plan-badge">MÁS POPULAR</div>
                    <?php endif; ?>

                    <div class="plan-header">
                        <div class="plan-title"><?= htmlspecialchars($p['nombre']) ?></div>
                        <div class="plan-desc"><?= htmlspecialchars($p['descripcion']) ?></div>
                    </div>

                    <div class="plan-price">
                        <span class="currency">$</span>
                        <span class="amount"><?= $precio ?></span>
                        <span class="period">/mes</span>
                    </div>

                    <ul class="plan-features">
                        <li><i class="fas fa-check check"></i> <strong><?= $max_esp ?></strong></li>
                        <li><i class="fas fa-check check"></i> Enlace propio para reservas de clientes</li>
                        <li><i class="fas fa-check check"></i> Agenda digital en tiempo real</li>
                        <li><i class="fas fa-check check"></i> Cálculo de tasa BCV oficial</li>
                        <li><i class="fas fa-check check"></i> Soporte técnico por WhatsApp</li>

                        <?php if ($p['nivel'] >= 2): ?>
                            <li><i class="fas fa-check check"></i> <strong>Club VIP de Puntos</strong></li>
                            <li><i class="fas fa-check check"></i> Fotos en catálogo de servicios</li>
                            <li><i class="fas fa-check check"></i> Control de comisiones del equipo</li>
                        <?php else: ?>
                            <li><i class="fas fa-times cross"></i> <span style="color:#94a3b8">Club VIP de Puntos</span></li>
                            <li><i class="fas fa-times cross"></i> <span style="color:#94a3b8">Catálogo de productos</span></li>
                        <?php endif; ?>

                        <?php if ($p['nivel'] >= 3): ?>
                            <li><i class="fas fa-check check"></i> <strong>Venta de Productos & Inventario</strong></li>
                            <li><i class="fas fa-check check"></i> Reportes avanzados de rentabilidad</li>
                            <li><i class="fas fa-check check"></i> Soporte prioritario 24/7</li>
                        <?php endif; ?>
                    </ul>

                    <div class="plan-actions">
                        <button type="button" class="btn-plan-buy" onclick="openPaymentModal('<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>', <?= (float)$p['precio_mensual'] ?>, 'nueva')">
                            <i class="fas fa-bolt"></i> Adquirir Plan
                        </button>
                        <button type="button" class="btn-plan-renew" onclick="openPaymentModal('<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>', <?= (float)$p['precio_mensual'] ?>, 'renovacion')">
                            <i class="fas fa-rotate"></i> Renovar Mes Existente
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- MODAL DE CHECKOUT: PASO 1 (DATOS Y CONTRATO) -> PASO 2 (PAGO Y REPORTE) -->
    <div class="modal-overlay" id="paymentModal">
        <div class="modal-box">
            <button class="modal-close" onclick="closePaymentModal()"><i class="fas fa-times"></i></button>

            <!-- ENCABEZADO Y STEPPER -->
            <div class="modal-header">
                <span class="hero-pill" style="font-size:11px; margin-bottom:8px; padding:4px 14px;" id="modalActionBadge">NUEVA SUSCRIPCIÓN</span>
                <h3 id="modalPlanTitle">Plan Profesional ($30/mes)</h3>
                <p id="modalStepSubtitle">Completa los datos de tu negocio y revisa los términos del servicio antes de pagar.</p>
            </div>

            <!-- INDICADOR DE PASOS -->
            <div class="steps-bar">
                <div class="step-item active" id="badgeStep1">
                    <span class="step-badge-num" id="badgeNum1">1</span>
                    <span>Datos & Contrato</span>
                </div>
                <i class="fas fa-chevron-right step-arrow-divider"></i>
                <div class="step-item" id="badgeStep2">
                    <span class="step-badge-num" id="badgeNum2">2</span>
                    <span>Pago & Reporte</span>
                </div>
            </div>

            <!-- ===================== PASO 1: DATOS Y CONTRATO ===================== -->
            <div id="stepPanel1" class="step-panel active">
                <div class="form-grid-modal">
                    <div class="form-group-modal">
                        <label>Nombre del Negocio / Marca *</label>
                        <input type="text" id="f_negocio" class="form-input-modal" placeholder="Ej. Spa Armonía / Nails Studio 54" required>
                    </div>
                    <div class="form-group-modal">
                        <label>Sector / Especialidad *</label>
                        <select id="f_categoria" class="form-input-modal" required>
                            <option value="Spa & Masajes">Spa & Masajes</option>
                            <option value="Salón de Uñas / Manicura">Salón de Uñas / Manicura</option>
                            <option value="Peluquería / Salón de Belleza">Peluquería / Salón de Belleza</option>
                            <option value="Barbería">Barbería</option>
                            <option value="Cejas & Pestañas">Cejas & Pestañas</option>
                            <option value="Estética & Skincare">Estética & Skincare</option>
                            <option value="Estudio de Tatuajes">Estudio de Tatuajes</option>
                            <option value="Otro Servicio por Cita">Otro Servicio por Cita</option>
                        </select>
                    </div>
                    <div class="form-group-modal">
                        <label>Nombre del Responsable / Titular *</label>
                        <input type="text" id="f_titular" class="form-input-modal" placeholder="Tu nombre y apellido" required>
                    </div>
                    <div class="form-group-modal">
                        <label>WhatsApp de Contacto *</label>
                        <input type="tel" id="f_telefono" class="form-input-modal" placeholder="Ej. 0412 1234567" required>
                    </div>
                    <div class="form-group-modal full" style="grid-column: span 2; margin-top: 6px;">
                        <label style="display:flex; justify-content:space-between; align-items:center;">
                            <span><i class="fas fa-ticket-alt" style="color:#d97706;"></i> ¿Fuiste visitado por un/a Promotor/a? (Código de Invitación)</span>
                            <span style="font-size:11px; color:#10b981; font-weight:700;"><i class="fas fa-percent"></i> 10% Descuento Especial</span>
                        </label>
                        <div style="display:flex; gap:8px;">
                            <input type="text" id="f_codigo_promotor" class="form-input-modal" placeholder="Ej. VALERIA10, CARLOS10 (Déjalo vacío si entraste directo)" style="text-transform:uppercase; font-weight:700; letter-spacing:1px;" oninput="this.value = this.value.toUpperCase()">
                            <button type="button" class="btn-check-code" onclick="validarCodigoPromotor()" style="background:#0f172a; color:#fff; border:none; padding:10px 18px; border-radius:10px; font-weight:700; font-size:12px; cursor:pointer; white-space:nowrap; transition:all 0.2s;">
                                Aplicar Código
                            </button>
                        </div>
                        <div id="promo_feedback" style="display:none; margin-top:8px; font-size:12px; padding:10px 14px; border-radius:10px;"></div>
                        <small style="color:#64748b; font-size:11px; margin-top:4px; display:block;">
                            * El 10% de descuento aplica exclusivamente con el código de invitación entregado por un/a promotor/a en campo. Si entraste directo a la página web sin promotor, se aplica la tarifa estándar.
                        </small>
                    </div>
                </div>

                <!-- CAJA DE LECTURA DE TÉRMINOS DEL CONTRATO -->
                <div class="contract-terms-box">
                    <h4><i class="fas fa-file-contract text-primary"></i> Contrato de Prestación de Servicios SaaS</h4>
                    <p>El presente acuerdo regula el uso de la plataforma digital para la gestión de citas y clientes en la nube:</p>

                    <h4>1. Objeto del Servicio y Enlace de Reservas</h4>
                    <p>Al contratar tu suscripción, se habilita una instancia en la nube con un enlace exclusivo cifrado (token digital) para que los clientes de tu negocio reserven turnos en tiempo real las 24 horas del día sin necesidad de instalar apps.</p>

                    <h4>2. Modalidad de Pago y Vigencia Mensual</h4>
                    <ul>
                        <li>El servicio opera bajo la modalidad de <strong>mensualidad anticipada de 30 días continuos</strong> a partir de la confirmación de pago.</li>
                        <li>Las renovaciones se procesan reportando el comprobante de pago antes de la fecha de vencimiento.</li>
                    </ul>

                    <h4>3. Privacidad y Propiedad de la Información</h4>
                    <p>Toda la base de datos de tus clientes, números de teléfono, historial de citas, ingresos y comisiones pertenecen exclusivamente a tu negocio. La plataforma garantiza estricta confidencialidad.</p>

                    <h4>4. Soporte Técnico y Continuidad</h4>
                    <p>Se garantiza alta disponibilidad del servidor, copias de seguridad continuas y asistencia técnica directa vía WhatsApp.</p>
                </div>

                <!-- CHECKBOX OBLIGATORIO DE ACEPTACIÓN -->
                <div class="checkbox-contract-accept">
                    <input type="checkbox" id="f_acepto_contrato" required>
                    <label for="f_acepto_contrato">
                        <strong>Declaro que he leído y acepto expresamente el Contrato de Servicio</strong> para la activación/renovación mensual de la plataforma en mi negocio.
                    </label>
                </div>

                <button type="button" class="btn-next-step" onclick="proceedToPaymentStep()">
                    <span>Aceptar Contrato y Continuar al Pago</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </div>

            <!-- ===================== PASO 2: CUENTAS DE PAGO Y REPORTE ===================== -->
            <div id="stepPanel2" class="step-panel">
                <button type="button" class="btn-prev-step" onclick="goToStep(1)">
                    <i class="fas fa-arrow-left"></i> Volver a revisar datos / contrato
                </button>

                <div class="accepted-contract-badge">
                    <i class="fas fa-check-circle" style="font-size:16px;"></i>
                    <div>
                        <strong>Contrato Aceptado Digitalmente</strong> por <span id="lblResponsable"></span>
                    </div>
                </div>

                <!-- TABS MÉTODOS DE PAGO -->
                <div class="pay-tabs">
                    <button type="button" class="tab-btn active" onclick="switchTab('pm', this)"><i class="fas fa-mobile-alt"></i> Pago Móvil</button>
                    <button type="button" class="tab-btn" onclick="switchTab('zelle', this)"><i class="fas fa-dollar-sign"></i> Zelle</button>
                    <button type="button" class="tab-btn" onclick="switchTab('transf', this)"><i class="fas fa-university"></i> Transferencia</button>
                    <button type="button" class="tab-btn" onclick="switchTab('binance', this)"><i class="fab fa-bitcoin"></i> Binance</button>
                </div>

                <!-- TAB PAGO MÓVIL -->
                <div id="tab-pm" class="pay-tab-content active">
                    <div class="data-row">
                        <strong>Banco:</strong>
                        <span><?= htmlspecialchars($config_saas['pago_movil']['banco']) ?></span>
                    </div>
                    <div class="data-row">
                        <strong>Teléfono:</strong>
                        <div>
                            <span><?= htmlspecialchars($config_saas['pago_movil']['telefono']) ?></span>
                            <button type="button" class="btn-copy-small" onclick="copyText('<?= htmlspecialchars($config_saas['pago_movil']['telefono'], ENT_QUOTES) ?>')">Copiar</button>
                        </div>
                    </div>
                    <div class="data-row">
                        <strong>Cédula / RIF:</strong>
                        <div>
                            <span><?= htmlspecialchars($config_saas['pago_movil']['ci_rif']) ?></span>
                            <button type="button" class="btn-copy-small" onclick="copyText('<?= htmlspecialchars($config_saas['pago_movil']['ci_rif'], ENT_QUOTES) ?>')">Copiar</button>
                        </div>
                    </div>
                    <div class="data-row">
                        <strong>Titular:</strong>
                        <span><?= htmlspecialchars($config_saas['pago_movil']['titular']) ?></span>
                    </div>
                </div>

                <!-- TAB ZELLE -->
                <div id="tab-zelle" class="pay-tab-content">
                    <div class="data-row">
                        <strong>Correo Zelle:</strong>
                        <div>
                            <span><?= htmlspecialchars($config_saas['zelle']['email']) ?></span>
                            <button type="button" class="btn-copy-small" onclick="copyText('<?= htmlspecialchars($config_saas['zelle']['email'], ENT_QUOTES) ?>')">Copiar</button>
                        </div>
                    </div>
                    <div class="data-row">
                        <strong>Titular:</strong>
                        <span><?= htmlspecialchars($config_saas['zelle']['titular']) ?></span>
                    </div>
                </div>

                <!-- TAB TRANSFERENCIA -->
                <div id="tab-transf" class="pay-tab-content">
                    <div class="data-row">
                        <strong>Banco:</strong>
                        <span><?= htmlspecialchars($config_saas['transferencia']['banco']) ?></span>
                    </div>
                    <div class="data-row">
                        <strong>N° de Cuenta:</strong>
                        <div>
                            <span><?= htmlspecialchars($config_saas['transferencia']['cuenta']) ?></span>
                            <button type="button" class="btn-copy-small" onclick="copyText('<?= htmlspecialchars($config_saas['transferencia']['cuenta'], ENT_QUOTES) ?>')">Copiar</button>
                        </div>
                    </div>
                    <div class="data-row">
                        <strong>RIF:</strong>
                        <span><?= htmlspecialchars($config_saas['transferencia']['rif']) ?></span>
                    </div>
                    <div class="data-row">
                        <strong>Titular:</strong>
                        <span><?= htmlspecialchars($config_saas['transferencia']['titular']) ?></span>
                    </div>
                </div>

                <!-- TAB BINANCE -->
                <div id="tab-binance" class="pay-tab-content">
                    <div class="data-row">
                        <strong>Binance Pay ID:</strong>
                        <div>
                            <span><?= htmlspecialchars($config_saas['binance']['pay_id']) ?></span>
                            <button type="button" class="btn-copy-small" onclick="copyText('<?= htmlspecialchars($config_saas['binance']['pay_id'], ENT_QUOTES) ?>')">Copiar</button>
                        </div>
                    </div>
                    <div class="data-row">
                        <strong>USDT (Red TRC20):</strong>
                        <div>
                            <span style="font-size:11px;"><?= htmlspecialchars($config_saas['binance']['usdt_trc20']) ?></span>
                            <button type="button" class="btn-copy-small" onclick="copyText('<?= htmlspecialchars($config_saas['binance']['usdt_trc20'], ENT_QUOTES) ?>')">Copiar</button>
                        </div>
                    </div>
                </div>

                <!-- FORMULARIO DE DETALLES DE PAGO -->
                <form id="reportPaymentForm" onsubmit="sendReportToWhatsApp(event)">
                    <input type="hidden" id="f_plan" value="Profesional">
                    <input type="hidden" id="f_precio" value="30">
                    <input type="hidden" id="f_precio_base" value="30">
                    <input type="hidden" id="f_tipo_solicitud" value="Nueva Suscripción">

                    <div class="form-grid-modal">
                        <div class="form-group-modal">
                            <label>Método de Pago Utilizado *</label>
                            <select id="f_metodo" class="form-input-modal" required>
                                <option value="Pago Móvil">Pago Móvil</option>
                                <option value="Zelle">Zelle</option>
                                <option value="Transferencia Bancaria">Transferencia Bancaria</option>
                                <option value="Binance USDT">Binance USDT</option>
                                <option value="Efectivo / Acuerdo directo">Efectivo / Acuerdo directo</option>
                            </select>
                        </div>
                        <div class="form-group-modal">
                            <label>N° Referencia o Comprobante *</label>
                            <input type="text" id="f_referencia" class="form-input-modal" placeholder="Ej. 984512 o correo Zelle" required>
                        </div>
                    </div>

                    <button type="submit" class="btn-whatsapp-send">
                        <i class="fab fa-whatsapp" style="font-size:20px;"></i> Enviar Reporte de Pago y Contrato a WhatsApp
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- FOOTER ELEGANTE -->
    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-col">
                    <a href="index.php" class="brand-link" style="margin-bottom:14px;">
                        <div class="brand-logo-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div>
                            <span class="brand-name" style="color:#ffffff;"><?= htmlspecialchars($config_saas['nombre_app']) ?></span>
                        </div>
                    </a>
                    <p style="font-size:13px; line-height:1.6; margin-top:8px; color:#94a3b8;">
                        La solución tecnológica integral de reservas y administración para salones de belleza, spas, peluquerías, manicuristas, barberías y estudios de estética.
                    </p>
                </div>

                <div class="footer-col">
                    <h4>Plataforma</h4>
                    <ul>
                        <li><a href="#beneficios">Beneficios</a></li>
                        <li><a href="#como-funciona">Cómo Funciona</a></li>
                        <li><a href="#sectores">Sectores</a></li>
                        <li><a href="#planes">Planes y Precios</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h4>Acceso</h4>
                    <ul>
                        <li><a href="login.php">Iniciar Sesión</a></li>
                        <li><a href="#planes">Ver Planes Disponibles</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h4>Atención al Cliente</h4>
                    <p style="font-size:13px; margin-bottom:12px; color:#94a3b8;">¿Tienes dudas o necesitas un plan a tu medida? Escríbenos directamente:</p>
                    <a href="https://wa.me/<?= $config_saas['whatsapp_ventas'] ?>" target="_blank" class="btn-outline-login" style="color:#10b981; border-color:#059669; font-size:13px; background:#0f172a;">
                        <i class="fab fa-whatsapp"></i> WhatsApp: <?= htmlspecialchars($config_saas['whatsapp_display']) ?>
                    </a>
                </div>
            </div>

            <div class="footer-bottom">
                <div>&copy; <?= date('Y') ?> <?= htmlspecialchars($config_saas['nombre_app']) ?>. Todos los derechos reservados.</div>
                <div>Software en la nube para citas y gestión de negocios.</div>
            </div>
        </div>
    </footer>

    <!-- JAVASCRIPT LOGIC -->
    <script>
        const WA_NUMBER = "<?= $config_saas['whatsapp_ventas'] ?>";

        let promoData = null;

        function openPaymentModal(planNombre, planPrecio, tipo) {
            document.getElementById('f_plan').value = planNombre;
            document.getElementById('f_precio').value = planPrecio;
            document.getElementById('f_precio_base').value = planPrecio;

            // Resetear promo
            promoData = null;
            const codeInput = document.getElementById('f_codigo_promotor');
            if (codeInput) codeInput.value = '';
            const fb = document.getElementById('promo_feedback');
            if (fb) { fb.style.display = 'none'; fb.innerHTML = ''; }

            const isRenovacion = (tipo === 'renovacion');
            document.getElementById('f_tipo_solicitud').value = isRenovacion ? 'Renovación de Mes' : 'Nueva Suscripción';
            document.getElementById('modalActionBadge').textContent = isRenovacion ? 'RENOVACIÓN DE MES' : 'NUEVA SUSCRIPCIÓN';
            document.getElementById('modalPlanTitle').textContent = `Plan ${planNombre} ($${planPrecio}/mes)`;

            // Siempre empezar en el Paso 1 (Datos y Contrato)
            goToStep(1);

            document.getElementById('paymentModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function validarCodigoPromotor() {
            const code = document.getElementById('f_codigo_promotor').value.trim().toUpperCase();
            const fb = document.getElementById('promo_feedback');
            if (!code) {
                promoData = null;
                fb.style.display = 'block';
                fb.style.background = '#fef2f2';
                fb.style.color = '#dc2626';
                fb.style.border = '1px solid #fecaca';
                fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Por favor ingresa el código de invitación de la promotora.';
                updateModalPlanPrice();
                return;
            }

            fb.style.display = 'block';
            fb.style.background = '#f8fafc';
            fb.style.color = '#64748b';
            fb.style.border = '1px solid #e2e8f0';
            fb.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verificando código de promotor...';

            fetch('api/v1/public?action=validar_codigo_promotor&codigo=' + encodeURIComponent(code))
                .then(r => r.json())
                .then(res => {
                    if (res.ok && res.data && res.data.valido) {
                        promoData = res.data;
                        fb.style.background = '#f0fdf4';
                        fb.style.color = '#15803d';
                        fb.style.border = '1px solid #bbf7d0';
                        fb.innerHTML = `<i class="fas fa-check-circle"></i> <strong>¡Código Válido!</strong> Promotor(a): <strong>${res.data.promotor_nombre}</strong> (${res.data.cargo}).<br><span style="font-weight:700">Se ha aplicado un 10% de DESCUENTO en el alquiler de tu servicio.</span>`;
                    } else {
                        promoData = null;
                        fb.style.background = '#fffbeb';
                        fb.style.color = '#b45309';
                        fb.style.border = '1px solid #fde68a';
                        fb.innerHTML = '<i class="fas fa-times-circle"></i> Código no válido o inactivo. Las compras directas aplican la tarifa estándar sin descuento.';
                    }
                    updateModalPlanPrice();
                })
                .catch(() => {
                    promoData = null;
                    fb.style.background = '#fef2f2';
                    fb.style.color = '#dc2626';
                    fb.style.border = '1px solid #fecaca';
                    fb.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Error al verificar el código. Intenta de nuevo.';
                    updateModalPlanPrice();
                });
        }

        function updateModalPlanPrice() {
            const basePrecio = parseFloat(document.getElementById('f_precio_base').value);
            const planNombre = document.getElementById('f_plan').value;

            if (promoData && promoData.valido) {
                const descMonto = basePrecio * 0.10;
                const finalPrecio = (basePrecio - descMonto).toFixed(2);
                document.getElementById('f_precio').value = finalPrecio;
                document.getElementById('modalPlanTitle').innerHTML = `Plan ${planNombre} <span style="text-decoration:line-through;color:#94a3b8;font-size:14px;">$${basePrecio.toFixed(2)}</span> <span style="color:#10b981;font-weight:900;">$${finalPrecio}/mes</span> <span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:6px;font-size:12px;font-weight:700">10% OFF Promotor/a</span>`;
            } else {
                document.getElementById('f_precio').value = basePrecio.toFixed(2);
                document.getElementById('modalPlanTitle').textContent = `Plan ${planNombre} ($${basePrecio.toFixed(2)}/mes)`;
            }
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        function goToStep(step) {
            if (step === 1) {
                document.getElementById('stepPanel1').classList.add('active');
                document.getElementById('stepPanel2').classList.remove('active');

                document.getElementById('badgeStep1').className = 'step-item active';
                document.getElementById('badgeStep2').className = 'step-item';
                document.getElementById('badgeNum1').textContent = '1';
                document.getElementById('modalStepSubtitle').textContent = 'Completa los datos de tu negocio y revisa los términos del servicio antes de pagar.';
            } else if (step === 2) {
                document.getElementById('stepPanel1').classList.remove('active');
                document.getElementById('stepPanel2').classList.add('active');

                document.getElementById('badgeStep1').className = 'step-item completed';
                document.getElementById('badgeNum1').innerHTML = '<i class="fas fa-check"></i>';
                document.getElementById('badgeStep2').className = 'step-item active';
                document.getElementById('modalStepSubtitle').textContent = 'Realiza tu pago en la cuenta de tu preferencia y reporta el comprobante a WhatsApp.';
            }
        }

        function proceedToPaymentStep() {
            const negocio = document.getElementById('f_negocio').value.trim();
            const titular = document.getElementById('f_titular').value.trim();
            const telefono = document.getElementById('f_telefono').value.trim();
            const acepto = document.getElementById('f_acepto_contrato').checked;

            if (!negocio) {
                alert('Por favor ingresa el nombre de tu negocio o marca.');
                document.getElementById('f_negocio').focus();
                return;
            }
            if (!titular) {
                alert('Por favor ingresa tu nombre y apellido como responsable.');
                document.getElementById('f_titular').focus();
                return;
            }
            if (!telefono) {
                alert('Por favor ingresa tu número de WhatsApp de contacto.');
                document.getElementById('f_telefono').focus();
                return;
            }
            if (!acepto) {
                alert('Debes leer y marcar la casilla de aceptación del Contrato de Servicio para poder continuar al pago.');
                document.getElementById('f_acepto_contrato').focus();
                return;
            }

            // Mostrar el nombre del titular en el badge del Paso 2
            document.getElementById('lblResponsable').textContent = `${titular} (${negocio})`;
            goToStep(2);
        }

        function switchTab(tabKey, btnElement) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.pay-tab-content').forEach(c => c.classList.remove('active'));

            btnElement.classList.add('active');
            document.getElementById('tab-' + tabKey).classList.add('active');
        }

        function copyText(txt) {
            navigator.clipboard.writeText(txt).then(() => {
                alert('¡Copiado en el portapapeles: ' + txt);
            });
        }

        function sendReportToWhatsApp(e) {
            e.preventDefault();

            const plan = document.getElementById('f_plan').value;
            const precio = document.getElementById('f_precio').value;
            const tipo = document.getElementById('f_tipo_solicitud').value;
            const negocio = document.getElementById('f_negocio').value.trim();
            const categoria = document.getElementById('f_categoria').value;
            const titular = document.getElementById('f_titular').value.trim();
            const telefono = document.getElementById('f_telefono').value.trim();
            const metodo = document.getElementById('f_metodo').value;
            const referencia = document.getElementById('f_referencia').value.trim();

            if (!referencia) {
                alert('Por favor ingresa el número de referencia o comprobante de tu pago.');
                document.getElementById('f_referencia').focus();
                return;
            }

            let promoLine = '🌐 *Afiliación:* Registro Directo en Web (Tarifa Estándar)\n';
            if (promoData && promoData.valido) {
                const baseP = parseFloat(document.getElementById('f_precio_base').value).toFixed(2);
                promoLine = `🎁 *Código Promotora:* ${promoData.codigo} (${promoData.promotor_nombre})\n` +
                            `🏷️ *Descuento Aplicado:* 10% OFF Promotor en Campo\n` +
                            `💵 *Total con Descuento:* $${precio}/mes (Antes: $${baseP})\n`;
            }

            const mensaje = `✨ *REPORTE DE PAGO & CONTRATO — ALCORTE PRO*\n\n` +
                `📌 *Tipo:* ${tipo}\n` +
                `⭐ *Plan:* ${plan} ($${precio}/mes)\n` +
                promoLine +
                `🏢 *Negocio:* ${negocio}\n` +
                `🏷️ *Categoría:* ${categoria}\n` +
                `👤 *Responsable:* ${titular}\n` +
                `📱 *Teléfono:* ${telefono}\n` +
                `💳 *Método de Pago:* ${metodo}\n` +
                `🔢 *N° Referencia:* ${referencia}\n` +
                `📜 *Contrato de Servicio:* ACEPTADO DIGITALMENTE ✅\n\n` +
                `Adjunto mi comprobante para que por favor validen los fondos y activen el servicio. ¡Muchas gracias!`;

            const waUrl = `https://wa.me/${WA_NUMBER}?text=${encodeURIComponent(mensaje)}`;
            window.open(waUrl, '_blank');
            closePaymentModal();
        }

        // Cerrar modal al hacer clic fuera del contenido
        window.addEventListener('click', function(e) {
            const modal = document.getElementById('paymentModal');
            if (e.target === modal) {
                closePaymentModal();
            }
        });
    </script>
</body>
</html>
