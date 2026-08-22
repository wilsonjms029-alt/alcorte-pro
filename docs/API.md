# API AlCorte Pro (v1)

Base URL: `/api/v1/` (relativa al directorio raíz del proyecto).

Todas las respuestas JSON usan el formato:

```json
{ "ok": true, "message": "...", "data": { ... } }
```

Errores:

```json
{ "ok": false, "error": "Descripción del error" }
```

## Autenticación

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/v1/auth/login` | Login (JSON: `usuario`, `password`, `csrf_token`) |
| POST | `/api/v1/auth/logout` | Cerrar sesión |

## Panel admin (sesión: admin, gerente, superadmin)

| Método | Ruta | Body |
|--------|------|------|
| POST | `/api/v1/admin` | `action` + campos del formulario (multipart para imágenes) |

Acciones: `add_cliente`, `delete_cliente`, `add_barbero`, `edit_barbero`, `delete_barbero`, `add_servicio`, `edit_servicio`, `delete_servicio`, `add_bloqueo`, `delete_bloqueo`, `update_sys_settings`, `add_producto`, `edit_producto`, `delete_producto`, `aprobar_pago_pedido`, `completar_pedido`, `cancelar_pedido`, `verificar_cita`.

## Superadmin

| Método | Ruta | Body |
|--------|------|------|
| POST | `/api/v1/superadmin` | `action` + campos |

## Usuarios (superadmin)

| Método | Ruta | Body |
|--------|------|------|
| POST | `/api/v1/usuarios` | `crear_usuario`, `editar_usuario`, `eliminar_usuario` |

## Barbero

| Método | Ruta | Body |
|--------|------|------|
| POST | `/api/v1/barbero` | `action=update_estado`, `cita_id`, `nuevo_estado` |

## Público (token de tienda `t`)

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/api/v1/public/agendar` | Reservar cita |
| POST | `/api/v1/public/pedido` | Crear pedido de productos |
| GET | `/api/v1/club?t=&telefono=` | Club VIP |
| GET | `/api/v1/bloqueos?barbero_id=&fecha=&t=` | Bloqueos de horario |
| GET | `/api/v1/mis_citas?telefono=&t=` | Citas y pedidos del cliente |
| POST | `/api/v1/cancelar_cita` | Cancelar cita (JSON) |

## CSRF

Las peticiones POST autenticadas y públicas requieren `csrf_token` de la sesión PHP actual.

## Legacy

Los endpoints antiguos en `backend/api/*.php` siguen disponibles; el router `/api/v1/` es la forma recomendada.

Los scripts en `backend/processing/` se invocan internamente vía el router y mantienen redirección HTML si se accede sin `ALCORTE_API_REQUEST`.
