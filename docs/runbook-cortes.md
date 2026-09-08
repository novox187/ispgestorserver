# Runbook — Cortes de servicio

Procedimiento de resolución para las excepciones del módulo de cortes. Cada
sección responde a una alerta concreta: qué la dispara, cómo confirmarla y qué
hacer. El diagnóstico previo a este runbook está en el informe *Anatomía del
Corte*; aquí solo está la acción.

---

## 0. Diagnóstico rápido

```bash
# Estado de todos los invariantes (solo lectura)
php artisan billing:check-integrity

# Sin tocar el router (útil si MikroTik está caído y solo interesa la BD)
php artisan billing:check-integrity --skip-mikrotik

# Corrige la desalineación con la lista morosos (pide confirmación)
php artisan billing:check-integrity --repair
```

`--repair` **solo** toca la address-list del router: desbloquea a clientes con
servicio vigente y bloquea a los suspendidos que falten. Nunca modifica estados
ni ventanas de corte en la base de datos: esos desajustes se corrigen a mano
porque "arreglarlos" automáticamente puede facturar de más.

---

## 1. Alerta: `cortes_sin_efecto_en_red > 0`

**Qué significa.** Se aplicó el corte en la base de datos pero no se pudo
confirmar que el abonado dejara de navegar. El cliente deja de facturarse y
sigue consumiendo: es la avería más cara del módulo.

**Cómo confirmarlo.** El resumen del worker trae el contador. El detalle, por
cliente, está en la ventana de corte:

```sql
SELECT client_id, suspended_at, enforcement_state, suspension_reason
FROM client_service_interruptions
WHERE reactivated_at IS NULL
  AND enforcement_state IN ('no_filter_rule','entry_missing','unverifiable','no_ip','ip_mismatch')
ORDER BY suspended_at DESC;
```

**Qué hacer según el estado:**

| `enforcement_state` | Causa | Acción |
|---|---|---|
| `no_filter_rule` | No hay regla de firewall activa que use la lista `morosos` | **Revisar el router**: ver sección 2. Afecta a *todos* los cortes a la vez |
| `entry_missing` | El router rechazó la escritura o no la conserva | Comprobar permisos de la cuenta API (`write` sobre `/ip/firewall/address-list`) |
| `unverifiable` | El router no respondió a la comprobación | Revisar conectividad; reintentar con `--repair` cuando responda |
| `no_ip` | El cliente no tiene IP asignada | Completar la ficha del cliente y volver a cortar |
| `ip_mismatch` | La IP registrada no es la del cliente en el router | Ver sección 3 |

---

## 2. La regla de firewall de `morosos` no existe o no aplica

Este es el fallo que convierte **todos** los cortes en cortes de papel. El
sistema escribe en la address-list, pero quien corta el tráfico es una regla de
RouterOS que el sistema no crea ni gestiona.

**Comprobación en el router:**

```
/ip firewall filter print where src-address-list=morosos
```

Debe existir al menos una regla que cumpla las tres cosas:

1. `action=drop` (o `reject`),
2. `disabled=no`,
3. `src-address-list=morosos` (o `dst-address-list`).

**Además**, la regla tiene que estar **por encima** de cualquier `accept` que
deje pasar ese tráfico: RouterOS evalúa en orden y la primera que coincide gana.
Una regla correcta colocada debajo de un `accept` general no bloquea nada, y la
comprobación automática no puede detectarlo (solo ve que la regla existe).

**Si falta**, crearla en la cadena `forward`:

```
/ip firewall filter add chain=forward src-address-list=morosos action=drop \
  comment="Corte de servicio por impago - gestionado por ISP Gestor"
```

**Después de crearla**, volver a verificar un corte vigente:

```bash
php artisan billing:check-integrity
```

> El nombre de la lista se configura en `BILLING_MOROSOS_LIST`
> (`config/billing.php`). Si se cambia en el router, hay que cambiarlo ahí.

---

## 3. `ip_mismatch` — la IP registrada no es la del cliente

**Qué significa.** La IP de `clients.ip` no coincide con el `target` de la cola
simple del cliente en el router. El sistema **no** bloqueó nada, a propósito:
bloquear esa IP habría cortado a otro abonado que está al corriente de pago.

**Cómo resolverlo:**

1. Consultar la cola real del cliente en el router (`/queue simple print`).
2. Corregir `clients.ip` desde la ficha del cliente con la IP real.
3. Volver a ejecutar el corte (manual, o esperar a la corrida de las 04:00).

