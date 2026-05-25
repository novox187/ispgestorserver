<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationChannelConfig;
use App\Models\NotificationEventRoute;
use App\Models\NotificationLog;
use App\Notifications\Core\CategoryCatalog;
use App\Notifications\Core\ChannelCatalog;
use App\Notifications\Core\ChannelRegistry;
use App\Notifications\Core\Enums\FormatHint;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Enums\NotificationStatus;
use App\Notifications\Core\Messages\ChannelRecipient;
use App\Notifications\Core\Messages\NotificationMessage;
use App\Notifications\Core\NotificationConfigRepository;
use App\Notifications\Core\NotificationDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * API administrativa del módulo de notificaciones.
 *
 *  GET    /admin/notifications/catalog          → catálogo de canales y categorías
 *  GET    /admin/notifications/channels         → estado actual por canal
 *  PUT    /admin/notifications/channels/{key}   → actualiza credenciales/settings/enabled
 *  GET    /admin/notifications/routes           → rutas (category × channel) configuradas
 *  PUT    /admin/notifications/routes           → reemplaza todas las rutas en bulk
 *  POST   /admin/notifications/channels/{key}/test → envía notificación de prueba
 *  GET    /admin/notifications/logs             → historial reciente de envíos
 */
class NotificationSettingsController extends Controller
{
    public function __construct(
        private readonly NotificationConfigRepository $configRepo,
        private readonly NotificationDispatcher       $dispatcher,
        private readonly ChannelRegistry              $registry,
    ) {
    }

    /**
     * Catálogo estático que el frontend usa para renderizar tabs y secciones.
     */
    public function catalog()
    {
        return response()->json([
            'channels'   => ChannelCatalog::all(),
            'categories' => CategoryCatalog::groups(),
            'severities' => CategoryCatalog::severities(),
        ]);
    }

    /**
     * Estado actual de cada canal (mergeado config + DB).
     * Las credenciales sensibles se devuelven como "********" si están seteadas,
     * o null si no — nunca el valor real.
     */
    public function listChannels()
    {
        $payload = [];
        foreach (ChannelCatalog::all() as $entry) {
            $cfg = $this->configRepo->channelConfig($entry['key']);
            $payload[] = [
                'key'                => $entry['key'],
                'label'              => $entry['label'],
                'status'             => $entry['status'],
                'description'        => $entry['description'],
                'enabled'            => $cfg['enabled'],
                'credentials_schema' => $entry['credentials_schema'],
                'settings_schema'    => $entry['settings_schema'],
                'credentials'        => $this->maskCredentials($cfg['credentials'], $entry['credentials_schema']),
                'settings'           => $this->maskSettings($cfg['settings'], $entry['settings_schema']),
                'has_db_override'    => NotificationChannelConfig::where('channel_key', $entry['key'])->exists(),
            ];
        }
        return response()->json(['channels' => $payload]);
    }

    public function updateChannel(Request $request, string $key)
    {
        $catalogEntry = ChannelCatalog::byKey($key);
        if (!$catalogEntry) {
            return response()->json(['message' => "Canal '{$key}' no existe"], 404);
        }
        if ($catalogEntry['status'] !== 'available') {
            return response()->json(['message' => "Canal '{$key}' aún no está disponible"], 422);
        }

        $data = $request->validate([
            'enabled'     => ['sometimes', 'boolean'],
            'credentials' => ['sometimes', 'array'],
            'settings'    => ['sometimes', 'array'],
        ]);

        $row = NotificationChannelConfig::firstOrNew(['channel_key' => $key]);

        if (array_key_exists('enabled', $data)) {
            $row->enabled = (bool) $data['enabled'];
        }

        if (array_key_exists('credentials', $data)) {
            // Estrategia "merge inteligente": si el usuario manda string vacío en una clave
            // sensible, preservamos el valor anterior (semánticamente: "no lo cambies").
            // Si manda explícitamente null, lo borra. Cualquier otro valor reemplaza.
            $current = (array) ($row->credentials ?? []);
            foreach ($data['credentials'] as $k => $v) {
                if ($v === '' || $v === '********') continue;
                $current[$k] = $v;
            }
            $row->credentials = $current;
        }

        if (array_key_exists('settings', $data)) {
            $current = (array) ($row->settings ?? []);
            foreach ($data['settings'] as $k => $v) {
                if ($v === null) {
                    unset($current[$k]);
                } else {
                    $current[$k] = $v;
                }
            }
            $row->settings = $current;
        }

        $row->save();

        return response()->json([
            'message' => 'Canal actualizado',
            'channel' => [
                'key'         => $key,
                'enabled'     => $row->enabled,
                'credentials' => $this->maskCredentials((array) $row->credentials, $catalogEntry['credentials_schema']),
                'settings'    => $this->maskSettings((array) $row->settings, $catalogEntry['settings_schema']),
            ],
        ]);
    }

