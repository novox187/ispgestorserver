# ISPgestor Server

[![Status](https://img.shields.io/badge/status-producción-brightgreen)](#)
[![PHP](https://img.shields.io/badge/PHP-%5E8.2-777bb4?logo=php&logoColor=white)](#requisitos-previos)
[![Laravel](https://img.shields.io/badge/Laravel-%5E12-ff2d20?logo=laravel&logoColor=white)](https://laravel.com/)
[![Sanctum](https://img.shields.io/badge/Sanctum-4-ff2d20?logo=laravel&logoColor=white)](https://laravel.com/docs/sanctum)
[![Reverb](https://img.shields.io/badge/Reverb-WebSocket-6366f1)](#)
[![License](https://img.shields.io/badge/licencia-privada-lightgrey)](#licencia)

API REST y servidor de tiempo real para el sistema ISPgestor. Construido con Laravel 12, gestiona clientes, facturación automática, integración con MikroTik RouterOS, soporte por chat en tiempo real y control de acceso basado en roles.

---

## Tabla de contenidos

- [Descripción y arquitectura](#descripción-y-arquitectura)
- [Requisitos previos](#requisitos-previos)
- [Instalación y configuración](#instalación-y-configuración)
- [Variables de entorno](#variables-de-entorno)
- [Scripts disponibles](#scripts-disponibles)
- [Estructura de carpetas](#estructura-de-carpetas)
- [Dependencias](#dependencias)
- [Modelos y base de datos](#modelos-y-base-de-datos)
- [Documentación de la API](#documentación-de-la-api)
- [Autenticación y autorización](#autenticación-y-autorización)
- [Sistema de colas y jobs](#sistema-de-colas-y-jobs)
- [WebSocket con Laravel Reverb](#websocket-con-laravel-reverb)
- [Integración con MikroTik](#integración-con-mikrotik)
- [Facturación automática](#facturación-automática)
- [Comandos Artisan personalizados](#comandos-artisan-personalizados)
- [Despliegue en producción](#despliegue-en-producción)
- [Troubleshooting](#troubleshooting)
- [Contribución](#contribución)
- [Licencia](#licencia)

---

## Descripción y arquitectura

ISPgestor Server es el backend completo del sistema ISPgestor. Expone una API REST consumida por el panel de administración (`ispgestoradmin`) y un portal de clientes. Utiliza colas asíncronas para las operaciones de facturación y control de estado, y Laravel Reverb para notificaciones WebSocket en tiempo real.

### Stack tecnológico

| Componente | Tecnología | Versión |
|-----------|-----------|---------|
| Framework | Laravel | ^12.0 |
| Lenguaje | PHP | ^8.2 |
| Autenticación API | Laravel Sanctum | ^4.0 |
| WebSocket | Laravel Reverb | ^1.10 |
| ORM | Eloquent | (incluido en Laravel) |
| Base de datos | MySQL | >= 8.0 |
| Colas | Database Queue | (incluido en Laravel) |
| MikroTik | RouterOS API PHP | ^1.6 |
| Almacenamiento de archivos | Cloudinary Laravel | ^3.0 |
| Testing | Pest PHP | ^4.1 |
| Logs | Laravel Pail | ^1.2.2 |

### Diagrama de arquitectura

```
ispgestoradmin (frontend)
         │
         │  HTTP REST (Bearer token)
         ▼
┌────────────────────────────────────────┐
│            Laravel 12 API              │
│                                        │
│  Routes/API ──► Controllers ──► Models │
│       │                           │    │
│  Middleware (Sanctum, RBAC)        │    │
│                                   ▼    │
│  Jobs / Queues              MySQL DB   │
│  (billing, suspensions)                │
│                                        │
│  Events ──► Reverb (WebSocket)         │
│                                        │
│  MikroTik API ──► RouterOS             │
│  Cloudinary ──► File storage           │
└────────────────────────────────────────┘
         │  WebSocket (ws://)
         ▼
ispgestoradmin (chat en tiempo real)
```

---

## Requisitos previos

- **PHP** >= 8.2 con las extensiones: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`
- **Composer** >= 2.x
- **MySQL** >= 8.0
- **Node.js** >= 18.x + npm (para el servidor de desarrollo con `composer dev`)
- Acceso de red al router **MikroTik** con la API RouterOS habilitada (puerto 8728 por defecto)
- (Opcional) Cuenta en **Cloudinary** para almacenamiento de archivos adjuntos

---

## Instalación y configuración

### 1. Clonar el repositorio

```bash
git clone <url-del-repositorio>
cd ispgestorserver
```

### 2. Instalar dependencias PHP

```bash
composer install
```

### 3. Configurar variables de entorno

```bash
cp .env.example .env
php artisan key:generate
```

Edita `.env` con los valores de tu entorno. Ver la sección [Variables de entorno](#variables-de-entorno).

### 4. Configurar la base de datos

Crea la base de datos en MySQL:

```sql
CREATE DATABASE ispgestorserver CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Ejecuta las migraciones y los seeders de datos iniciales:

```bash
php artisan migrate
php artisan db:seed
```

Los seeders crean un administrador por defecto, roles, permisos y datos de prueba.

### 5. Iniciar todos los servicios en desarrollo

El comando `dev` de Composer lanza en paralelo el servidor HTTP, el worker de colas, el visor de logs y Reverb:

```bash
composer dev
```

O puedes iniciar cada servicio por separado:

```bash
# Servidor HTTP
php artisan serve

# Worker de colas (necesario para billing y suspensiones)
php artisan queue:listen --tries=1

# Servidor WebSocket (chat en tiempo real)
php artisan reverb:start

# Visor de logs en tiempo real
php artisan pail
```

La API estará disponible en `http://localhost:8000`.

---

## Variables de entorno

Copia `.env.example` a `.env` y configura cada sección:

### Aplicación

```env
APP_NAME=ISPgestor
APP_ENV=local             # local | production
APP_KEY=                  # Generada con php artisan key:generate
APP_DEBUG=true            # false en producción
APP_URL=http://localhost
APP_LOCALE=es
```

### Base de datos

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ispgestorserver
DB_USERNAME=root
DB_PASSWORD=tu-password
```

### Colas y caché

```env
QUEUE_CONNECTION=database   # Almacena jobs en la tabla jobs de MySQL
CACHE_STORE=database
SESSION_DRIVER=database
```

### Broadcasting (Laravel Reverb — WebSocket)

```env
BROADCAST_CONNECTION=reverb   # "log" en dev, "reverb" en producción

REVERB_APP_ID=ispgestor
REVERB_APP_KEY=isp-chat-key   # Debe coincidir con VITE_REVERB_APP_KEY del frontend
REVERB_APP_SECRET=tu-secreto
REVERB_HOST=0.0.0.0
REVERB_PORT=8080
REVERB_SCHEME=http            # https en producción
```

### MikroTik RouterOS API

```env
MIKROTIK_HOST=192.168.88.1    # IP del router
MIKROTIK_USER=admin
MIKROTIK_PASS=tu-password
MIKROTIK_PORT=8728            # Puerto de la API RouterOS
MIKROTIK_TIMEOUT=10
MIKROTIK_ATTEMPTS=10
MIKROTIK_DELAY=1
```

### Facturación automática

```env
BILLING_SUSPENSION_GRACE_DAYS=3               # Días de gracia antes de suspender
BILLING_TIMEZONE=America/Guayaquil           # Zona horaria Ecuador (UTC-5, sin DST)
BILLING_SCHEDULE_INVOICE_DAY=1                # Día del mes para generar facturas
BILLING_SCHEDULE_INVOICE_TIME=00:05           # Hora de generación de facturas
BILLING_SCHEDULE_PAYMENTS_TIME=02:00          # Hora de procesamiento de pagos
BILLING_SCHEDULE_SUSPEND_TIME=08:00           # Hora de ejecución de suspensiones
BILLING_SCHEDULE_REACTIVATE_TIME=10:00        # Hora de revisión de reactivaciones
BILLING_SCHEDULE_MKSYNC_HOUR1=6               # Primera sincronización diaria MikroTik
BILLING_SCHEDULE_MKSYNC_HOUR2=18              # Segunda sincronización diaria MikroTik
BILLING_QUEUE_SUSPENSIONS=suspensions         # Nombre de la cola para suspensiones
BILLING_QUEUE_REACTIVATIONS=reactivations     # Nombre de la cola para reactivaciones
```

### Cloudinary (almacenamiento de archivos adjuntos)

```env
CLOUDINARY_URL=cloudinary://api_key:api_secret@cloud_name
```

### Correo electrónico

```env
MAIL_MAILER=smtp              # log | smtp | sendmail
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=tu-usuario
MAIL_PASSWORD=tu-password
MAIL_FROM_ADDRESS=noreply@tudominio.com
MAIL_FROM_NAME=ISPgestor
```

---

## Scripts disponibles

### Composer

| Comando | Descripción |
|---------|-------------|
| `composer dev` | Inicia servidor, queue, logs y Vite en paralelo |
| `composer test` | Limpia caché y ejecuta la suite de tests con Pest |

### Artisan — Generales

| Comando | Descripción |
|---------|-------------|
| `php artisan serve` | Servidor HTTP de desarrollo |
| `php artisan migrate` | Ejecutar migraciones pendientes |
| `php artisan migrate:fresh --seed` | Recrear toda la base de datos con seeders |
| `php artisan db:seed` | Ejecutar seeders sin recrear tablas |
| `php artisan queue:listen` | Procesar jobs de la cola en tiempo real |
| `php artisan queue:work` | Procesar jobs de la cola (daemon) |
| `php artisan schedule:run` | Ejecutar las tareas programadas manualmente |
| `php artisan schedule:work` | Iniciar el scheduler en modo daemon (dev) |
| `php artisan reverb:start` | Iniciar el servidor WebSocket |
| `php artisan pail` | Ver logs de la aplicación en tiempo real |
| `php artisan tinker` | REPL interactivo de Laravel |

### Artisan — Comandos personalizados

| Comando | Descripción |
|---------|-------------|
| `php artisan mikrotik:test` | Probar la conectividad con el router MikroTik |
| `php artisan billing:check-reactivate` | Verificar y reactivar clientes que pagaron |
| `php artisan billing:process-auto` | Procesar facturación automática manualmente |

---

## Estructura de carpetas

```
ispgestorserver/
├─ app/
│  ├─ Console/
│  │  └─ Commands/
│  │     ├─ CheckAndReactivate.php         # Reactivar clientes con saldo suficiente
│  │     ├─ ProcessAutoBilling.php         # Facturación automática manual
│  │     └─ TestMikroTikConnection.php    # Test de conectividad al router
│  ├─ Events/
│  │  ├─ ClientEventBroadcast.php          # Evento: cambio de estado de cliente (WebSocket)
│  │  └─ MessageSent.php                   # Evento: nuevo mensaje de chat (WebSocket)
│  ├─ Http/
│  │  ├─ Controllers/
│  │  │  ├─ Admin/                         # Controladores del panel de administración
│  │  │  │  ├─ ChatController.php         # Gestión de tickets de soporte
│  │  │  │  ├─ ClientController.php       # CRUD de clientes y cambios de estado
│  │  │  │  ├─ DashboardController.php    # Estadísticas y KPIs del dashboard
│  │  │  │  ├─ EmployeeController.php     # Gestión de empleados y roles
│  │  │  │  ├─ ImportController.php       # Importación masiva de clientes
│  │  │  │  ├─ InvoiceController.php      # Gestión de facturas (admin)
│  │  │  │  ├─ InternetServiceProviderController.php  # ISPs
│  │  │  │  ├─ IspConnectionController.php            # Uplinks de ISPs
│  │  │  │  ├─ MikrotikRouterController.php           # Configuración de routers
│  │  │  │  ├─ PlanController.php         # Planes de servicio
│  │  │  │  └─ TransactionController.php  # Transacciones de billetera (admin)
│  │  │  ├─ AuthClientController.php      # Autenticación de clientes (portal)
│  │  │  ├─ AuthEmployeeController.php    # Autenticación de empleados (admin)
│  │  │  ├─ ClientPlanController.php      # Consultas de plan del cliente
│  │  │  ├─ FirewallController.php        # Reglas de firewall MikroTik
│  │  │  ├─ InvoiceController.php         # Facturas (portal de clientes)
│  │  │  ├─ MessageController.php         # Mensajes de chat
│  │  │  ├─ MikroTikController.php        # API del sistema MikroTik
│  │  │  ├─ TransactionController.php     # Transacciones (portal de clientes)
│  │  │  └─ walletController.php          # Saldo de la billetera
│  │  └─ Middleware/
│  │     └─ EnsureEmployeeSuperAdmin.php  # Middleware de rol Super Admin
│  ├─ Jobs/
│  │  ├─ GenerateMonthlyInvoices.php      # Generar facturas mensuales
│  │  ├─ ProcessAutoReactivation.php      # Reactivar cliente tras pago
│  │  ├─ ProcessClientSuspension.php      # Suspender cliente moroso
│  │  └─ SyncMikroTikQueues.php           # Sincronizar colas del router
│  └─ Models/
│     ├─ Client.php                        # Cliente (Authenticatable)
│     ├─ ClientEvent.php                   # Eventos de actividad del cliente
│     ├─ ClientPlan.php                    # Asignación de plan a cliente
│     ├─ Employee.php                      # Empleado/usuario del panel
│     ├─ FirewallApplyLog.php             # Historial de cambios de firewall
│     ├─ FirewallFilterRule.php           # Reglas de filtro MikroTik
│     ├─ FirewallNatRule.php              # Reglas NAT MikroTik
│     ├─ ImportHistory.php                # Historial de importaciones
│     ├─ InternetServiceProvider.php      # Proveedor de internet (ISP)
│     ├─ Invoice.php                       # Factura de servicio
│     ├─ IspConnection.php                # Conexión/uplink de ISP
│     ├─ Message.php                       # Mensaje de chat
│     ├─ MikrotikRouter.php               # Configuración de router
│     ├─ Permission.php                    # Permiso RBAC
│     ├─ Plan.php                          # Plan de servicio
│     ├─ PlanFeature.php                   # Característica de plan
│     ├─ Role.php                          # Rol de empleado
│     ├─ Support.php                       # Ticket de soporte
│     ├─ Ticket.php                        # Seguimiento de ticket de chat
│     ├─ Transaction.php                   # Transacción de billetera
│     └─ Wallet.php                        # Billetera del cliente
│
├─ routes/
│  ├─ api.php                              # Todas las rutas de la API REST
│  ├─ web.php                              # Rutas web (mínimas)
│  └─ channels.php                         # Definición de canales WebSocket
│
├─ database/
│  ├─ migrations/                          # Definiciones de esquema (~27 archivos)
│  ├─ seeders/
│  │  ├─ DatabaseSeeder.php               # Orquestador principal de seeders
│  │  ├─ AdministradorSeeder.php          # Usuario administrador por defecto
│  │  ├─ RolSeeder.php                    # Roles del sistema
│  │  ├─ PermissionSeeder.php             # Permisos RBAC
│  │  ├─ RolePermissionSeeder.php         # Asignación de permisos a roles
│  │  ├─ PlanSeeder.php                   # Planes de servicio de ejemplo
│  │  ├─ PlanFeatureSeeder.php            # Características de planes
│  │  ├─ ClientSeeder.php                 # Clientes de prueba
│  │  ├─ ClientPlanSeeder.php             # Asignaciones plan-cliente
│  │  ├─ WalletSeeder.php                 # Billeteras de prueba
│  │  ├─ TransactionSeeder.php            # Transacciones de prueba
│  │  └─ SupportSeeder.php               # Tickets de soporte de prueba
│  └─ factories/                           # Factories para tests
│
├─ config/
│  ├─ mikrotik.php                         # Configuración del cliente RouterOS API
│  ├─ sanctum.php                          # Configuración de autenticación
│  ├─ broadcasting.php                     # Configuración de canales WebSocket
│  └─ [archivos de configuración estándar de Laravel]
│
├─ storage/
│  ├─ app/                                 # Almacenamiento local de archivos
│  ├─ logs/                               # Archivos de log de la aplicación
│  └─ framework/                           # Caché, vistas compiladas y sesiones
│
├─ tests/
│  ├─ Feature/                             # Tests de integración (Pest)
│  └─ Unit/                               # Tests unitarios
│
├─ .env.example                            # Plantilla de variables de entorno
├─ artisan                                 # CLI de Laravel
├─ composer.json                           # Dependencias PHP
└─ Dockerfile                             # Imagen Docker para producción
```

---

## Dependencias

### Producción (`require`)

| Paquete | Versión | Propósito |
|---------|---------|-----------|
| `php` | ^8.2 | Lenguaje base |
| `laravel/framework` | ^12.0 | Framework PHP |
| `laravel/sanctum` | ^4.0 | Autenticación API con tokens |
| `laravel/reverb` | ^1.10 | Servidor WebSocket nativo |
| `laravel/tinker` | ^2.10.1 | REPL interactivo |
| `evilfreelancer/routeros-api-php` | ^1.6 | Cliente RouterOS API para MikroTik |
| `cloudinary-labs/cloudinary-laravel` | ^3.0 | Almacenamiento de archivos en Cloudinary |

### Desarrollo (`require-dev`)

| Paquete | Versión | Propósito |
|---------|---------|-----------|
| `pestphp/pest` | ^4.1 | Framework de testing moderno |
| `pestphp/pest-plugin-laravel` | ^4.0 | Plugin Pest para Laravel |
| `laravel/pail` | ^1.2.2 | Visor de logs en tiempo real |
| `laravel/pint` | ^1.24 | Formateador de código PHP |
| `laravel/sail` | ^1.41 | Entorno Docker para desarrollo |
| `fakerphp/faker` | ^1.23 | Generación de datos falsos para tests |
| `mockery/mockery` | ^1.6 | Mocking para tests unitarios |
| `nunomaduro/collision` | ^8.6 | Reportes de error más legibles |

---

## Modelos y base de datos

### Tablas principales

| Tabla | Modelo | Descripción |
|-------|--------|-------------|
| `clients` | `Client` | Clientes del ISP (Authenticatable) |
| `employees` | `Employee` | Empleados/administradores |
| `plans` | `Plan` | Planes de servicio con velocidades y precio |
| `plan_features` | `PlanFeature` | Características adicionales por plan |
| `clients_plans` | `ClientPlan` | Asignación de plan a cliente |
| `wallets` | `Wallet` | Billetera/saldo del cliente |
| `transactions` | `Transaction` | Movimientos de la billetera |
| `invoices` | `Invoice` | Facturas de servicio mensual |
| `roles` | `Role` | Roles de empleados |
| `permissions` | `Permission` | Permisos del sistema RBAC |
| `role_permission` | — | Relación roles ↔ permisos |
| `tickets` | `Ticket` | Tickets de soporte |
| `messages` | `Message` | Mensajes de chat dentro de tickets |
| `attachments` | `Attachment` | Archivos adjuntos en mensajes |
| `supports` | `Support` | Información base del soporte |
| `mikrotik_routers` | `MikrotikRouter` | Configuración de routers |
| `firewall_filter_rules` | `FirewallFilterRule` | Reglas de filtro MikroTik |
| `firewall_nat_rules` | `FirewallNatRule` | Reglas NAT MikroTik |
| `firewall_apply_logs` | `FirewallApplyLog` | Historial de cambios de firewall |
| `internet_service_providers` | `InternetServiceProvider` | ISPs registrados |
| `isp_connections` | `IspConnection` | Uplinks/conexiones de ISPs |
| `import_history` | `ImportHistory` | Registros de importaciones |
| `audits` | `Audit` | Log de auditoría de cambios |
| `client_events` | `ClientEvent` | Eventos de actividad del cliente |
| `personal_access_tokens` | — | Tokens de Sanctum |
| `jobs` | — | Jobs de la cola |
| `cache` | — | Caché de la aplicación |

### Relaciones clave

```
Client ──── has one  ──── Wallet
Client ──── has many ──── Transaction
Client ──── has many ──── Invoice
Client ──── has many ──── ClientPlan ──── belongs to ──── Plan
Client ──── has many ──── Ticket
Ticket ──── has many ──── Message
Employee ── belongs to ── Role
Role ─────── belongs to many ── Permission
MikrotikRouter ── has many ── FirewallFilterRule
MikrotikRouter ── has many ── FirewallNatRule
MikrotikRouter ── has many ── FirewallApplyLog
InternetServiceProvider ── has many ── IspConnection
```

---

## Documentación de la API

Todas las rutas de la API están bajo el prefijo `/api`. Las rutas autenticadas requieren el header `Authorization: Bearer {TOKEN}`.

### Autenticación de empleados

| Método | Ruta | Descripción |
|--------|------|-------------|
| `POST` | `/api/employee/login` | Login → retorna token Sanctum |
| `POST` | `/api/employee/logout` | Logout e invalidación del token |
| `GET` | `/api/user` | Datos del usuario autenticado |

### Autenticación de clientes (portal)

| Método | Ruta | Descripción |
|--------|------|-------------|
| `POST` | `/api/client/login` | Login del cliente |
| `POST` | `/api/client/logout` | Logout del cliente |

### Dashboard

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/admin/dashboard/stats` | KPIs: clientes activos, ingresos, facturas |
| `GET` | `/api/admin/dashboard/full-stats` | Estadísticas extendidas con tendencias |
| `GET` | `/api/admin/dashboard/top-debtors` | Lista de mayores deudores |

### Clientes

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/admin/clientes/summary` | Lista con filtros (`estado`, `plan_id`, etc.) |
| `GET` | `/api/admin/clientes/full/{id}` | Detalle completo del cliente |
| `POST` | `/api/admin/clientes/crear` | Crear nuevo cliente |
| `PUT` | `/api/admin/clientes/{id}` | Actualizar datos personales |
| `POST` | `/api/admin/clientes/{id}/suspend` | Suspender servicio |
| `POST` | `/api/admin/clientes/{id}/activate` | Reactivar servicio |
| `POST` | `/api/admin/clientes/{id}/cancel` | Cancelar contrato |
| `POST` | `/api/admin/clientes/{id}/add-funds` | Agregar fondos a la wallet |

### Planes

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/admin/planes/summary` | Listar planes con estadísticas |
| `POST` | `/api/admin/planes` | Crear plan |
| `PUT` | `/api/admin/planes/{id}` | Actualizar plan |
| `PUT` | `/api/admin/planes/{id}/status` | Activar/desactivar plan |

### Facturación

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/admin/invoices` | Listar facturas (filtros por estado, cliente, fecha) |
| `POST` | `/api/admin/invoices/generate-auto` | Generar facturas automáticas |
| `GET` | `/api/invoices` | Facturas del cliente autenticado (portal) |

### Empleados

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/admin/employees` | Listar empleados |
| `POST` | `/api/admin/employees` | Crear empleado |
| `PUT` | `/api/admin/employees/{id}` | Actualizar empleado |
| `DELETE` | `/api/admin/employees/{id}` | Eliminar empleado |

### ISPs y conexiones

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/admin/isps` | Listar ISPs registrados |
| `POST` | `/api/admin/isps` | Crear ISP |
| `PUT` | `/api/admin/isps/{id}` | Actualizar ISP |
| `GET` | `/api/admin/isp-connections` | Listar conexiones de ISPs |
| `POST` | `/api/admin/isp-connections` | Crear conexión |

### MikroTik — Routers

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/admin/mikrotik-routers` | Listar routers |
| `POST` | `/api/admin/mikrotik-routers` | Registrar router |
| `PUT` | `/api/admin/mikrotik-routers/{id}` | Actualizar router |
| `DELETE` | `/api/admin/mikrotik-routers/{id}` | Eliminar router |

### MikroTik — Firewall

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/mikrotik/firewall/snapshot` | Snapshot actual de reglas |
| `POST` | `/api/mikrotik/firewall/validate` | Validar cambios propuestos |
| `POST` | `/api/mikrotik/firewall/apply` | Aplicar cambios al router |
| `GET` | `/api/mikrotik/firewall/apply-logs` | Historial de cambios |
| `POST` | `/api/mikrotik/firewall/apply-logs/{id}/rollback` | Revertir un cambio |
| `GET` | `/api/mikrotik/firewall/router-status` | Estado de conectividad |
| `POST` | `/api/mikrotik/firewall/sync/from-router` | Importar reglas del router |
| `POST` | `/api/mikrotik/firewall/sync/merge-from-router` | Fusionar reglas del router |

### Chat y soporte

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/admin/chat/conversations` | Listar tickets de soporte |
| `GET` | `/api/admin/chat/{ticketId}/messages` | Mensajes de un ticket |
| `POST` | `/api/admin/chat/{ticketId}/messages` | Enviar mensaje (admin) |

### Importación

| Método | Ruta | Descripción |
|--------|------|-------------|
| `POST` | `/api/admin/import/validate` | Validar archivo antes de importar |
| `POST` | `/api/admin/import/process` | Ejecutar importación |

### WebSocket (broadcasting)

| Método | Ruta | Descripción |
|--------|------|-------------|
| `POST` | `/api/broadcasting/auth` | Autenticar canal privado de WebSocket |

---

## Autenticación y autorización

### Laravel Sanctum (tokens API)

- Los tokens se generan en el login y se almacenan en la tabla `personal_access_tokens`
- Cada petición autenticada requiere: `Authorization: Bearer {TOKEN}`
- Los tokens no tienen expiración por defecto (configurable en `config/sanctum.php`)

### RBAC (Control de acceso basado en roles)

El sistema incluye un esquema de roles y permisos:

```
Employee ── belongs to ── Role ── has many ── Permission
```

Los permisos usan el formato `módulo.acción` (ej. `usuarios.crear`, `clientes.ver`) y son generados automáticamente desde la constante `MODULES` en `PermissionSeeder`.

El middleware `permission:{slug}` (clase `CheckPermission`) protege rutas individualmente. Los roles `super_admin` y el permiso `acceso_total` omiten todos los checks.

**Ver [`PERMISOS.md`](./PERMISOS.md) para la guía completa de cómo agregar nuevos módulos, acciones o proteger rutas.**

Para sincronizar la base de datos con la matriz de permisos:

```bash
php artisan db:seed --class=PermissionSeeder
```

### Guards configurados

| Guard | Modelo | Uso |
|-------|--------|-----|
| `employee` | `Employee` | Panel de administración |
| `client` | `Client` | Portal de clientes |

---

## Sistema de colas y jobs

Las operaciones de billing se ejecutan de forma asíncrona mediante jobs de Laravel:

| Job | Cola | Disparado por | Acción |
|-----|------|--------------|--------|
| `GenerateMonthlyInvoices` | `default` | Scheduler (día 1 del mes) | Genera facturas para todos los clientes activos |
| `ProcessClientSuspension` | `suspensions` | Scheduler + eventos | Suspende el servicio de clientes morosos |
| `ProcessAutoReactivation` | `reactivations` | Scheduler + eventos | Reactiva clientes que saldaron su deuda |
| `SyncMikroTikQueues` | `default` | Scheduler (2 veces al día) | Sincroniza planes con colas del router |

### Iniciar workers

```bash
# Procesar todas las colas (desarrollo)
php artisan queue:listen --tries=1

# Worker persistente en producción con colas específicas
php artisan queue:work --queue=suspensions,reactivations,default --tries=3 --sleep=3

# Con Supervisor (recomendado en producción)
# Ver sección de despliegue
```

### Ver estado de las colas

```bash
php artisan queue:monitor
php artisan queue:failed
php artisan queue:retry all
```

---

## WebSocket con Laravel Reverb

Reverb es el servidor WebSocket nativo de Laravel. Se usa para:

- **Chat en tiempo real:** Los mensajes de soporte se transmiten instantáneamente al panel de admin
- **Eventos de clientes:** Notificaciones cuando cambia el estado de un cliente

### Canales definidos (`routes/channels.php`)

```php
// Canal de eventos por cliente
Broadcast::channel('client-events.{clientId}', ...);

// Canal de chat por ticket
Broadcast::channel('chat.{ticketId}', ...);
```

### Iniciar Reverb

```bash
# Desarrollo
php artisan reverb:start

# Producción (en background)
php artisan reverb:start --host=0.0.0.0 --port=8080 --daemon
```

> Las variables `REVERB_APP_KEY`, `REVERB_HOST` y `REVERB_PORT` deben coincidir con `VITE_REVERB_*` del frontend.

---

## Integración con MikroTik

El sistema se conecta al router MikroTik via la **RouterOS API** (puerto TCP 8728) usando el paquete `evilfreelancer/routeros-api-php`.

### Funcionalidades

- **Colas simples:** Sincronización de planes de velocidad con simple queues del router
- **Firewall filter:** Gestión de reglas de filtro con historial y rollback
- **Firewall NAT:** Gestión de reglas de traducción de direcciones
- **Estado de clientes inalámbricos:** Monitoring de dispositivos conectados

### Habilitar la API en MikroTik

Desde la terminal del router o Winbox:

```
/ip service enable api
/ip service set api port=8728
/user add name=ispgestor password=tu-password group=full
```

### Probar la conexión

```bash
php artisan mikrotik:test
```

---

## Facturación automática

El scheduler de Laravel ejecuta automáticamente las tareas de billing. Para que funcione en producción, agrega la entrada al cron del sistema:

```bash
* * * * * cd /ruta/del/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

### Flujo de facturación mensual

```
Día 1 del mes (00:05)
   │
   ▼
GenerateMonthlyInvoices
   │  Crea facturas para todos los clientes con plan activo
   │
   ▼
ProcessAutoBilling (02:00)
   │  Intenta cobrar desde la wallet del cliente
   │  Si hay saldo → paga la factura → client permanece activo
   │  Si no hay saldo → factura queda pendiente
   │
   ▼
ProcessClientSuspension (08:00)
   │  Clientes con facturas vencidas > BILLING_SUSPENSION_GRACE_DAYS días
   │  → Despacha job a la cola "suspensions"
   │  → Actualiza estado en DB + notifica a MikroTik
   │
   ▼
CheckAndReactivate (10:00)
   │  Clientes suspendidos que recargaron saldo
   │  → Paga la factura pendiente
   │  → Restaura servicio en DB + notifica a MikroTik
```

---

## Facturación Ecuador — Cumplimiento SRI

El sistema está adaptado para cumplir la normativa tributaria de Ecuador administrada por el **SRI (Servicio de Rentas Internas)**.

### Marco legal

| Regulación | Descripción |
|---|---|
| **Ley de Régimen Tributario Interno (LRTI)** | Arts. 52-67 rigen el IVA |
| **Decreto Ejecutivo 470** (4 dic 2024) | Fija IVA en 15% |
| **Circular NAC-DGECCGC25-00000006** (26 dic 2025) | Confirma 15% para 2026 |
| **Reglamento de Comprobantes de Venta** | Rige el formato y numeración de facturas |
| **ARCOTEL Resolución 2018-0716** | Normas de facturación para ISPs/telecomunicaciones |

### Parámetros fiscales Ecuador

| Campo | Valor | Descripción |
|---|---|---|
| Autoridad tributaria | SRI | Servicio de Rentas Internas |
| ID tributario | RUC | 13 dígitos numéricos |
| IVA vigente | 15% (`0.15`) | Confirmado para 2026 |
| Moneda | USD | Ecuador dolarizado desde 2000 |
| Zona horaria | `America/Guayaquil` | UTC-5, sin horario de verano |
| Formato factura | `001-001-000000001` | EEE-PPP-SSSSSSSSS (SRI) |
| Numeración | Secuencial estricta | Sin saltos ni reinicios por periodo |

### Configuración en base de datos (`system_settings`)

Los parámetros se editan desde el panel admin en `/configuraciones/facturacion`. Claves clave:

| Clave | Grupo | Descripción | Ejemplo |
|---|---|---|---|
| `issuer_ruc` | issuer | RUC del emisor (13 dígitos) | `1790123456001` |
| `sri_establishment_code` | legal | Código de establecimiento SRI | `001` |
| `sri_emission_point` | legal | Código del punto de emisión SRI | `001` |
| `currency_code` | currency | Código ISO moneda | `USD` |
| `tax_rate` | tax | Tasa IVA en decimal | `0.15` |

### Formato y numeración de facturas

Las facturas se generan con el formato exigido por el SRI:

```
{sri_establishment_code}-{sri_emission_point}-{SSSSSSSSS}

Ejemplo: 001-001-000000001
         ─── ─── ─────────
          ↑   ↑       ↑
        Est. Pto.  Secuencial
                    9 dígitos
```

El secuencial es **estrictamente creciente** por combinación establecimiento+emisión y **nunca se resetea**. No elimine facturas físicamente — use soft delete o cancelación.

### Validación del RUC

El servicio `InvoiceConfigValidator` verifica:
- Exactamente 13 dígitos numéricos
- Dígitos 1-2: código de provincia (01-24)
- Dígitos 11-13: código de establecimiento, distinto de `000`

### Re-ejecutar la configuración inicial de Ecuador

```bash
# Limpia claves de Colombia y crea las de Ecuador
php artisan db:seed --class=SystemSettingsSeeder
```

### Migración desde Colombia

Si el sistema fue configurado para Colombia, el seeder limpia automáticamente:

| Clave eliminada | Reemplazada por |
|---|---|
| `issuer_nit` | `issuer_ruc` |
| `invoice_resolution_number` | `sri_establishment_code` |
| `invoice_resolution_date` | `sri_emission_point` |

---

## Comandos Artisan personalizados

### `php artisan mikrotik:test`

Verifica la conectividad al router MikroTik usando las credenciales del `.env`. Útil para validar la configuración antes de poner en producción.

```bash
php artisan mikrotik:test
# Output: ✓ Conexión exitosa a 192.168.88.1:8728
# Output: ✗ Error: Connection timed out
```

### `php artisan billing:check-reactivate`

Ejecuta manualmente el proceso de revisión y reactivación de clientes suspendidos. Equivalente a la tarea programada `CheckAndReactivate`.

### `php artisan billing:process-auto`

Ejecuta manualmente el proceso de facturación automática. Útil para recuperar ciclos perdidos o para pruebas.

---

## Despliegue en producción

### Requisitos del servidor

- Ubuntu 22.04+ / Debian 12+ (recomendado)
- PHP 8.2 + extensiones requeridas
- MySQL 8.0+
- Nginx o Apache
- Supervisor (para queue workers)
- Certbot / SSL (para HTTPS)

### Pasos de despliegue

```bash
# 1. Clonar y configurar
git clone <repo> /var/www/ispgestorserver
cd /var/www/ispgestorserver
composer install --no-dev --optimize-autoloader

# 2. Configurar entorno
cp .env.example .env
php artisan key:generate
# Editar .env con configuración de producción

# 3. Base de datos
php artisan migrate --force
php artisan db:seed --force   # Solo la primera vez

# 4. Optimizar Laravel
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 5. Permisos de storage
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### Configuración de Supervisor (queue workers)

Crea `/etc/supervisor/conf.d/ispgestor-workers.conf`:

```ini
[program:ispgestor-default]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/ispgestorserver/artisan queue:work --queue=default --tries=3 --sleep=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/ispgestor-default.log

[program:ispgestor-suspensions]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/ispgestorserver/artisan queue:work --queue=suspensions --tries=3 --sleep=5
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/ispgestor-suspensions.log

[program:ispgestor-reactivations]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/ispgestorserver/artisan queue:work --queue=reactivations --tries=3 --sleep=5
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/ispgestor-reactivations.log

[program:ispgestor-reverb]
process_name=%(program_name)s
command=php /var/www/ispgestorserver/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/ispgestor-reverb.log
```

```bash
supervisorctl reread
supervisorctl update
supervisorctl start all
```

### Configuración de Nginx

```nginx
server {
    listen 443 ssl;
    server_name api.tudominio.com;
    root /var/www/ispgestorserver/public;

    ssl_certificate /etc/letsencrypt/live/api.tudominio.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.tudominio.com/privkey.pem;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # WebSocket Reverb
    location /app {
        proxy_pass http://localhost:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### Con Docker

```dockerfile
FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    mysql-client \
    libzip-dev \
    && docker-php-ext-install pdo_mysql zip bcmath pcntl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --optimize-autoloader

RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
```

---

## Troubleshooting

### `php artisan migrate` falla con error de conexión

**Causa:** Credenciales de DB incorrectas o el servidor MySQL no está activo.

```bash
# Verificar variables de DB en .env
grep DB_ .env

# Probar conexión manual
mysql -h 127.0.0.1 -u root -p ispgestorserver
```

---

### Los jobs de la cola no se procesan

**Causa:** El worker de colas no está corriendo.

```bash
# Verificar estado de Supervisor
supervisorctl status

# Iniciar worker manualmente para debug
php artisan queue:listen --tries=1

# Ver jobs fallidos
php artisan queue:failed
php artisan queue:retry all
```

---

### El WebSocket de Reverb no acepta conexiones

**Causa 1:** Reverb no está iniciado.

```bash
php artisan reverb:start
```

**Causa 2:** `BROADCAST_CONNECTION` no está configurado como `reverb` en `.env`.

```bash
grep BROADCAST_CONNECTION .env
# Debe ser: BROADCAST_CONNECTION=reverb
```

**Causa 3:** Las variables `REVERB_APP_KEY` del backend y `VITE_REVERB_APP_KEY` del frontend no coinciden.

---

### Error 419 (CSRF) en peticiones POST

**Causa:** Las rutas de la API no están excluidas de la verificación CSRF.

**Solución:** Las rutas en `routes/api.php` ya están exentas por defecto. Verifica que no hayas movido rutas incorrectamente a `routes/web.php`.

---

### Los clientes no se suspenden automáticamente

**Causa 1:** El cron de `schedule:run` no está configurado en el servidor.

```bash
# Verificar que el cron existe
crontab -l | grep artisan

# Agregar si no existe
* * * * * cd /ruta/del/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

**Causa 2:** `BILLING_SCHEDULE_SUSPEND_TIME` tiene una hora que ya pasó hoy.

```bash
# Ejecutar manualmente para verificar
php artisan billing:check-reactivate
```

---

### La API de MikroTik retorna "Connection refused"

**Causa:** La API RouterOS está deshabilitada en el router.

**Solución en el router MikroTik:**

```
/ip service enable api
/ip service set api port=8728
```

Verifica también que el firewall del router no bloquea el puerto 8728 desde la IP del servidor.

---

### Error "Class not found" después de `composer install`

```bash
composer dump-autoload
php artisan clear-compiled
php artisan optimize
```

---

### Archivos adjuntos no se suben (error de Cloudinary)

**Causa:** `CLOUDINARY_URL` no configurada o credenciales inválidas.

```bash
grep CLOUDINARY_URL .env
```

Verifica el formato: `cloudinary://API_KEY:API_SECRET@CLOUD_NAME`

---

## Contribución

1. Haz fork del repositorio
2. Crea una rama descriptiva: `git checkout -b feature/nombre-funcionalidad`
3. Sigue los estándares de código PSR-12 (puedes usar `composer pint` para formatear)
4. Escribe tests para la nueva funcionalidad
5. Ejecuta la suite de tests: `composer test`
6. Abre un Pull Request con una descripción clara del cambio y su motivación

---

## Licencia

Proyecto privado. Todos los derechos reservados.
