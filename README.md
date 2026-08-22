# AlCorte Pro - Sistema de Gestión de Barbería

Sistema profesional de gestión de citas, clientes y personal para barberías. Multitienda, con control de roles y seguridad avanzada.

## 🚀 Características

- ✅ **Multitienda**: Gestión de múltiples sucursales con aislamiento de datos
- ✅ **5 Roles**: SuperAdmin, Admin, Gerente, Barbero, Cliente
- ✅ **Seguridad**: CSRF tokens, SQL injection prevention, rate limiting, bcrypt hashing
- ✅ **UI Light Moderno**: Interfaz profesional con diseño SaaS (Notion/Linear style)
- ✅ **Club VIP**: Sistema de puntos acumulables
- ✅ **Métodos de Pago**: Pago Móvil, Zelle, Efectivo
- ✅ **API REST**: Endpoint público para consultas de puntos

## 📁 Estructura del Proyecto

```
alcorte-prueba/
├── frontend/                    # Interfaz pública y paneles
│   ├── login/
│   │   ├── index.php           # Formulario de login
│   │   └── logout.php          # Cierre de sesión
│   ├── admin/
│   │   ├── index.php           # Panel Admin/Gerente
│   │   ├── superadmin.php      # Panel SuperAdmin
│   │   └── usuarios.php        # Gestión de usuarios
│   ├── barbero/
│   │   └── index.php           # Agenda del Barbero
│   └── cliente/
│       └── index.php           # Plataforma de reservas
│
├── backend/                     # Lógica de servidor (NO exponer)
│   ├── config/
│   │   └── config.php          # Configuración y conexión BD
│   ├── api/
│   │   └── club.php            # API pública para consultas VIP
│   ├── processing/
│   │   ├── admin.php           # Procesamiento de acciones
│   │   ├── superadmin.php      # Procesamiento de acciones
│   │   └── usuarios.php        # Procesamiento de gestión
│   └── database/
│       ├── schema.sql          # Estructura de base de datos
│       └── migration.sql       # Migración multitienda
│
├── index.php                    # Punto de entrada (redirecciona a login)
├── .env.example                 # Variables de entorno
├── .htaccess                    # Seguridad y reescrituras
└── README.md                    # Documentación
```

## 🔧 Instalación

> **Guía detallada para otra máquina (local):** ver [docs/DESPLIEGUE_LOCAL.md](docs/DESPLIEGUE_LOCAL.md) — Laragon/XAMPP, base de datos, URLs, credenciales y troubleshooting.

### Requisitos
- PHP 7.4+
- MySQL 5.7+
- Apache con mod_rewrite
- XAMPP (para desarrollo local)

### Pasos de Instalación

1. **Clonar o descargar el proyecto**
   ```bash
   git clone <repo-url> alcorte-prueba
   cd alcorte-prueba
   ```

2. **Configurar base de datos**
   - Crear base de datos: `barberia_db`
   - Importar: `backend/database/init.sql`
   - (Opcional demo) Con sesión SuperAdmin: `backend/database/seed.php`

3. **Configurar conexión BD**
   - Editar credenciales en `backend/config/config.php` (o copiar `.env.example` a `.env` cuando esté integrado)

4. **Establecer permisos (en servidor)**
   ```bash
   chmod 755 -R .
   chmod 700 backend/    # Extra protección
   ```

5. **Acceder a la aplicación**
   - URL: `http://localhost/alcorte-prueba`
   - Redirigirá automáticamente a `/frontend/login/`

## 👤 Credenciales de Prueba

| Rol | Usuario | Contraseña | Acceso |
|-----|---------|-----------|--------|
| SuperAdmin | `admin` | `admin1234` | Torre de control (`frontend/superadmin.php`) |
| Admin | `admin` | `admin123` | Panel de administración |
| Gerente | `gerente` | `gerente123` | Dashboard de sucursal |
| Barbero | `joshy` | `barbero123` | Mi agenda |
| Cliente | `cliente_demo` | `cliente123` | Reservar citas |

## 🔐 Seguridad

### Implementado
- **CSRF Tokens**: Validación en todos los formularios
- **SQL Injection Prevention**: Prepared statements con bind_param
- **Password Hashing**: BCrypt con PHP password_hash()
- **Rate Limiting**: 5 intentos fallidos = 15 minutos de bloqueo
- **Session Management**: Aislamiento por rol y sucursal
- **Backend Blocking**: .htaccess bloquea acceso directo a /backend
- **Headers de Seguridad**: X-Frame-Options, X-Content-Type-Options, etc.

### Para Producción
- [ ] Cambiar todas las contraseñas
- [ ] Configurar HTTPS obligatorio
- [ ] Deshabilitar display_errors en config.php
- [ ] Usar variables de entorno para credenciales BD
- [ ] Configurar respaldos automáticos
- [ ] Verificar que .htaccess bloquea acceso a backend

## 🗄️ Base de Datos

### Tablas Principales
- `usuarios` - Usuarios del sistema con 5 roles
- `sucursales` - Sedes/filiales del negocio
- `barberos` - Personal de barberías
- `citas` - Reservas de clientes
- `clientes` - Club VIP con puntos acumulables
- `configuracion` - Settings por sucursal (métodos de pago, etc.)

## 🌐 Métodos de Pago

Configurables por sucursal en SuperAdmin → Settings:
- **Pago Móvil** (Bs)
- **Zelle** (USD/divisa)
- **Efectivo** (sin validación)

## 📱 Responsive Design

- ✅ Desktop (1024px+)
- ✅ Tablet (768px - 1023px)
- ✅ Mobile (< 768px)

## 🚀 Despliegue a Servidor

### En cPanel/Hosting

1. Subir archivos vía FTP a carpeta pública
2. Crear base de datos MySQL en cPanel
3. Importar `backend/database/schema.sql`
4. Copiar `.env.example` a `.env`
5. Configurar `.env` con credenciales reales
6. Verificar que `.htaccess` está activo

### Estructura de carpetas en servidor

```
public_html/alcorte-prueba/
├── frontend/           ← Expuesto públicamente
├── backend/            ← Bloqueado por .htaccess
├── index.php
├── .htaccess
└── .env
```

## ✅ Testing Rápido

```bash
# Verificar que login funciona
http://localhost/alcorte-prueba

# Verificar que backend está protegido
http://localhost/alcorte-prueba/backend/config/config.php
# Debería devolver: Forbidden

# Verificar API Club VIP (requiere token de tienda ?t=)
http://localhost/alcorte-pro/backend/api/club.php?t=TOKEN_TIENDA&telefono=04121234567
```

## 📊 Flujos Principales

1. **Cliente: Agendar Cita**
   - Login → Reservar → Formulario → Processing → Cita creada

2. **Admin: Validar Pagos**
   - Dashboard → Citas pendientes → Aprobar → Puntos asignados

3. **SuperAdmin: Gestionar**
   - Dashboard empresarial → Usuarios, Sucursales, Settings

## 🐛 Troubleshooting

**Error: "Acceso denegado" en login**
- Verificar `sucursal_id` en tabla `usuarios`

**API devuelve 403 Forbidden**
- Normal en desarrollo. En producción, permitir solo desde frontend

**Pago Móvil no aparece**
- Habilitar en SuperAdmin → Configuración

## 📄 Licencia

Todos los derechos reservados © 2026 AlCorte Pro.

---

**Versión**: 1.0.0  
**Estado**: Production Ready ✅  
**Última actualización**: Junio 2026
