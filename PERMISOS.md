# Sistema de Permisos — Iron Link ISP

## Arquitectura

El sistema usa un modelo **Rol → Permisos** donde:

- Cada `Employee` tiene un `Role`
- Cada `Role` tiene muchos `Permission` (tabla pivote `role_permission`)
- Los permisos se generan automáticamente desde un archivo de configuración
- **No** se crean permisos manualmente desde la UI

---

## Formato de slug

```
{módulo}.{acción}
```

Ejemplos: `usuarios.ver`, `clientes.crear`, `facturas.eliminar`, `mikrotik.gestionar`

---

## Módulos y acciones disponibles

| Módulo         | ver | crear | editar | eliminar | gestionar |
|---------------|-----|-------|--------|----------|-----------|
| usuarios       | ✓   | ✓     | ✓      | ✓        |           |
| clientes       | ✓   | ✓     | ✓      | ✓        |           |
| planes         | ✓   | ✓     | ✓      | ✓        |           |
| facturas       | ✓   | ✓     | ✓      | ✓        |           |
| mikrotik       | ✓   |       |        |          | ✓         |
| proveedores    | ✓   | ✓     | ✓      | ✓        |           |
| soporte        | ✓   |       |        |          | ✓         |
| configuracion  | ✓   |       |        |          | ✓         |

---

## Permisos especiales

| Slug           | Descripción                                     |
|----------------|-------------------------------------------------|
| `acceso_total` | Omite todos los checks. Equivale a super_admin. |

El rol `super_admin` (slug exacto) también omite todos los checks sin necesitar permiso alguno.

---

## Cómo agregar un nuevo módulo

### 1. Actualizar el frontend (fuente de verdad visual)

Archivo: `ispgestoradmin/src/lib/config/permissions.ts`

```typescript
export const PERMISSION_MODULES = [
    // ... módulos existentes ...
    { slug: 'reportes', label: 'Reportes', actions: ['ver', 'exportar'] },
    //            ↑ nuevo módulo
];
```

### 2. Actualizar el backend (fuente de verdad para la BD)

Archivo: `database/seeders/PermissionSeeder.php` → constante `MODULES`

```php
private const MODULES = [
    // ... módulos existentes ...
    ['slug' => 'reportes', 'label' => 'Reportes', 'actions' => ['ver', 'exportar']],
];
```

> **Regla:** `MODULES` en el seeder y `PERMISSION_MODULES` en el frontend deben estar siempre sincronizados.

### 3. Ejecutar el seeder

```bash
php artisan db:seed --class=PermissionSeeder
```

Esto creará `reportes.ver` y `reportes.exportar` en la tabla `permissions`.

### 4. Proteger las rutas del backend

Archivo: `routes/api.php`

```php
Route::get('/reportes', [ReporteController::class, 'index'])
    ->middleware('permission:reportes.ver');

Route::post('/reportes/export', [ReporteController::class, 'export'])
    ->middleware('permission:reportes.exportar');
```

### 5. Proteger la UI del frontend

```svelte
{#if auth.can('reportes', 'ver')}
    <!-- Mostrar link o sección de reportes -->
{/if}

{#if auth.can('reportes', 'exportar')}
    <button>Exportar</button>
{/if}
```

---

## Cómo agregar una nueva acción a un módulo existente

Si quieres agregar `archivar` al módulo `facturas`:

1. En `permissions.ts` agregar `'archivar'` al array `actions` de `facturas`
2. En `PermissionSeeder.php` agregar `'archivar'` al array `actions` de `facturas`
3. Ejecutar `php artisan db:seed --class=PermissionSeeder`
4. Aplicar `->middleware('permission:facturas.archivar')` en la ruta correspondiente
5. Usar `auth.can('facturas', 'archivar')` en el frontend

---

## Middleware disponible

| Middleware           | Efecto                                          |
|----------------------|-------------------------------------------------|
| `permission:{slug}`  | Verifica el permiso exacto (o bypasses)         |
| `super_admin`        | Solo pasa empleados con rol slug `super_admin`  |
| `auth:sanctum`       | Solo verifica autenticación, sin permisos       |

Implementación: `app/Http/Middleware/CheckPermission.php`

---

## Bypasses automáticos

`CheckPermission` permite el acceso sin verificar el permiso específico cuando:

1. El empleado tiene el rol `super_admin`
2. El empleado tiene el permiso `acceso_total`

Esto permite crear un rol de "administrador completo" sin listar cada permiso individualmente.

---

## Frontend: función `auth.can()`

```typescript
// Uso correcto
auth.can('usuarios', 'crear')      // → true/false
auth.can('clientes', 'ver')        // → true/false
auth.can('mikrotik', 'gestionar')  // → true/false

// Internamente verifica: permissions.includes('usuarios.crear')
// Con bypasses para super_admin y acceso_total
```

Archivo: `src/lib/stores/auth.svelte.ts`

---

## Flujo completo de permisos

```
Login
  └─ Backend retorna permissions[] (slugs del rol)
       └─ auth.save() persiste en localStorage
            └─ auth.can('modulo', 'accion') consulta el array
                 └─ UI muestra/oculta elementos

Request a API
  └─ middleware('permission:modulo.accion')
       └─ CheckPermission verifica el empleado autenticado
            └─ 403 si no tiene permiso
```
