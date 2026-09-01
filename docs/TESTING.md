# Suite de Tests — Estado, ejecución y mantenimiento

## Resumen

- Framework: **Pest** sobre PHPUnit (Laravel 12).
- Suites declaradas en `phpunit.xml`: `Unit` y `Feature`.
- Base de datos de prueba: MySQL (ver nota más abajo).
- Resultado actual: **330 tests pasando, 1093 aserciones, ~80 segundos**.

```bash
DB_CONNECTION=mysql DB_DATABASE=ispgestor_test php artisan test
```

> **`phpunit.xml` miente sobre la base de datos.** Declara `sqlite`/`:memory:`,
> pero esta máquina no tiene `pdo_sqlite`, así que la suite se corre contra
> MySQL con el override de arriba. Sin él, `php artisan test` a secas falla.

> **Ruido de PHP 8.5.** Todos los tests aparecen marcados como `DEPR` por
> `PDO::MYSQL_ATTR_SSL_CA`, que se emite desde
> `vendor/laravel/framework/config/database.php:64`. Es ajeno al proyecto y no
> indica ningún fallo: lo que importa es que no haya líneas `FAIL`.

## Configuración crítica para que la suite sea estable

| Archivo | Cambio | Por qué |
|---|---|---|
| `phpunit.xml` | Añadido `<env name="MIKROTIK_ENABLED" value="false"/>` | Evita que el provider intente conectar al router real (lo cual añadía hasta 90 s/test por timeouts). |
| `tests/CreatesApplication.php` | `$app['config']->set('mikrotik.enabled', false)` después de `bootstrap()` | Necesario cuando la configuración está cacheada (`bootstrap/cache/config.php`): los `env()` ya están "horneados" y `phpunit.xml` no los puede sobreescribir. |
| `tests/Pest.php` | Añadidos los helpers globales `makeSuperAdminEmployee()` y `seedValidInvoiceConfig()` | Centralizan la creación de un empleado con el rol `super_admin` (necesario para pasar el middleware `permission:*`) y la siembra de configuración de facturación válida. |

> **Cache de configuración.** Si modificas `.env`, ejecuta `php artisan config:clear` y luego `php artisan config:cache` para que la suite vea los nuevos valores. Sin esto, los tests usarán los valores cacheados.

## Cambios a archivos de prueba

### Unit

#### `tests/Unit/InvoiceConfigValidatorTest.php`
- **Causa raíz**: el test seteaba claves obsoletas (`issuer_nit`, `invoice_resolution_number`, `invoice_resolution_date`) que el `InvoiceConfigValidator` actual ya no requiere. El validador real espera `issuer_ruc`, `sri_establishment_code` y `sri_emission_point`.
- **Cambios**:
  - `uses(RefreshDatabase::class)` → `uses(Tests\TestCase::class, RefreshDatabase::class)` (necesario porque los tests Unit por defecto no extienden el `TestCase` de Laravel).
  - Sustituido el seed de `seedAllRequired()` por las claves vigentes (RUC ecuatoriano de 13 dígitos, códigos SRI de 3 dígitos).
  - Reemplazados los dos tests que validaban `invoice_resolution_date` por dos equivalentes que validan los códigos SRI (`000` y formatos inválidos).
  - Renombrado el test final ("acepta fecha de resolución de hoy") por uno que afirma que códigos SRI dentro de rango son válidos.

#### `tests/Unit/MikroTikQueueNameTest.php`
- **Causa raíz**: el constructor de `MikroTikQueueSyncService` ahora requiere un segundo argumento (`IspCapacityService`), y los tests también disparaban una warning de PHP 8.5 por `ReflectionMethod::setAccessible(true)` (no necesario desde PHP 8.1 para métodos del propio servicio).
- **Cambios**:
  - Inyectado `new IspCapacityService($mikrotik)` como segundo argumento.
  - Eliminadas las llamadas a `setAccessible(true)`.
  - Añadido `uses(Tests\TestCase::class)` para que el contenedor de Laravel esté disponible (el método `buildClientQueueName` registra logs vía `Log::warning`, lo que necesita la facade resuelta).

#### `tests/Unit/IspCapacityParsingTest.php`
- Eliminadas las llamadas a `setAccessible(true)` que disparaban deprecations en PHP 8.5.

### Feature

#### `tests/Feature/MikroTikClientSyncTest.php`
- Mismo problema del constructor que `MikroTikQueueNameTest`: añadido `IspCapacityService` como segundo argumento en los cuatro tests.

