# Facturación y cortes de servicio — fecha límite de emisión

## Problema que se corrige

La facturación automática decidía la elegibilidad de un cliente mirando **solo su
`service_status` actual**. La fecha del corte no existía como dato estructurado
(solo quedaba enterrada en JSON de auditoría), lo que producía dos inconsistencias:

1. **Retro-facturación de periodos cortados.** `generateInvoicesByContractDate()`
   genera todos los ciclos desde `contract_date` hasta hoy. Un cliente suspendido
   dos meses y luego reactivado volvía a ser "activo", y la corrida retroactiva le
   emitía facturas de los meses en que el servicio estuvo cortado.
2. **Estados inconsistentes facturaban.** Si el estado quedaba desincronizado
   (p. ej. un corte aplicado en MikroTik/auditoría pero el estado en `clients`
   seguía `ACTIVE` por un cambio manual o un fallo parcial), el cliente se
   facturaba con normalidad porque no había ninguna fecha contra la cual validar.

## Diseño de la solución

### 1. Registro estructurado de ventanas de corte

Nueva tabla **`client_service_interruptions`** (migración
`2026_07_04_000001_create_client_service_interruptions_table.php`, modelo
`App\Models\ClientServiceInterruption`). Cada fila es un periodo
`[suspended_at, reactivated_at)` en el que el cliente **no consume** el servicio:

| Columna | Significado |
|---|---|
| `type` | `suspension` (impago) o `cancellation` (baja) |
| `suspended_at` | Fecha del corte — **la fecha límite de facturación** |
| `reactivated_at` | Fecha de reactivación; `NULL` = corte vigente |
| `suspension_reason` / `reactivation_reason` | Razón de negocio de cada extremo |
| `suspended_by` / `reactivated_by` | Ejecutor (`system_auto`, `employee:{id}`, …) |
| `invoice_id` | Factura que originó el corte, si aplica |
| `source` | `auto`, `manual`, `status_change`, `backfill` |

La migración incluye un **backfill**: los clientes que ya estaban
suspendidos/cancelados al desplegar reciben una ventana abierta cuya fecha se
recupera de la auditoría (`SUSPEND_AUTO_OP`, `SUSPEND_TECH_OP`, `CANCEL_OP`) o,
en su defecto, de `updated_at`.

El modelo usa el trait `Auditable`: cada apertura/cierre de ventana queda además
en la tabla `audits`.

### 2. Registro automático ante cualquier cambio de estado

`App\Observers\ClientServiceStatusObserver` (registrado en `AppServiceProvider`)
escucha los eventos de Eloquent del modelo `Client`:

- Transición a estado no facturable (`SUSPENDED`/`CANCELLED`, variantes ES/EN):
  **abre** una ventana (idempotente: nunca hay dos abiertas).
- Transición fuera de esos estados: **cierra** las ventanas abiertas con
  `reactivated_at = now()`.
- `suspendido → cancelado`: la ventana continúa, solo cambia a `cancellation`.
- Cliente **creado** ya suspendido: también abre ventana.

Esto cubre **todas las vías**, incluidas las manuales: `ClientSuspensionService`
(suspensión/baja/reactivación automáticas), `ClientController::suspend()/activate()`
(panel de administración), la reactivación por lista blanca (pasa por
`reactivateClient`) e incluso un `$client->update(['service_status' => ...])`
directo. Los flujos de negocio enriquecen el registro (razón, ejecutor, factura
origen) seteando `Client::$serviceStatusChangeContext` antes de `save()`; si nadie
lo setea, el observer registra valores por defecto con `source = status_change`.

> Limitación conocida: los *mass-updates* (`Client::where()->update()`) no
> disparan eventos de Eloquent. Ningún flujo del sistema cambia `service_status`
> por esa vía; evitarla en código futuro.

### 3. Validación por fecha límite en `AutoBillingService`

Regla de negocio (granularidad de **día**, `ClientServiceInterruption::covers()`):
ninguna factura se emite con fecha dentro de una ventana de corte. El día del
corte ya no es facturable; el día de la reactivación sí lo es.

- **`generateMonthlyInvoices()`** — además del filtro por `service_status`
  (que se conserva), se valida que **hoy** no caiga dentro de una ventana de
  corte. Defensa en profundidad: detiene la emisión aun con el estado
  inconsistente, y lo deja registrado en el log `billing` con `cut_since`.
- **`generateInvoicesByContractDate()`** — cada ciclo cuyo inicio cae dentro de
  una ventana (vigente o histórica) se omite y queda en el reporte como
  `skipped` con razón `"servicio suspendido/cortado en la fecha de inicio del
  ciclo"`. Esto elimina la retro-facturación de periodos suspendidos en clientes
  reactivados.