    public function listRoutes()
    {
        $routes = NotificationEventRoute::query()
            ->orderBy('category')
            ->orderBy('channel_key')
            ->get()
            ->map(fn (NotificationEventRoute $r) => [
                'category'         => $r->category,
                'channel_key'      => $r->channel_key,
                'enabled'          => $r->enabled,
                'address_override' => $r->address_override,
                'extra'            => $r->extra ?? new \stdClass(),
            ]);
        return response()->json(['routes' => $routes]);
    }

    /**
     * Reemplazo en bulk: el frontend envía la matriz completa de rutas y aquí
     * sincronizamos (upsert + delete de las que no vienen). Es el patrón más
     * simple para un formulario que se guarda todo junto.
     */
    public function replaceRoutes(Request $request)
    {
        $data = $request->validate([
            'routes' => ['required', 'array'],
            'routes.*.category'         => ['required', 'string'],
            'routes.*.channel_key'      => ['required', 'string'],
            'routes.*.enabled'          => ['required', 'boolean'],
            'routes.*.address_override' => ['nullable', 'string', 'max:255'],
            'routes.*.extra'            => ['nullable', 'array'],
        ]);

        $validCategories = array_map(fn (NotificationCategory $c) => $c->value, NotificationCategory::cases());
        $validChannels   = array_column(ChannelCatalog::all(), 'key');

        $seenPairs = [];
        foreach ($data['routes'] as $r) {
            if (!in_array($r['category'], $validCategories, true)) {
                return response()->json(['message' => "Categoría inválida: {$r['category']}"], 422);
            }
            if (!CategoryCatalog::isExposed($r['category'])) {
                return response()->json(['message' => "Categoría no expuesta: {$r['category']}"], 422);
            }
            if (!in_array($r['channel_key'], $validChannels, true)) {
                return response()->json(['message' => "Canal inválido: {$r['channel_key']}"], 422);
            }

            $key = $r['category'] . '|' . $r['channel_key'];
            $seenPairs[$key] = true;

            NotificationEventRoute::updateOrCreate(
                ['category' => $r['category'], 'channel_key' => $r['channel_key']],
                [
                    'enabled'          => (bool) $r['enabled'],
                    'address_override' => $r['address_override'] ?: null,
                    'extra'            => $r['extra'] ?: null,
                ]
            );
        }

        // Eliminar rutas que existían pero no fueron enviadas: el frontend manda
        // siempre el set completo. Esto permite "limpiar" una configuración.
        $existing = NotificationEventRoute::query()->get();
        foreach ($existing as $row) {
            $key = $row->category . '|' . $row->channel_key;
            if (!isset($seenPairs[$key])) {
                $row->delete();
            }
        }

        // Forzar bust de cache (los eventos saved/deleted ya lo hacen pero
        // por seguridad redundante en caso de conexión externa al modelo).
        Cache::forget(NotificationEventRoute::CACHE_KEY);

        return response()->json(['message' => 'Rutas actualizadas', 'count' => count($data['routes'])]);
    }