#### `tests/Feature/Admin/PlanCapacityAssignedPlansTest.php`
#### `tests/Feature/ClientPlanCapacityTest.php`
#### `tests/Feature/Admin/InvoiceAutoGenerateTest.php`
#### `tests/Feature/Admin/ClientMikroTikSyncHttpTest.php`
- **Causa raíz**: todos creaban el empleado con `Employee::factory()->create()` sin rol. El middleware `permission:*` aplica a esas rutas y rechaza con **403** cuando el empleado no tiene rol ni la permisión específica. Los tests anteriores que sí pasaban (p.ej. `InvoiceConfigCheckTest`, `AutomationControllerTest`) siempre creaban un `Role` con slug `super_admin` para bypassar el middleware.
- **Cambio**: reemplazado `Employee::factory()->create()` por el helper global `makeSuperAdminEmployee()` declarado en `tests/Pest.php`.

#### `tests/Feature/Admin/ClientMikroTikSyncHttpTest.php` (extra)
- El test "revierte cambios en DB si falla la sincronización..." disparaba 409 (`ISP_CAPACITY_EXHAUSTED`) en lugar del 503 esperado porque el flujo `update()` del controlador valida la capacidad ISP global. Sin `IspConnection` sembrada el `remaining_down_mbps = 0` y bloquea cualquier cambio de plan.
- **Cambio**: añadido el helper local `seedAmpleIspCapacity()` (1 Gbps simétrico) y llamada al inicio del test.

#### `tests/Feature/Admin/InvoiceAutoGenerateTest.php` (extra)
- El endpoint `generate-auto` pre-valida la configuración de facturación y devuelve **422** si falta. El test no sembraba ninguna configuración.
- **Cambio**: invoca `seedValidInvoiceConfig()` (helper global de `tests/Pest.php`) antes del acto de prueba.

## Helpers globales (`tests/Pest.php`)

- **`makeSuperAdminEmployee(array $attributes = []): Employee`** — `firstOrCreate` el `Role` con slug `super_admin` y crea un `Employee` con ese `role_id`. El middleware `CheckPermission` hace short-circuit si el empleado tiene el rol `super_admin`, eliminando la necesidad de poblar permisos uno a uno en cada test.
- **`seedValidInvoiceConfig(): void`** — inserta exactamente las 12 claves que `InvoiceConfigValidator::REQUIRED` exige, con valores válidos. Reutilizable por cualquier test que dispare un endpoint de facturación.
- **`makeProvisioningAgent(role, capabilities, attributes): array`** — crea un agente de aprovisionamiento ya enrolado y devuelve `['agent', 'token', 'secret']`. Las credenciales en claro solo existen aquí: son necesarias para poder firmar peticiones desde un test.
- **`signedAgentHeaders(enrolled, method, uri, body, overrides): array`** — cabeceras HMAC válidas. `overrides` permite romper una a propósito (`secret`, `nonce`, `timestamp`, `token`, `signature`) para ejercitar los rechazos del middleware.
- **`driveProvisioningFlow(test, provisioner, vpnHost, failAt, maxSteps): array`** — conduce un alta completa simulando a los dos agentes por HTTP contra los endpoints reales. Devuelve los tipos de tarea ejecutados en orden. `failAt` mapea tipo de tarea → fallo, para ejercitar la compensación.
- **`fakeAgentTaskResult(type, overrides): array`** — resultado plausible de cada tipo de tarea.

## Aislamiento entre tests

Cada test Feature/Unit que toca la base de datos usa `RefreshDatabase`, lo que envuelve cada prueba en una transacción que se revierte al final — garantizando aislamiento incluso cuando se reutiliza una base de datos MySQL persistente (caso actual). No es necesario truncar tablas manualmente.

Los mocks de `MikroTikQueueSyncService` se registran con `app()->instance(...)` y se descartan al final del test gracias a Laravel + Mockery (no se filtran entre tests).

## Cuando agregues una nueva funcionalidad...

1. Si tu endpoint usa `permission:*` y no quieres ejercitar el sistema de permisos, autentica con `$this->actingAs(makeSuperAdminEmployee(), 'sanctum')`.
2. Si tu test toca facturación y no quieres ejercitar la validación de configuración, llama a `seedValidInvoiceConfig()` antes.
3. Si tu test toca creación/edición de clientes con cambio de plan y no quieres ejercitar la validación de capacidad ISP, usa el patrón `seedAmpleIspCapacity()` de `ClientMikroTikSyncHttpTest.php` o adapta uno similar.
4. **Nunca** dejes que un test dependa del router MikroTik real. Mockea siempre `MikroTikQueueSyncService` con `app()->instance(MikroTikQueueSyncService::class, $mock)`.
5. Si tu test toca el alta automática de dispositivos, usa `makeProvisioningAgent()` + `driveProvisioningFlow()` y mockea `MikrotikHealthChecker` — es lo único del flujo que exige un router real al otro lado.
6. Antes de pushear, corre `DB_CONNECTION=mysql DB_DATABASE=ispgestor_test php artisan test` y verifica que sigan 330+ pasando.