**Si la IP del sistema es la correcta** y la cola está mal, el problema está en
la sincronización de colas: `php artisan mikrotik:sync-queues`.

---

## 4. Alerta: corrida abortada — "MikroTik no responde"

**Qué significa.** El corte automático se detuvo **antes de tocar a nadie**
porque el router no contestó. Es el comportamiento correcto: cortar con el
router mudo produce cortes solo-BD masivos.

**Qué hacer:**

1. Comprobar el router (`ping`, panel de red, túnel VPN si aplica).
2. Confirmar que existe un router primary activo:
   la tabla `network_devices` debe tener una fila con `is_primary = 1` **y**
   `is_active = 1`. Sin ella, todo el módulo MikroTik opera en modo no-op.
3. Cuando el router vuelva, la siguiente corrida (04:00) recupera la cohorte
   completa. Para no esperar: *Workers Automáticos → Suspensión Automática →
   Ejecutar ahora*.

**Nadie queda sin cortar por esto**: la cohorte se recalcula cada corrida.

---

## 5. Aviso: "Cobros Automáticos desactivados"

**Qué significa.** El worker `auto_billing` está apagado. Ya no impide los
cortes —la cohorte mira la fecha de vencimiento, no el estado `failed`—, pero sí
significa que nadie está intentando cobrar: los clientes se cortan sin que se
haya probado a cobrarles más que en el último intento del propio corte.

**Qué hacer.** Decidir si el apagado es intencionado. Si no lo es:
*Workers Automáticos → Cobros Automáticos → Activar*.

---

## 6. Alerta: `pendientes_siguiente_corrida > 0`

**Qué significa.** Había más clientes candidatos que la cota de lote
(`BILLING_SUSPENSION_MAX_BATCH`, 200 por defecto). Se cortaron los 200 más
antiguos; el resto entra en la corrida siguiente.

**Qué hacer.** Normalmente nada: se resuelve solo en 24 h. Si el número es
grande y persistente durante varios días, revisar por qué creció tanto la
cartera vencida antes de subir la cota — subirla solo alarga la corrida y
acerca el timeout.

---

## 7. Cliente cortado que no debía estarlo

**Vía rápida (deja al cliente protegido y lo reactiva):**
*Configuraciones → Lista blanca → Añadir cliente*. La inclusión reactiva el
servicio automáticamente y bloquea futuros cortes, tanto automáticos como
manuales.

**Vía normal:** ficha del cliente → *Reactivar servicio*.

Ambas cierran la ventana de corte y reanudan la facturación desde ese día (el
día de la reactivación ya es facturable; el del corte no).

---

## 8. Cliente que paga y no navega

Suele ser el inverso: quedó bloqueado en el router pero activo en la BD (fallo
parcial entre red y base de datos).

```bash
php artisan billing:check-integrity          # aparece en `in_morosos_not_suspended`
php artisan billing:check-integrity --repair # lo desbloquea
```

---

## Ventanas horarias

| Hora | Proceso | Cola |
|---|---|---|
| 01:00 (día 1) | Generación mensual de facturas | `default` |
| 02:00 | Cobros automáticos | `default` |
| 03:00 | Conciliación de integridad | `default` |
| **04:00** | **Corte automático** | `suspensions` |
| 10:00 | Barrido de reactivaciones | `reactivations` |
| Continuo | Reactivación por recarga (≈10 s) | `reactivations` |

El corte va **después** de los cobros a propósito: debe mirar una cartera ya
estabilizada, no competir con quien la está cobrando.

---

## Decisión pendiente: el estado `LIMITED`

`LIMITED` existe en el enum de `clients.service_status`, en los filtros del
listado y en las estadísticas, pero **ningún flujo lo produce**. Hoy se comporta
de forma coherente si alguien lo asigna a mano (se factura, no abre ventana de
corte, y `activate` lo acepta como origen), pero es un estado muerto.

Hay dos salidas y son mutuamente excluyentes:

- **Implementarlo** como corte parcial: en vez de bloquear la IP, cambiar la
  cola del cliente a un plan reducido. Es más amable comercialmente que el corte
  seco y suele reducir bajas, pero es una funcionalidad nueva, no un arreglo.
- **Retirarlo** del enum y de la UI. Requiere migración y verificar que ninguna
  fila lo use.

**No se ha tocado**: es una decisión de negocio, no técnica.
