# Lista Blanca de Clientes

Documentación técnica de la funcionalidad de **lista blanca** que protege a clientes específicos de la suspensión automática por facturas vencidas.

---

## 1. Visión general

La lista blanca permite registrar clientes que NO deben ser suspendidos automáticamente por el job `ProcessClientSuspension`, aun cuando tengan facturas vencidas más allá del periodo de gracia.

- **Acceso**: solo empleados con rol `super_admin`.
- **Trazabilidad**: toda alta, baja o modificación queda registrada en la tabla `audits`.
- **Vencimiento**: cada inclusión puede ser permanente o tener fecha de expiración.
- **Defensa en profundidad**: la regla se aplica tanto en el filtrado de candidatos (job) como dentro del `ClientSuspensionService` antes de cualquier escritura.

---

## 2. Estructura de datos

### 2.1 Tabla `client_whitelists`

```
┌────────────────────────────────────────────────────────────────┐
│                      client_whitelists                         │
├────────────────────┬───────────────────────────────────────────┤
│ id                 │ bigint UNSIGNED PK AUTO_INCREMENT         │
│ client_id          │ bigint UNSIGNED  (FK → clients.id)        │
│ added_at           │ timestamp DEFAULT CURRENT_TIMESTAMP       │
│ authorized_by      │ bigint UNSIGNED NULL (FK → employees.id)  │
│ reason             │ text NOT NULL                             │
│ expires_at         │ timestamp NULL                            │
│ active             │ boolean DEFAULT TRUE                      │
│ created_at         │ timestamp                                 │
│ updated_at         │ timestamp                                 │
└────────────────────┴───────────────────────────────────────────┘

Índices:
- idx_whitelist_client          (client_id)
- idx_whitelist_active          (active)
- idx_whitelist_expires         (expires_at)
- idx_whitelist_lookup          (client_id, active)   ← consulta de protección

Foreign keys:
- client_id      → clients(id)      ON DELETE CASCADE
- authorized_by  → employees(id)    ON DELETE SET NULL
```

**Decisiones de diseño:**

- `active` permite la **baja lógica** para preservar el historial. Al retirar a un cliente la fila no se borra, simplemente se marca `active = false`.
- `expires_at` admite NULL para inclusiones permanentes. Las fechas pasadas se consideran inactivas vía el scope `active()` del modelo Eloquent.
- El índice compuesto `(client_id, active)` está optimizado para la consulta más caliente del sistema (`isProtected($clientId)`).

### 2.2 Diagrama de relaciones

```
  ┌───────────────┐                ┌────────────────────┐
  │   employees   │                │      clients       │
  │ (super_admin) │                │                    │
  └───────┬───────┘                └──────────┬─────────┘
          │                                   │
          │ authorized_by                     │ client_id
          │                                   │
          ▼                                   ▼
       ┌─────────────────────────────────────────┐
       │           client_whitelists             │
       │ (id, added_at, reason, expires_at,      │
       │  active)                                │
       └──────────────────┬──────────────────────┘
                          │
                          │ (operación)
                          ▼
                  ┌──────────────────┐
                  │      audits      │
                  │ table_name =     │
                  │ client_whitelists│
                  └──────────────────┘
```

---

## 3. Flujo de validación de suspensión

```
ProcessClientSuspension job
        │
        ▼
┌──────────────────────────────────────┐
│ Query candidatos:                    │
│  status = failed                     │
│  AND due_date <= hoy - grace_days    │
│  AND client.service_status NOT IN    │
│      (suspended, cancelled)          │
│  AND client NOT EXISTS (             │
│        whitelist activa vigente)     │ ◄── filtro a nivel SQL
└──────────────────┬───────────────────┘
                   │
                   ▼
        Por cada factura candidata:
                   │
                   ▼
       Intento final de cobro (AutoBillingService)
                   │
        ┌──────────┴──────────┐
        │                     │
       OK                 falla
        │                     │
        ▼                     ▼
    saltar         ClientSuspensionService::suspendClient()
                              │
                              ▼
              ┌──────────────────────────────────┐
              │ ¿whitelist.isProtected(cliente)? │ ◄── 2.ª verificación
              └──────────┬───────────────────────┘
                   sí    │    no
                   │     │
                   ▼     ▼
        Audit:           Bloqueo MikroTik
        SUSPEND_BLOCKED_  + DB status='suspended'
        WHITELIST        + Audit: SUSPEND_AUTO_OP
        return whitelisted=true
```

### Por qué doble verificación

