# AlCorte Pro — Guía de despliegue en local

Instrucciones paso a paso para instalar y ejecutar el proyecto en **otra máquina** (Windows con Laragon o XAMPP). Tiempo estimado: **15–30 minutos**.

---

## 1. Requisitos del sistema

| Componente | Versión mínima | Notas |
|------------|----------------|-------|
| PHP | 7.4+ (recomendado 8.1–8.3) | Extensiones: `mysqli`, `json`, `session`, `fileinfo` |
| MySQL / MariaDB | 5.7+ / 10.3+ | Puerto por defecto `3306` |
| Apache | 2.4+ | `mod_rewrite` habilitado |
| Git | Cualquier versión reciente | Para clonar el repositorio |

**Stack recomendado en Windows:** [Laragon](https://laragon.org/) (incluye PHP, MySQL y Apache).

---

## 2. Obtener el código

### Opción A — Clonar con Git

```bash
git clone https://github.com/wilsonjms029-alt/alcorte-pro.git
cd alcorte-pro
```

### Opción B — Descargar ZIP

1. Abre https://github.com/wilsonjms029-alt/alcorte-pro  
2. **Code → Download ZIP**  
3. Extrae el contenido en la carpeta web de tu servidor local.

### Ubicación de la carpeta (ejemplos)

| Stack | Ruta típica |
|-------|-------------|
| Laragon | `C:\laragon\www\alcorte-pro` |
| XAMPP | `C:\xampp\htdocs\alcorte-pro` |
| Linux | `/var/www/alcorte-pro` |

El nombre de la carpeta define parte de la URL en localhost (ver sección 6).

---

## 3. Iniciar servicios

### Laragon

1. Abre Laragon.
2. Clic en **Start All** (Apache + MySQL deben quedar en verde).
3. Opcional: **Menu → Apache → SSL → Disabled** si solo usas HTTP en local.

### XAMPP

1. Abre el Panel de Control de XAMPP.
2. Inicia **Apache** y **MySQL**.

### Verificar que MySQL responde

```bash
mysql -u root -e "SELECT 1;"
```

En Laragon, si `mysql` no está en el PATH, usa la ruta completa:

```powershell
C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe -u root -e "SELECT 1;"
```

(Ajusta la versión del directorio `mysql-*` según tu instalación.)

---

## 4. Base de datos

### 4.1 Crear la base de datos

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS barberia_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Si tu MySQL tiene contraseña para `root`:

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS barberia_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 4.2 Importar estructura y datos iniciales

Desde la raíz del proyecto:

```bash
mysql -u root barberia_db < backend/database/init.sql
```

En PowerShell (Windows):

```powershell
Get-Content backend\database\init.sql | mysql -u root barberia_db
```

Esto crea tablas, el usuario SuperAdmin por defecto y planes básicos.

### 4.3 (Opcional) Datos de demostración

Los datos de demo **no** se cargan con `init.sql`. Para tiendas, barberos y citas de ejemplo:

1. Inicia sesión como SuperAdmin (ver sección 7).
2. Abre en el navegador:
   ```
   http://localhost/alcorte-pro/backend/database/seed.php
   ```
   (ajusta la ruta según tu carpeta; ver sección 6).

> **Seguridad:** `seed.php` y `setup.php` solo funcionan con sesión **SuperAdmin**. Sin login devuelven **403**.

### 4.4 (Opcional) Migraciones adicionales

Si faltan tablas de planes o suscripciones, inicia sesión como SuperAdmin y visita:

```
http://localhost/alcorte-pro/backend/database/setup.php
```

---

## 5. Configuración de la aplicación

### 5.1 Archivo `.env`

```bash
cp .env.example .env
```

En Windows:

```powershell
Copy-Item .env.example .env
```

Edita `.env` si tu MySQL no usa los valores por defecto.

> **Importante:** Hoy la conexión a BD está definida en `backend/config/config.php` (líneas ~77–80). Si tu `root` tiene contraseña o usas otro usuario, edita ese archivo:

```php
$host = "127.0.0.1";
$user = "root";
$pass = "";           // ← tu contraseña MySQL
$db   = "barberia_db";
```

### 5.2 Carpeta de subidas

Crea las subcarpetas si no existen (el sistema también las crea al subir la primera imagen):

```
uploads/
uploads/logos/
uploads/barberos/
uploads/servicios/
uploads/productos/
```

En Linux/macOS, asegura que Apache/PHP pueda escribir en `uploads/`:

```bash
chmod -R 775 uploads
```

### 5.3 Apache — `AllowOverride`

Para que `.htaccess` funcione (bloqueo de rutas sensibles), en la configuración del virtual host o del directorio debe existir:

```apache
AllowOverride All
```

Laragon ya lo incluye en sus virtual hosts automáticos.

---

## 6. URLs de acceso

Sustituye `alcorte-pro` por el nombre real de tu carpeta si es distinto.

| Entrada | URL |
|---------|-----|
| **Login principal** | `http://localhost/alcorte-pro/` |
| **SuperAdmin (Torre de Control)** | `http://localhost/alcorte-pro/frontend/superadmin.php` |
| **Panel Admin / Gerente** | `http://localhost/alcorte-pro/frontend/admin.php` |
| **Panel Barbero** | `http://localhost/alcorte-pro/frontend/barbero.php` |
| **Cerrar sesión** | `http://localhost/alcorte-pro/frontend/logout.php` |

### Laragon — dominio `.test` (opcional)

Si el proyecto está en `C:\laragon\www\alcorte-pro`, Laragon suele crear:

```
http://alcorte-pro.test/
```

Si no funciona: Laragon → **Menu → Apache → Sites Directory** y reinicia Apache.

### Tienda pública (reservas de clientes)

Cada sucursal tiene un enlace único con token:

```
http://localhost/alcorte-pro/frontend/cliente.php?t={token_de_32_caracteres}
```

**Obtener el token:**

- Panel Admin → **Configuración** → sección *Enlace de Reservas para Clientes*, o  
- En MySQL:

```sql
SELECT id, nombre, token FROM sucursales WHERE activo = 1 AND id > 1;
```

---

## 7. Credenciales iniciales

### Tras `init.sql` (instalación limpia)

| Rol | Usuario | Contraseña |
|-----|---------|------------|
| SuperAdmin | `admin` | `admin1234` |

### Tras `seed.php` (datos de demo)

| Rol | Usuario | Contraseña |
|-----|---------|------------|
| SuperAdmin | `admin` | `admin1234` |
| Admin tienda Maracay | `admin_maracay` | `admin12345` |
| Admin tienda San Diego | `admin_sandiego` | `admin12345` |
| Admin tienda Valencia | `admin_valencia` | `admin12345` |
| Barbero | `joshy`, `carlos`, `andres`, `miguel`, `luis` | `barbero123` |

> Cambia todas las contraseñas antes de exponer el sistema en internet.

---

## 8. Verificación rápida (checklist)

Marca cada ítem después de instalar:

- [ ] `http://localhost/alcorte-pro/` muestra el formulario de login.
- [ ] Login con `admin` / `admin1234` abre SuperAdmin o redirige según rol.
- [ ] `http://localhost/alcorte-pro/backend/config/config.php` → **403 Forbidden** (protegido por `.htaccess`).
- [ ] `http://localhost/alcorte-pro/backend/database/seed.php` sin login → **403**.
- [ ] Enlace de tienda (`cliente.php?t=...`) muestra la pantalla de reservas.
- [ ] Subir logo en Admin → Configuración guarda y muestra la imagen.

### Probar API Club VIP (requiere token de tienda)

```
http://localhost/alcorte-pro/backend/api/club.php?t=TOKEN_TIENDA&telefono=04121234567
```

Respuesta esperada: JSON con `encontrado: true/false` (no datos si el teléfono no tiene historial en esa tienda).

---

## 9. Estructura relevante del proyecto

```
alcorte-pro/
├── index.php                 # Login principal
├── frontend/
│   ├── admin.php             # Panel admin / gerente
│   ├── superadmin.php        # Torre de control
│   ├── barbero.php           # Agenda barbero
│   ├── cliente.php           # Reservas públicas (con ?t=token)
│   └── logout.php
├── backend/
│   ├── config/config.php     # Conexión BD y helpers
│   ├── api/                  # APIs JSON (club, citas, bloqueos…)
│   ├── processing/           # Acciones POST (admin, superadmin)
│   └── database/
│       ├── init.sql          # Instalación inicial
│       ├── setup.php         # Migraciones (SuperAdmin)
│       └── seed.php          # Datos demo (SuperAdmin)
├── uploads/                  # Imágenes subidas
├── .env.example
└── .htaccess                 # Seguridad y bloqueos
```

---

## 10. Solución de problemas

### «No se pudo conectar con la base de datos»

- MySQL no está iniciado.
- Base `barberia_db` no existe → repetir sección 4.
- Usuario/contraseña incorrectos en `backend/config/config.php`.

### Página en blanco o error 500

- Revisa el log de PHP: `backend/logs/php-error.log`
- En Laragon: **Menu → PHP → php.ini** → `display_errors = On` solo para depurar en local.

### Apache no arranca (puerto 80 ocupado)

- Cierra Skype, IIS u otro servicio en puerto 80, o cambia el puerto de Apache en Laragon.

### Las imágenes subidas no se ven

- Verifica que exista la carpeta `uploads/` con permisos de escritura.
- La URL de la imagen debe coincidir con la ruta del proyecto (el sistema calcula la ruta base automáticamente).

### `cliente.php` dice «Enlace no válido»

- El parámetro `t` debe ser un token de **32 caracteres hex** de una sucursal activa.
- Copia el enlace completo desde el panel Admin → Configuración.

### No aparece menú «Clientes» (Club VIP)

- El plan de la tienda debe ser **Profesional** o **Pro** (nivel ≥ 2 en tabla `planes` / suscripción activa).

### Cambios de git no se reflejan

- Reinicia Apache.
- Limpia caché del navegador (Ctrl+F5).

---

## 11. Flujo de trabajo diario (desarrollo)

```text
1. Laragon → Start All
2. Abrir http://alcorte-pro.test/ o http://localhost/alcorte-pro/
3. Trabajar en el código
4. git pull / git push según el flujo del equipo
```

---

## 12. Siguiente paso: producción

Para desplegar en un hosting (cPanel, VPS), además de esta guía:

1. Cambiar contraseñas por defecto.
2. Usar HTTPS (`SESSION_SECURE=true` en `.env` cuando se integre).
3. No dejar `seed.php` / `setup.php` accesibles sin necesidad.
4. Configurar copias de seguridad de `barberia_db`.

---

## Referencia rápida de comandos (Windows + Laragon)

```powershell
# Clonar
git clone https://github.com/wilsonjms029-alt/alcorte-pro.git C:\laragon\www\alcorte-pro
cd C:\laragon\www\alcorte-pro

# Base de datos
mysql -u root -e "CREATE DATABASE IF NOT EXISTS barberia_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
Get-Content backend\database\init.sql | mysql -u root barberia_db

# Entorno
Copy-Item .env.example .env

# Abrir en navegador
start http://alcorte-pro.test/
```

---

**Repositorio:** https://github.com/wilsonjms029-alt/alcorte-pro  
**Última actualización de esta guía:** Agosto 2026
