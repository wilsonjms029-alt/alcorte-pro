CREATE DATABASE IF NOT EXISTS barberia_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE barberia_db;

CREATE TABLE IF NOT EXISTS sucursales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    direccion TEXT,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS barberos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    foto_url VARCHAR(300) DEFAULT '',
    hora_inicio TIME NOT NULL DEFAULT '09:00:00',
    hora_fin TIME NOT NULL DEFAULT '17:00:00',
    almuerzo_inicio TIME NOT NULL DEFAULT '12:00:00',
    almuerzo_fin TIME NOT NULL DEFAULT '13:00:00',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    sucursal_id INT DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(80) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    telefono VARCHAR(30) DEFAULT NULL,
    rol ENUM('superadmin','admin','gerente','barbero','cliente') NOT NULL DEFAULT 'cliente',
    sucursal_id INT DEFAULT NULL,
    barbero_id INT DEFAULT NULL,
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS citas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barbero_id INT DEFAULT NULL,
    cliente_nombre VARCHAR(150) NOT NULL,
    cliente_telefono VARCHAR(30) NOT NULL,
    servicio VARCHAR(100) NOT NULL,
    fecha DATE NOT NULL,
    hora TIME NOT NULL,
    metodo_pago VARCHAR(50) NOT NULL DEFAULT 'Efectivo',
    referencia_pago VARCHAR(200) DEFAULT NULL,
    estado_pago ENUM('pendiente','verificado') NOT NULL DEFAULT 'pendiente',
    sucursal_id INT DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clientes (
    telefono VARCHAR(30) NOT NULL PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    puntos INT NOT NULL DEFAULT 0,
    ultima_visita DATE DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS configuracion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clave VARCHAR(100) NOT NULL,
    valor TEXT,
    sucursal_id INT NOT NULL DEFAULT 1,
    UNIQUE KEY uq_clave_sucursal (clave, sucursal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS planes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    precio_mensual DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_barberos INT NOT NULL DEFAULT 3,
    max_citas_mes INT NOT NULL DEFAULT 100,
    descripcion TEXT,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suscripciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sucursal_id INT NOT NULL,
    plan_id INT NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    estado ENUM('activo','vencido','suspendido') NOT NULL DEFAULT 'activo',
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES planes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pagos_suscripcion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sucursal_id INT NOT NULL,
    plan_id INT DEFAULT NULL,
    monto DECIMAL(10,2) NOT NULL,
    fecha_pago DATE NOT NULL,
    metodo ENUM('Zelle','Efectivo','Transferencia','PayPal','Otro') NOT NULL DEFAULT 'Zelle',
    referencia VARCHAR(200) DEFAULT NULL,
    notas TEXT,
    registrado_por INT DEFAULT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sucursal_id) REFERENCES sucursales(id),
    FOREIGN KEY (plan_id) REFERENCES planes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default superadmin: usuario=admin, password=admin1234
INSERT IGNORE INTO usuarios (usuario, password, nombre, rol)
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrador', 'superadmin');

-- Default config for sucursal 1
INSERT IGNORE INTO configuracion (clave, valor, sucursal_id) VALUES
('nombre_negocio', 'AlCorte Pro', 1),
('estado_pago_movil', '0', 1),
('estado_zelle', '0', 1),
('estado_efectivo', '1', 1),
('banco_nombre', '', 1),
('banco_telefono', '', 1),
('banco_ci', '', 1),
('zelle_email', '', 1);

-- Default plans
INSERT IGNORE INTO planes (id, nombre, precio_mensual, max_barberos, max_citas_mes, descripcion) VALUES
(1, 'Básico', 29.99, 2, 100, 'Plan ideal para barberías pequeñas que inician'),
(2, 'Profesional', 59.99, 5, 300, 'Para negocios en crecimiento con múltiples barberos'),
(3, 'Empresarial', 99.99, 15, 1000, 'Para cadenas y franquicias con múltiples sedes');