    /**
     * Envía un mensaje de prueba dirigido específicamente al canal indicado.
     *
     * A diferencia del flujo normal (que encola un SendNotificationJob), aquí
     * llamamos al canal **sincronicamente** para devolver resultado inmediato al
     * panel. Si el envío falla, el admin obtiene el error en pantalla en vez de
     * tener que esperar a un worker y revisar logs.
     *
     * El log de auditoría se crea igual, marcado con dedupeKey específico para
     * pruebas y status real (sent/failed) sin pasar por pending.
     */
    public function sendTest(Request $request, string $key)
    {
        $entry = ChannelCatalog::byKey($key);
        if (!$entry || $entry['status'] !== 'available') {
            return response()->json(['message' => "Canal '{$key}' no disponible"], 422);
        }

        $cfg = $this->configRepo->channelConfig($key);
        if (!$cfg['enabled']) {
            return response()->json(['message' => 'El canal está deshabilitado. Habilítalo antes de probar.'], 422);
        }

        $address = $request->input('address')
            ?: ($cfg['settings']['default_address'] ?? null);

        if (!$address) {
            return response()->json(['message' => 'No hay un destinatario configurado para probar (configure Chat ID por defecto).'], 422);
        }

        $message = new NotificationMessage(
            category:   NotificationCategory::INFO_TASK_COMPLETED,
            severity:   NotificationSeverity::INFO,
            title:      "Prueba de notificación ({$entry['label']})",
            body:       "Este es un mensaje de prueba enviado desde el panel de administración a las "
                       . now()->toIso8601String() . ".\n\n"
                       . "Si lo recibe, el canal está correctamente configurado.",
            context:    ['source' => 'panel-admin', 'channel' => $key, 'address' => $address],
            formatHint: FormatHint::MARKDOWN,
            dedupeKey:  "test:panel:{$key}:" . now()->format('YmdHis'),
        );

        $recipient = new ChannelRecipient(channelKey: $key, address: (string) $address);

        // Crear el log primero para tener auditoría sin importar el resultado.
        $log = NotificationLog::create([
            'notification_id' => $message->id,
            'category'        => $message->category->value,
            'severity'        => $message->severity->value,
            'channel'         => $recipient->channelKey,
            'recipient'       => $recipient->address,
            'title'           => $message->title,
            'body'            => $message->body,
            'context'         => $message->context,
            'attachments'     => $message->attachments,
            'status'          => NotificationStatus::PENDING->value,
            'dedupe_key'      => $message->effectiveDedupeKey(),
            'attempts'        => 1,
        ]);

        try {
            $channel = $this->registry->get($key);
            if (!$channel->isEnabled()) {
                $log->markFailed('channel disabled or misconfigured');
                return response()->json([
                    'message'         => 'El canal está deshabilitado o le faltan credenciales.',
                    'last_log_status' => $log->status,
                    'last_log_error'  => $log->last_error,
                ], 422);
            }

            $result = $channel->send($message, $recipient);

            if ($result->success) {
                $log->markSent($result->externalId);
                return response()->json([
                    'message'         => 'Notificación de prueba enviada con éxito.',
                    'logs_sent'       => 1,
                    'last_log_status' => $log->status,
                    'last_log_error'  => null,
                ]);
            }

            $log->markFailed($result->error ?? 'unknown failure');
            return response()->json([
                'message'         => 'El canal rechazó el envío: ' . ($result->error ?? 'error desconocido'),
                'logs_sent'       => 0,
                'last_log_status' => $log->status,
                'last_log_error'  => $log->last_error,
            ], 502);
        } catch (\Throwable $e) {
            $log->markFailed('exception: ' . $e->getMessage());
            return response()->json([
                'message'         => 'Excepción al enviar: ' . $e->getMessage(),
                'logs_sent'       => 0,
                'last_log_status' => $log->status,
                'last_log_error'  => $log->last_error,
            ], 500);
        }
    }

    /**
     * Historial de los últimos 200 envíos para auditoría y debug.
     */
    public function logs(Request $request)
    {
        $query = NotificationLog::query()->orderByDesc('id')->limit(200);

        if ($request->filled('channel')) {
            $query->where('channel', $request->input('channel'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        $rows = $query->get(['id','notification_id','category','severity','channel','recipient','title','status','attempts','external_id','last_error','sent_at','created_at']);

        return response()->json(['logs' => $rows]);
    }

    private function maskCredentials(array $credentials, array $schema): array
    {
        $masked = [];
        foreach ($schema as $field) {
            $key = $field['key'];
            $val = $credentials[$key] ?? null;
            $masked[$key] = $val ? '********' : null;
        }
        return $masked;
    }

    private function maskSettings(array $settings, array $schema): array
    {
        $out = [];
        foreach ($schema as $field) {
            $key = $field['key'];
            $val = $settings[$key] ?? null;
            // Settings sensibles (default_address = chat_id) también se enmascaran.
            $out[$key] = (($field['sensitive'] ?? false) && $val)
                ? '********'
                : $val;
        }
        return $out;
    }
}