1. **Filtrado SQL en el job**: evita iterar a clientes protegidos en memoria; reduce drásticamente el trabajo cuando hay muchos morosos protegidos.
2. **Verificación en el servicio**: garantiza que las invocaciones directas a `suspendClient()` (incluso fuera del job, por ejemplo desde un controlador o una rutina manual) respeten la regla.

---

## 4. API administrativa

Todas las rutas están bajo `/api/admin/whitelist` y requieren:

- Cabecera `Authorization: Bearer <employee_token>` (Sanctum).
- Rol `super_admin` en el empleado autenticado (middleware `super_admin`).

| Método | Ruta                                  | Acción                                |
|--------|---------------------------------------|---------------------------------------|
| GET    | `/admin/whitelist?status=active`      | Listar inclusiones.                   |
| GET    | `/admin/whitelist/history?client_id=` | Historial de auditoría.               |
| GET    | `/admin/whitelist/export?status=`     | Exportar CSV.                         |
| GET    | `/admin/whitelist/{id}`               | Detalle de una inclusión.             |
| POST   | `/admin/whitelist`                    | Agregar cliente.                      |
| PUT    | `/admin/whitelist/{id}`               | Actualizar motivo o fecha vencimiento.|
| DELETE | `/admin/whitelist/{id}`               | Retirar al cliente (baja lógica).     |

### Ejemplo: agregar un cliente

```http
POST /api/admin/whitelist
Content-Type: application/json
Authorization: Bearer <token>

{
  "client_id": 1234,
  "reason": "Cliente VIP — acuerdo comercial con gerencia",
  "expires_at": "2026-12-31T23:59:59Z"   // opcional; null = permanente
}
```

**Respuesta 201:**
```json
{
  "data": {
    "id": 7,
    "client_id": 1234,
    "client": { "id": 1234, "full_name": "...", "document_id": "..." },
    "added_at": "2026-05-24T15:32:01+00:00",
    "authorized_by": 2,
    "authorizer": { "id": 2, "nombre": "Ana López", "email": "ana@isp.com" },
    "reason": "Cliente VIP — acuerdo comercial con gerencia",
    "expires_at": "2026-12-31T23:59:59+00:00",
    "active": true,
    "is_valid": true
  }
}
```

### Códigos de error

| Status | Código                       | Causa                                         |
|--------|------------------------------|-----------------------------------------------|
| 401    | —                            | Sin autenticación.                            |
| 403    | `FORBIDDEN`                  | El empleado no es super_admin.                |
| 409    | `WHITELIST_ALREADY_EXISTS`   | El cliente ya tiene inclusión vigente.        |
| 409    | `WHITELIST_NOT_ACTIVE`       | Intento de retirar a un cliente no incluido.  |
| 422    | (Laravel validation)         | Datos inválidos (reason corto, cliente etc.). |

---

## 5. Gestión desde la interfaz administrativa

**Ruta del panel:** `Configuraciones → Lista Blanca de Clientes` (`/configuraciones/whitelist`).

### 5.1 Agregar un cliente
1. Pulsar **"Agregar cliente"**.
2. Introducir el `ID` numérico del cliente.
3. Escribir el **motivo** (mínimo 5 caracteres).
4. *(Opcional)* Establecer una **fecha de vencimiento**. Si se deja vacía, la inclusión es **permanente**.
5. Confirmar. El cliente queda excluido del próximo ciclo de suspensión.

### 5.2 Retirar un cliente
1. Pulsar el icono **papelera** en la fila correspondiente.
2. *(Opcional)* Indicar el motivo del retiro.
3. La fila se desactiva pero permanece visible en el filtro "Inactivas / Vencidas" y en el historial.

### 5.3 Editar una inclusión
1. Pulsar el icono **lápiz**.
2. Modificar el motivo y/o la fecha de vencimiento.
3. Guardar. La acción queda registrada como `WHITELIST_UPDATE` en auditoría.

### 5.4 Ver el historial
- El botón **"Historial"** (cabecera) muestra todas las operaciones de la lista.
- El icono **historial** por fila filtra al historial del cliente concreto. Allí también se ven los eventos `SUSPEND_BLOCKED_WHITELIST` (intentos de suspensión que fueron bloqueados).

### 5.5 Exportar CSV
- Botón **"Exportar CSV"** descarga las inclusiones según el filtro de estado actual.
- El CSV se genera en streaming (no carga toda la tabla en memoria) y se nombra `lista_blanca_YYYYMMDD_HHMMSS.csv`.
- Incluye BOM UTF-8 para abrirse correctamente en Excel.

---