Las ventanas se cargan con *eager loading* (`with('serviceInterruptions')`):
la validación no agrega consultas por cliente.

La protección anti-duplicados existente no cambió: verificación por mes/plan en
la generación mensual y `lockForUpdate` + verificación por rango de ciclo (con
soft-deleted incluidas) en la generación por contrato.

## Conciliación de integridad (worker `billing_integrity`)

`App\Services\BillingIntegrityService` verifica —en **solo lectura**— los
invariantes del módulo y reporta lo que encuentre sin corregir nada:

1. Cliente con estado facturable pero ventana de corte abierta.
2. Cliente suspendido/cancelado sin ventana abierta (típico de un mass-update
   o edición directa en BD que esquivó el observer).
3. Facturas no anuladas emitidas dentro de una ventana de corte (misma regla
   de día que `AutoBillingService`).
4. Cliente cortado que conserva planes `active`.
5. *(best-effort)* Desalineación con la lista `morosos` de MikroTik en ambas
   direcciones: suspendidos que siguen navegando y activos que siguen
   bloqueados. Si el router no responde, el chequeo se omite y queda anotado.

Puntos de entrada:

- **Worker automático** `App\Jobs\ReconcileBillingIntegrity`, gestionado desde
  Workers Automáticos (key `billing_integrity`, diario 03:00 por defecto,
  parámetro `check_mikrotik`). Notifica el resumen vía `NotifiesWorkerSummary`.
- **Comando manual** `php artisan billing:check-integrity [--skip-mikrotik]` —
  útil tras un deploy o una corrección de datos; devuelve exit code ≠ 0 si hay
  hallazgos.

Cuando hay inconsistencias, el detalle queda en el log `billing` y en la
auditoría (operación `BILLING_INTEGRITY_OP`), además del resumen notificado.
Pruebas en `tests/Feature/BillingIntegrityTest.php`.

## Verificación

Pruebas en `tests/Feature/SuspendedClientBillingTest.php` (Pest):

| Escenario | Resultado esperado |
|---|---|
| Cliente activo, dos corridas mensuales | 1 factura, sin duplicados |
| Cliente suspendido por el flujo de corte | 0 facturas |
| Estado `ACTIVE` pero ventana de corte vigente (dato inconsistente) | 0 facturas |
| Suspendido y luego reactivado | vuelve a facturar el ciclo vigente |
| Retroactiva por contrato con ventana histórica (reactivación posterior) | omite los ciclos del corte, factura el resto, reejecución sin duplicados |
| Corte vigente sin reactivar | no genera ciclos desde la fecha límite |
| Observer | abre/cierra ventanas en flujo automático, manual y `update()` directo; sin ventanas duplicadas |

Ejecución (sin `pdo_sqlite`, se corre contra MySQL):

```bash
DB_CONNECTION=mysql DB_DATABASE=ispgestor_test php artisan test tests/Feature/SuspendedClientBillingTest.php
DB_CONNECTION=mysql DB_DATABASE=ispgestor_test php artisan test   # suite completa
```

Última corrida completa: **234 tests, 667 aserciones, 0 fallos**.

### Verificación manual (tinker)

```php
$c = Client::find($id);
$c->serviceCutSince();                    // fecha límite vigente o null
$c->openServiceInterruption();            // ventana abierta con razón/ejecutor
$c->serviceInterruptions;                 // histórico completo de cortes
app(AutoBillingService::class)->generateMonthlyInvoices(); // no debe incluirlo si está cortado
```

## Archivos modificados

- `database/migrations/2026_07_04_000001_create_client_service_interruptions_table.php` (nuevo)
- `app/Models/ClientServiceInterruption.php` (nuevo)
- `app/Observers/ClientServiceStatusObserver.php` (nuevo)
- `app/Models/Client.php` — relación `serviceInterruptions()`, helpers `openServiceInterruption()` / `serviceCutSince()`, contexto transitorio
- `app/Providers/AppServiceProvider.php` — registro del observer
- `app/Services/AutoBillingService.php` — validación por fecha límite en ambos generadores
- `app/Services/ClientSuspensionService.php` — contexto de negocio en suspensión/baja/reactivación
- `app/Http/Controllers/Admin/ClientController.php` — contexto en suspensión/activación manual
- `tests/Feature/SuspendedClientBillingTest.php` (nuevo)

Conciliación de integridad:

- `app/Services/BillingIntegrityService.php` (nuevo)
- `app/Jobs/ReconcileBillingIntegrity.php` (nuevo)
- `app/Console/Commands/CheckBillingIntegrity.php` (nuevo)
- `app/Services/MikroTikService.php` — método `getAddressListEntries()`
- `database/migrations/2026_07_05_000001_seed_billing_integrity_automation.php` (nuevo)
- `tests/Feature/BillingIntegrityTest.php` (nuevo)
