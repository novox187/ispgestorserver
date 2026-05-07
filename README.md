# ISPgestor Server (Backend)

[![Status](https://img.shields.io/badge/status-en%20desarrollo-yellow)](#)
[![PHP](https://img.shields.io/badge/PHP-%5E8.2-777bb4?logo=php&logoColor=white)](#requisitos-previos)
[![Laravel](https://img.shields.io/badge/Laravel-%5E12-ff2d20?logo=laravel&logoColor=white)](https://laravel.com/)
[![Vite](https://img.shields.io/badge/Vite-%5E7-646cff?logo=vite&logoColor=white)](https://vitejs.dev/)

Backend API y servicios del sistema ISPgestor. Implementa lógica de negocio para administración de clientes/planes/facturación, y orquesta integraciones (p. ej., MikroTik RouterOS y almacenamiento en Cloudinary).

## Índice

- [Descripción](#descripción)
- [Stack](#stack)
- [Requisitos previos](#requisitos-previos)
- [Instalación](#instalación)
- [Configuración](#configuración)
- [Comandos](#comandos)
- [Seeders](#seeders)
- [Comandos de consola (Artisan)](#comandos-de-consola-artisan)
- [Estructura de carpetas](#estructura-de-carpetas)
- [Ejemplos de uso](#ejemplos-de-uso)
- [Contribución](#contribución)
- [Solución de problemas](#solución-de-problemas)
- [Seguridad](#seguridad)

## Descripción

Este proyecto contiene el backend (Laravel) que expone endpoints HTTP para el ecosistema ISPgestor (por ejemplo, el panel `ispgestoradmin`). También ejecuta jobs en cola y tareas de soporte (migraciones, seeds, imports, etc.).

## Stack

- Framework: Laravel (PHP)
- Auth API: Laravel Sanctum
- Base de datos: MySQL/MariaDB (por defecto en `.env.example`)
- Cola/Jobs: `QUEUE_CONNECTION=database` (por defecto en `.env.example`)
- Assets (para vistas internas si aplica): Vite + Tailwind (configurados en este repo)
- Integraciones:
  - MikroTik RouterOS (RouterOS API)
  - Cloudinary (filesystem disk `cloudinary`)

## Requisitos previos

- PHP 8.2+
- Composer 2.x
- Node.js 18+ y npm (o pnpm/yarn) para assets
- MySQL 8+ / MariaDB
- Extensiones PHP típicas para Laravel (PDO, OpenSSL, Mbstring, Tokenizer, XML, Ctype, JSON, etc.)

Opcional (según entorno):
- Redis/Memcached (si se habilitan en `CACHE_STORE` / `REDIS_*`)
- Credenciales y acceso a MikroTik RouterOS (si se habilita)
- Cuenta y credenciales de Cloudinary (si se usa el disk `cloudinary`)

## Instalación

1. Instalar dependencias PHP:

```bash
composer install
```

2. Crear el archivo de entorno:

```bash
cp .env.example .env
```

En Windows (PowerShell):

```powershell
Copy-Item .env.example .env
```

3. Generar clave de aplicación:

```bash
php artisan key:generate
```

4. Instalar dependencias de frontend (solo si vas a compilar assets):

```bash
npm install
```

5. Configurar base de datos en `.env` y ejecutar migraciones:

```bash
php artisan migrate
```

Notas:
- Si `QUEUE_CONNECTION=database`, asegúrate de que existan las tablas de jobs/failed jobs según las migraciones del proyecto.
- Para servir archivos públicos, en entornos locales puede ser útil:

```bash
php artisan storage:link
```

## Configuración

### Variables de entorno

La configuración base se toma de `.env` (ver `.env.example`). Además, existen variables adicionales usadas por integraciones.

| Variable | Ejemplo | Requerida | Descripción |
|---|---:|:---:|---|
| `APP_ENV` | `local` | Sí | Entorno de ejecución (`local`, `staging`, `production`, etc.). |
| `APP_KEY` | (auto) | Sí | Clave de Laravel para cifrado y sesiones. |
| `APP_URL` | `http://localhost` | Sí | URL base de la app (útil para links/storage). |
| `DB_CONNECTION` | `mysql` | Sí | Driver de DB. |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | Sí | Host/puerto de DB. |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `ispgestorserver` | Sí | Credenciales de DB. |
| `QUEUE_CONNECTION` | `database` | No | Driver de cola (por defecto database). |
| `CACHE_STORE` | `database` | No | Store de cache (por defecto database). |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost` | Depende | Necesaria si se usa Sanctum con cookies/SPA en el mismo dominio. |

#### MikroTik (RouterOS)

Se configura vía `config/mikrotik.php`.

| Variable | Ejemplo | Requerida | Descripción |
|---|---:|:---:|---|
| `MIKROTIK_ENABLED` | `true` | No | Habilita integración. En CI/dev puede desactivarse. |
| `MIKROTIK_HOST` | `10.0.0.2` | Sí* | Host/IP del router. |
| `MIKROTIK_PORT` | `8728` | No | Puerto de API. |
| `MIKROTIK_USER` | `router_user` | Sí* | Usuario RouterOS API. |
| `MIKROTIK_PASS` | (secreto) | Sí* | Contraseña RouterOS API. |
| `MIKROTIK_TIMEOUT` | `2` | No | Timeout en segundos. |
| `MIKROTIK_ATTEMPTS` / `MIKROTIK_DELAY` | `1` / `0` | No | Reintentos/delay. |

\* Requerida si `MIKROTIK_ENABLED=true`.

#### Cloudinary (uploads y storage)

Se configura vía `config/cloudinary.php` y `config/filesystems.php` (disk `cloudinary`).

| Variable | Ejemplo | Requerida | Descripción |
|---|---:|:---:|---|
| `CLOUDINARY_URL` | `cloudinary://...` | Sí* | URL completa (recomendado). |
| `CLOUDINARY_CLOUD_NAME` | `mi-cloud` | Sí* | Nombre de cloud. |
| `CLOUDINARY_KEY` | `...` | Sí* | API key. |
| `CLOUDINARY_SECRET` | (secreto) | Sí* | API secret. |
| `CLOUDINARY_SECURE` | `true` | No | Forzar HTTPS. |
| `CLOUDINARY_PREFIX` | `...` | No | Prefijo/opcional. |
| `CLOUDINARY_NOTIFICATION_URL` | `https://...` | No | Webhook de notificación. |
| `CLOUDINARY_UPLOAD_PRESET` | `...` | No | Upload preset de Cloudinary. |

\* Requerida si se usa el disk `cloudinary` o endpoints que suben/transforman medios.

## Comandos

### Desarrollo (recomendado)

Levanta servidor HTTP, worker de cola, logs (Pail) y Vite en paralelo:

```bash
composer run dev
```

Alternativa (manual):

```bash
php artisan serve
php artisan queue:listen --tries=1
npm run dev
```

### Producción (referencial)

Instalar dependencias optimizadas y compilar assets:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

Preparar caches de Laravel:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Ejecutar migraciones en producción:

```bash
php artisan migrate --force
```

Ejecutar workers:

```bash
php artisan queue:work --tries=1
```

Nota: en producción se recomienda servir `public/` con Nginx/Apache o PHP-FPM en lugar de `php artisan serve`.

### Tests y calidad

```bash
composer run test
```

Formateo (Pint):

```bash
./vendor/bin/pint
```

### Base de datos (migraciones/seeders)

Re-crear el esquema desde cero y poblarlo con datos de ejemplo:

```bash
php artisan migrate:fresh --seed
```

Ejecutar seeders sin recrear el esquema:

```bash
php artisan db:seed
```

Ejecutar un seeder específico:

```bash
php artisan db:seed --class=Database\\Seeders\\PlanSeeder
```

## Seeders

Los seeders viven en `database/seeders/` y se orquestan desde `DatabaseSeeder`.

Orden actual de carga (resumen):

- Permisos: `PermissionSeeder`
- Roles y administradores: `RolSeeder`, `RolePermissionSeeder`, `AdministradorSeeder`
- Planes y características: `PlanSeeder`, `PlanFeatureSeeder`
- Clientes y relación cliente-plan: `ClientSeeder`, `ClientPlanSeeder`
- Billeteras y transacciones: `WalletSeeder`, `TransactionSeeder`
- Tickets/soporte: `SupportSeeder`

Uso recomendado para un entorno limpio de desarrollo:

```bash
php artisan migrate:fresh --seed
```

## Comandos de consola (Artisan)

Este proyecto incluye comandos personalizados además de los estándar de Laravel. Para verlos todos:

```bash
php artisan list
```

### MikroTik

Probar conectividad y, opcionalmente, permisos de escritura en `/queue/simple`:

```bash
php artisan mikrotik:test --write-test
```

Mostrar stack trace completo (útil en entornos de CI/producción):

```bash
php artisan mikrotik:test --trace
```

Sincronizar Simple Queues (planes y clientes) con el router:

```bash
php artisan mikrotik:sync-queues
```

Sincronizar y además eliminar colas huérfanas (solo si estás seguro):

```bash
php artisan mikrotik:sync-queues --cleanup
```

### Facturación automática

Proceso completo (genera facturas y procesa pagos automáticos):

```bash
php artisan billing:process
```

Solo generar facturas:

```bash
php artisan billing:process --generate-invoices
```

Solo procesar pagos:

```bash
php artisan billing:process --process-payments
```

Procesar solo un cliente:

```bash
php artisan billing:process --client-id=123
```

### Otros

```bash
php artisan inspire
```

## Estructura de carpetas

Estructura principal (Laravel):

```text
ispgestorserver/
├─ app/                 # Lógica de dominio: Models, Services, Controllers, etc.
├─ bootstrap/           # Bootstrap Laravel
├─ config/              # Configuración (mikrotik, cloudinary, sanctum, etc.)
├─ database/            # Migraciones, factories, seeders
├─ public/              # Document root (deploy)
├─ resources/           # Assets (js/css) para Vite (si aplica)
├─ routes/              # api.php, web.php, console.php
├─ storage/             # Logs, cache, archivos
├─ tests/               # Tests
└─ composer.json        # Dependencias PHP y scripts
```

## Ejemplos de uso

### Consumir la API desde un cliente HTTP

Ejemplo genérico de llamada autenticada (token Bearer):

```bash
curl -H "Authorization: Bearer <TOKEN>" \
  -H "Accept: application/json" \
  http://localhost:8000/api/admin/clientes/summary
```

## Contribución

- Mantén cambios pequeños y enfocados.
- Antes de abrir un PR:
  - Ejecuta tests: `composer run test`
  - Ejecuta formateo: `./vendor/bin/pint`
- No subas archivos `.env` ni credenciales.
- Si agregas una variable de entorno nueva, actualiza `.env.example` y este README.

## Solución de problemas

| Problema | Causa probable | Solución |
|---|---|---|
| `APP_KEY` vacío / error de cifrado | `.env` sin clave | `php artisan key:generate` |
| `SQLSTATE[HY000] [1045]` | Credenciales DB incorrectas | Verifica `DB_*` y acceso a MySQL |
| Jobs no se procesan | Worker detenido o tablas faltantes | Ejecuta `php artisan queue:work` y revisa migraciones de jobs |
| 401/CSRF con Sanctum | Dominios stateful mal configurados | Ajusta `SANCTUM_STATEFUL_DOMAINS` y CORS según tu arquitectura |
| Error al subir archivos a Cloudinary | Variables Cloudinary incompletas | Configura `CLOUDINARY_*` y revisa el disk en `FILESYSTEM_DISK` |
| Timeouts con MikroTik | Red/credenciales/puerto | Verifica `MIKROTIK_*`, conectividad y habilitación de API RouterOS |

## Seguridad

- No publiques secretos en repositorios (DB, Cloudinary, MikroTik).
- En producción: `APP_DEBUG=false` y logs con nivel adecuado.
- Rota credenciales ante sospecha de exposición.
- Asegura permisos correctos para `storage/` y `bootstrap/cache/`.