## 6. Seguridad y auditoría

### 6.1 Permisos
- El middleware `super_admin` (alias de `EnsureEmployeeSuperAdmin`) bloquea cualquier petición de un usuario que no sea un `Employee` con `role.slug = 'super_admin'`.
- Las operaciones del servicio (`addClient`, `removeClient`, `updateEntry`) requieren un `Employee` válido como parámetro y no aceptan operaciones anónimas; esto previene que jobs o seeders escriban en la lista sin atribución.

### 6.2 Eventos auditados

Todas se guardan en la tabla `audits`:

| Operación                     | `table_name`        | Disparador                                 |
|-------------------------------|---------------------|--------------------------------------------|
| `WHITELIST_ADD`               | `client_whitelists` | Inclusión de un cliente.                   |
| `WHITELIST_UPDATE`            | `client_whitelists` | Cambio de motivo o vencimiento.            |
| `WHITELIST_REMOVE`            | `client_whitelists` | Baja lógica (`active = false`).            |
| `SUSPEND_BLOCKED_WHITELIST`   | `clients`           | El motor de suspensión fue bloqueado.      |

Cada registro guarda:
- `user_id` + `user_type` → empleado responsable (o `null` y `system_auto` para bloqueos automáticos).
- `ip_address` → IP del request (o `127.0.0.1` para eventos del scheduler).
- `old_values` / `new_values` → snapshot antes y después del cambio.
- `created_at` → marca temporal inmutable.

---

## 7. Mantenimiento

### 7.1 Tareas recomendadas

- **Revisión periódica**: filtrar la lista por inclusiones permanentes (`expires_at IS NULL`) para validar que siguen vigentes con el área comercial.
- **Limpieza de inactivas**: la baja es lógica; no se borran filas. Si en el futuro se considera necesario, programar un job que purgue `client_whitelists.active = false AND updated_at < now() - 24 months`.
- **Vencimientos automáticos**: hoy se evalúan **on the fly** (la consulta excluye filas cuya `expires_at` ya pasó). No se requiere job adicional, pero si se desea generar un evento de auditoría al expirar puede crearse un comando periódico.

### 7.2 Resolución de problemas

| Síntoma                                          | Diagnóstico                                                                 |
|--------------------------------------------------|-----------------------------------------------------------------------------|
| Un cliente protegido fue suspendido              | Buscar en `audits` el registro `SUSPEND_AUTO_OP` con su `client.id`. Comprobar `client_whitelists.active` y `expires_at` en el momento `created_at` del audit. |
| `POST /admin/whitelist` responde 409             | Ya existe una inclusión activa (`active = true` y vigente). Use `PUT` para editar. |
| El job sigue intentando suspender                | El filtro SQL del job evita el intento. Si aparece igualmente en logs, revisar `whereDoesntHave` en `ProcessClientSuspension`. |
| El CSV se ve corrupto en Excel                   | El controlador emite BOM UTF-8. Si persiste, verificar que ningún proxy reescriba la respuesta.                              |

### 7.3 Comandos útiles

```bash
# Validar que las rutas se cargan bien (puede haber caché)
php artisan route:clear
php artisan route:list | grep whitelist

# Ejecutar la suite de tests específica
php artisan test --filter=ClientWhitelist

# Re-correr migraciones en local
php artisan migrate:fresh --seed
```

### 7.4 Archivos relevantes

| Capa             | Archivo                                                                   |
|------------------|---------------------------------------------------------------------------|
| Migración        | `database/migrations/2026_05_25_000001_create_client_whitelists_table.php`|
| Modelo           | `app/Models/ClientWhitelist.php`                                          |
| Servicio         | `app/Services/ClientWhitelistService.php`                                 |
| Integración      | `app/Services/ClientSuspensionService.php`                                |
| Job (filtrado)   | `app/Jobs/ProcessClientSuspension.php`                                    |
| Controlador      | `app/Http/Controllers/Admin/ClientWhitelistController.php`                |
| Rutas            | `routes/api.php` (`Route::prefix('whitelist')->middleware('super_admin')`)|
| Frontend         | `ispgestoradmin/src/routes/configuraciones/whitelist/+page.svelte`        |
| Cliente API JS   | `ispgestoradmin/src/lib/api/whitelist.ts`                                 |
| Tipos JS         | `ispgestoradmin/src/lib/types/whitelist.ts`                               |
| Tests            | `tests/Feature/ClientWhitelistTest.php`                                   |
| Tests servicio   | `tests/Feature/ClientWhitelistServiceUnitTest.php`                        |
