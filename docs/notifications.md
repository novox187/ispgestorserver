# Módulo de Notificaciones del Sistema

Sistema central de alertas y resúmenes operativos del servidor ISP Gestor.
Diseñado con un patrón **Strategy** para que la lógica de orquestación sea
independiente de los medios de entrega: hoy soporta **Telegram**, mañana se
puede agregar email, Slack, SMS, push, etc. sin tocar el núcleo.

## Tabla de contenidos

1. [Arquitectura](#arquitectura)
2. [Flujo de una notificación](#flujo-de-una-notificación)
3. [Catálogo de categorías y severidades](#catálogo-de-categorías-y-severidades)
4. [Formato de `NotificationMessage`](#formato-de-notificationmessage)
5. [Configuración](#configuración)
6. [Canal Telegram — puesta en marcha](#canal-telegram--puesta-en-marcha)
7. [Cómo agregar un canal nuevo](#cómo-agregar-un-canal-nuevo)
8. [Casos de uso cubiertos](#casos-de-uso-cubiertos)
9. [Pruebas](#pruebas)
10. [Seguridad de credenciales](#seguridad-de-credenciales)
11. [FAQ y troubleshooting](#faq-y-troubleshooting)

---

## Arquitectura

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Caller (Job, Service, Controller)                                          │
│   └─ Notify::dispatch(new MikrotikDisconnectedNotification(...))           │
└─────────────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  NotificationDispatcher (app/Notifications/Core/NotificationDispatcher.php) │
│   ├─ Deduplicator       — suprime mensajes idénticos dentro de la ventana  │
│   ├─ NotificationRouter — resuelve destinatarios por severidad/categoría   │
│   ├─ NotificationLog    — registra una fila por destino (status=pending)   │
│   └─ Enqueue            — despacha un SendNotificationJob por destino       │
└─────────────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  SendNotificationJob (ShouldQueue, $tries=5, backoff [10,30,90,270,600]s)  │
│   ├─ ChannelRegistry.get('telegram') → TelegramChannel                     │
│   ├─ channel.send(message, recipient)                                       │
│   └─ Actualiza NotificationLog (sent | failed | exhausted)                  │
└─────────────────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  TelegramChannel  (app/Notifications/Channels/Telegram/)                    │
│   ├─ TelegramMessageFormatter — produce MarkdownV2 escapado                │
│   └─ TelegramClient           — HTTP a api.telegram.org                    │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Componentes clave

| Capa | Archivo | Responsabilidad |
|---|---|---|
| API pública | `app/Notifications/Core/Facades/Notify.php` | Fachada estática `Notify::dispatch()` |
| Orquestación | `app/Notifications/Core/NotificationDispatcher.php` | Único punto de entrada al módulo |
| Routing | `app/Notifications/Core/NotificationRouter.php` | Mapea `severity → recipients` con overrides por categoría |
| Deduplicación | `app/Notifications/Core/Deduplicator.php` | Cache atómico con TTL configurable |
| Registro de canales | `app/Notifications/Core/ChannelRegistry.php` | Resuelve drivers desde config |
| Contrato | `app/Notifications/Core/Contracts/NotificationChannel.php` | Interfaz Strategy |
| Worker async | `app/Notifications/Core/Jobs/SendNotificationJob.php` | Reintentos exponenciales + meta-alerta |
| Persistencia | `app/Models/NotificationLog.php` + tabla `notification_logs` | Auditoría de cada envío |

---

## Flujo de una notificación

1. El productor construye un `NotificationMessage` (típicamente vía una factory
   en `app/Notifications/Messages/`).
2. Llama a `Notify::dispatch($message)`.
3. El dispatcher calcula la `dedupeKey`. Si está en cache → se crea un log con
   `status=duplicated` y termina.
4. Resuelve destinatarios. Si la lista está vacía → log con `status=failed` y
   razón "no recipients".
5. Para cada destinatario: inserta un `NotificationLog` `pending` y encola un
   `SendNotificationJob` en la cola configurada (`notifications.queue.name`).
6. El job ejecuta `channel.send()`:
   - Éxito → marca `sent`, guarda `external_id` (p. ej., `message_id` de Telegram).
   - Error reintentable (HTTP 429/5xx, timeout) → lanza excepción → Laravel
     reintenta con backoff exponencial hasta `max_attempts`.
   - Error definitivo (HTTP 400 chat_id inválido, sin permisos) → marca `failed`
     sin reintentar.
7. Si se agotan todos los reintentos: marca `exhausted` y emite una **meta-alerta
   CRITICAL** (categoría `META_FAILURE`). Si esa también falla, queda solo en
   `storage/logs/laravel.log` — no se reintenta para evitar recursión.

---

## Catálogo de categorías y severidades

### Severidades

| Severidad | Significado | Default route |
|---|---|---|
| `critical` | Requiere intervención inmediata | `TELEGRAM_CHAT_CRITICAL` |
| `summary` | Resumen periódico/operativo | `TELEGRAM_CHAT_SUMMARY` |
| `info` | Aviso informativo rutinario | `TELEGRAM_CHAT_INFO` |

### Categorías

| Categoría | Severidad por defecto | Caso de uso |
|---|---|---|
| `mikrotik_connectivity` | critical | Router MikroTik desconectado |
| `mikrotik_recovery` | info | Router MikroTik vuelve a responder |
| `worker_summary` | summary | Resumen de fin de ejecución de un worker |
| `worker_failure` | critical | Worker arrojó excepción no capturada o errores en `result` |
| `ssl_expiration` | info | Certificado SSL próximo a vencer |
| `resource_usage` | summary | CPU/memoria del router por encima del umbral |
| `db_sync_failure` | critical | Fallo grave de sincronización con MikroTik u otra BD |
| `service_health` | summary | Health checks generales del sistema |
| `info_task_completed` | info | Confirmación de tarea programada exitosa |
| `meta_failure` | critical | El módulo no pudo entregar otra notificación |

---

## Formato de `NotificationMessage`

```php
new NotificationMessage(
    category:   NotificationCategory::MIKROTIK_CONNECTIVITY,   // enum
    severity:   NotificationSeverity::CRITICAL,                // enum
    title:      'MikroTik Sur desconectado',                   // string no vacío
    body:       "...Markdown V2 ya formateado...",             // string no vacío
    context:    [                                              // payload estructurado
        'router_id'   => 7,
        'host'        => '10.20.30.40',
        'detected_at' => '2026-05-25T18:32:01Z',
    ],
    formatHint: FormatHint::MARKDOWN,                          // markdown | plain | html
    attachments: [                                             // opcional
        ['type' => 'photo',    'url' => 'https://...', 'caption' => '...'],
        ['type' => 'document', 'url' => 'https://...'],
    ],
    dedupeKey:  'mikrotik:disconnected:7',                     // opcional; si se omite, se calcula
);
```

Como JSON (lo que termina en `notification_logs.context`):

```json
{
    "id":          "01HW...",
    "category":    "mikrotik_connectivity",
    "severity":    "critical",
    "title":       "MikroTik Sur desconectado",
    "body":        "...",
    "context":     { "router_id": 7, "host": "10.20.30.40", "detected_at": "..." },
    "format_hint": "markdown",
    "attachments": [],
    "dedupe_key":  "mikrotik:disconnected:7"
}
```

---

## Configuración

Toda la configuración vive en [`config/notifications.php`](../config/notifications.php).
Las claves sensibles se leen desde `.env` — nunca hardcodear tokens.

Variables relevantes en `.env`:

```env
NOTIFICATIONS_ENABLED=true
NOTIFICATIONS_QUEUE_NAME=notifications

NOTIFICATIONS_MIKROTIK_MONITOR=true
MIKROTIK_FAILURE_THRESHOLD=2          # fallos consecutivos antes de alertar
MIKROTIK_HEALTH_TIMEOUT=3             # segundos por router en el ping de salud

TELEGRAM_ENABLED=true
TELEGRAM_BOT_TOKEN=...                # token de @BotFather
TELEGRAM_CHAT_CRITICAL=...
TELEGRAM_CHAT_SUMMARY=...
TELEGRAM_CHAT_INFO=...
TELEGRAM_TIMEOUT=10
TELEGRAM_PARSE_MODE=MarkdownV2
```

Override por categoría: si se quiere, p. ej., enviar todos los `worker_failure`
a un chat distinto al de severidad `critical`, descomentar y editar
`category_overrides` en `config/notifications.php`.

---

## Canal Telegram — puesta en marcha

1. **Crear el bot**:
   - Abrir [@BotFather](https://t.me/BotFather) en Telegram.
   - `/newbot`, seguir instrucciones, copiar el token a `TELEGRAM_BOT_TOKEN`.

2. **Crear los grupos de destino** (mínimo uno por severidad, o usar el mismo):
   - Crear tres grupos: Operaciones-Críticas, Operaciones-Resumen,
     Operaciones-Informativo.
   - Agregar el bot a cada grupo. **Importante**: si el grupo es privado,
     desactivar "modo privacidad" del bot (`/setprivacy` con BotFather → Disable)
     para que pueda leer el id del chat.

3. **Obtener los `chat_id`**:
   - Enviar un mensaje cualquiera en cada grupo.
   - Visitar `https://api.telegram.org/bot<TOKEN>/getUpdates`.
   - Copiar el campo `result[].message.chat.id` (puede ser negativo para grupos).
   - Pegar cada uno en `TELEGRAM_CHAT_CRITICAL`, `TELEGRAM_CHAT_SUMMARY`,
     `TELEGRAM_CHAT_INFO`.

4. **Verificar**: `php artisan notifications:test critical` (también `summary` o
   `info`). Si todo está bien, debería llegar el mensaje al grupo correspondiente.

---

## Cómo agregar un canal nuevo

Ejemplo: agregar un canal de email transaccional.

1. **Crear la clase del canal** que implemente la interfaz Strategy:

   ```php
   // app/Notifications/Channels/Email/EmailChannel.php
   namespace App\Notifications\Channels\Email;

   use App\Notifications\Core\Contracts\NotificationChannel;
   use App\Notifications\Core\Messages\ChannelDeliveryResult;
   use App\Notifications\Core\Messages\ChannelRecipient;
   use App\Notifications\Core\Messages\NotificationMessage;

   class EmailChannel implements NotificationChannel
   {
       public function key(): string { return 'email'; }

       public function isEnabled(): bool
       {
           return (bool) config('notifications.channels.email.enabled')
               && !empty(config('notifications.channels.email.config.smtp_host'));
       }

       public function supports(NotificationMessage $m): bool { return true; }

       public function send(NotificationMessage $m, ChannelRecipient $r): ChannelDeliveryResult
       {
           // Lógica con Mail::raw() o un Mailable propio.
           // Devolver ChannelDeliveryResult::success() / ::transientFailure() / ::permanentFailure().
       }
   }
   ```

2. **Registrarlo en `config/notifications.php`**:

   ```php
   'channels' => [
       'telegram' => [/* ya existente */],
       'email' => [
           'driver'  => \App\Notifications\Channels\Email\EmailChannel::class,
           'enabled' => env('EMAIL_NOTIFICATIONS_ENABLED', false),
           'config'  => [
               'smtp_host' => env('MAIL_HOST'),
               'from'      => env('MAIL_FROM_ADDRESS'),
           ],
       ],
   ],
   ```

3. **Rutearlo** desde el bloque `severity_routes` o `category_overrides`:

   ```php
   'severity_routes' => [
       'critical' => [
           ['channel' => 'telegram', 'address' => env('TELEGRAM_CHAT_CRITICAL')],
           ['channel' => 'email',    'address' => env('OPS_EMAIL')],
       ],
       // ...
   ],
   ```

4. **Agregar variables a `.env.example`** y documentar los pasos de
   aprovisionamiento en este documento.

5. **Pruebas mínimas**:
   - Unit: que `isEnabled()` reaccione a configuración válida/inválida.
   - Feature: con `Http::fake()` o `Mail::fake()`, validar que un dispatch genere
     un `NotificationLog` con `status=sent` y que el reintento funcione.

No se debe modificar el dispatcher, los jobs, ni los value objects para agregar
un canal — esa es la garantía del patrón Strategy.

---

## Casos de uso cubiertos

### 1. Pérdida de conectividad con MikroTik (CRITICAL)

- **Job programado**: `MonitorMikrotikConnectivityJob` corre cada 5 min vía
  scheduler dinámico (`routes/console.php`).
- Recorre todos los `MikrotikRouter::where('is_active', true)`.
- Usa `MikrotikHealthChecker` (timeout 3s por defecto) para hacer un ping
  RouterOS API a cada uno.
- Tras `MIKROTIK_FAILURE_THRESHOLD` fallos consecutivos: marca
  `connectivity_status='disconnected'` y emite `MikrotikDisconnectedNotification`
  (CRITICAL).
- En transición disconnected→connected: emite `MikrotikRecoveredNotification`
  (INFO).
- Deduplicación de 10 min por router para evitar spam mientras dura el incidente.

### 2. Resúmenes de workers

Cada worker incorpora el trait `App\Jobs\Concerns\NotifiesWorkerSummary`:

- `handle()` invoca `$this->notifyWorkerSummary($name, $result, $objective)`
  al final.
- `failed()` invoca `$this->notifyWorkerFailure($name, $exception, $objective)`.

La factory `WorkerCompletedNotification::build()` detecta automáticamente si
`result.errors`/`result.failed` > 0 y **escala la severidad a CRITICAL**.

Jobs cubiertos hoy:
- `ProcessAutoBilling` — métricas de cobros automáticos.
- `ProcessClientSuspension` — suspendidos / recuperados / errores.
- `SyncMikroTikQueues` — planes / clientes / eliminados.
- `GenerateMonthlyInvoices` — cantidad generada por período.
- `ProcessAutoReactivation` — solo notifica `failed()` (es per-cliente, no
  ameritan resúmenes individuales).

### 3. Pruebas manuales

```bash
php artisan notifications:test critical
php artisan notifications:test summary
php artisan notifications:test info
```

Cada invocación crea filas en `notification_logs` y dispara el envío al canal
correspondiente.

---

## Pruebas

```bash
composer test                                              # toda la suite
vendor/bin/pest tests/Unit/Notifications                   # solo unitarias
vendor/bin/pest tests/Feature/Notifications                # solo feature
```

Cobertura actual:

| Test | Foco |
|---|---|
| `Unit/Notifications/NotificationMessageTest.php` | Invariantes del value object |
| `Unit/Notifications/DeduplicatorTest.php` | TTL y atomicidad del cache |
| `Unit/Notifications/TelegramMessageFormatterTest.php` | Escape MarkdownV2, truncado a 4096 |
| `Feature/Notifications/NotificationDispatcherTest.php` | Routing, dedupe, overrides, multi-destino |
| `Feature/Notifications/TelegramChannelTest.php` | HTTP 200/400/429/500 con `Http::fake()` |
| `Feature/Notifications/MikrotikConnectivityMonitorTest.php` | Umbral, recuperación, dedupe del monitor |
| `Feature/Notifications/WorkerSummaryNotificationTest.php` | Resumen ok / con errores / fallo |

Convención: cola sync (`QUEUE_CONNECTION=sync` en `phpunit.xml`) hace que los
jobs corran inmediatamente, simplificando los aserts.

---

## Seguridad de credenciales

- `TELEGRAM_BOT_TOKEN` y `TELEGRAM_CHAT_*` viven solo en `.env`, **nunca**
  versionado.
- `.env.example` documenta las claves con valores vacíos.
- El cliente HTTP **no loguea el token**: ante errores, el mensaje sanitizado
  contiene la `description` de Telegram pero no la URL completa.
- Los `chat_id` sí se almacenan en `notification_logs.recipient` — son IDs
  numéricos, no secretos, y son necesarios para auditoría/depuración.

---

## FAQ y troubleshooting

### "Las notificaciones se encolan pero nunca llegan"

- Verificar que `php artisan queue:work` (o un supervisor) esté corriendo
  para la cola `notifications`.
- Revisar `notification_logs` filtrando por `status='pending'` (deberían pasar
  a `sent` en segundos).

### "Llegan duplicadas"

- Revisar el TTL en `config/notifications.php` → `deduplication.per_category`.
- En entornos de tests asegurar `cache.default=array` para que cada test parta
  limpio.

### "HTTP 400 Bad Request: can't parse entities"

- Es un error de MarkdownV2 mal escapado. Si el productor construye `body`
  manualmente con sintaxis Markdown, debe escapar puntos, guiones y caracteres
  reservados. Como alternativa: usar `FormatHint::PLAIN` para que el formatter
  escape todo.

### "Want to disable notifications during a maintenance window"

```bash
php artisan tinker
> config(['notifications.enabled' => false]);   # solo dura la sesión
```

Para persistente: `NOTIFICATIONS_ENABLED=false` en `.env` y reiniciar workers.
