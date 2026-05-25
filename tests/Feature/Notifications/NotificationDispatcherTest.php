<?php

use App\Models\NotificationLog;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Enums\NotificationStatus;
use App\Notifications\Core\Facades\Notify;
use App\Notifications\Core\Messages\NotificationMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // El config cacheado puede traer queue.default=database; en tests forzamos
    // sync para que SendNotificationJob corra en el mismo tick que dispatch().
    config(['queue.default' => 'sync']);
    config(['notifications.queue.connection' => null]);
    config(['notifications.deduplication.store' => 'array']);
    config(['cache.default' => 'array']);

    // Toda la config del canal vive en la BD (no en env ni config).
    seedTelegramChannel(
        botToken: 'fake-token',
        defaultAddress: 'chat-default',
        routes: [
            NotificationCategory::WORKER_SUMMARY->value  => 'chat-summary',
            NotificationCategory::SERVICE_HEALTH->value  => 'chat-critical',
        ]
    );

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200),
    ]);
});

function makeMessage(
    NotificationSeverity $severity = NotificationSeverity::SUMMARY,
    ?string $dedupe = null,
    NotificationCategory $category = NotificationCategory::WORKER_SUMMARY,
): NotificationMessage {
    return new NotificationMessage(
        category:  $category,
        severity:  $severity,
        title:     'Resumen de prueba',
        body:      'Cuerpo de prueba',
        context:   ['k' => 'v'],
        dedupeKey: $dedupe,
    );
}

it('enruta al chat configurado en la BD y persiste log SENT', function () {
    Notify::dispatch(makeMessage(NotificationSeverity::CRITICAL, 'unique:1', NotificationCategory::SERVICE_HEALTH));

    $log = NotificationLog::first();
    expect($log)->not->toBeNull()
        ->and($log->channel)->toBe('telegram')
        ->and($log->recipient)->toBe('chat-critical')
        ->and($log->status)->toBe(NotificationStatus::SENT->value)
        ->and($log->external_id)->toBe('42');
});

it('crea un log DUPLICATED cuando la clave ya fue vista', function () {
    Notify::dispatch(makeMessage(NotificationSeverity::SUMMARY, 'dup:42'));
    Notify::dispatch(makeMessage(NotificationSeverity::SUMMARY, 'dup:42'));

    $statuses = NotificationLog::pluck('status')->toArray();
    expect($statuses)->toContain(NotificationStatus::SENT->value)
        ->and($statuses)->toContain(NotificationStatus::DUPLICATED->value);
});

it('marca FAILED cuando no hay rutas en BD para la categoría', function () {
    \App\Models\NotificationEventRoute::query()->delete();
    \Illuminate\Support\Facades\Cache::flush();

    Notify::dispatch(makeMessage(NotificationSeverity::SUMMARY, 'no-recipients'));

    $log = NotificationLog::first();
    expect($log->status)->toBe(NotificationStatus::FAILED->value)
        ->and($log->last_error)->toContain('sin destinatarios');
});

it('cae al default_address del canal cuando una ruta no tiene address_override', function () {
    \App\Models\NotificationEventRoute::where('category', NotificationCategory::WORKER_SUMMARY->value)
        ->update(['address_override' => null]);
    \Illuminate\Support\Facades\Cache::flush();

    Notify::dispatch(makeMessage(NotificationSeverity::SUMMARY, 'fallback-default'));

    $log = NotificationLog::where('status', NotificationStatus::SENT->value)->first();
    expect($log)->not->toBeNull()
        ->and($log->recipient)->toBe('chat-default');
});

it('respeta address_override sobre default_address del canal', function () {
    // El UNIQUE (category, channel_key) impide duplicar rutas. La forma real
    // de tener "varios destinatarios" en este modelo es registrar distintos
    // canales (telegram + email + ...). Lo que sí podemos validar es que el
    // address_override gana sobre el default_address del canal.
    \App\Models\NotificationEventRoute::where('category', NotificationCategory::WORKER_SUMMARY->value)
        ->update(['address_override' => 'override-wins']);
    \Illuminate\Support\Facades\Cache::flush();

    Notify::dispatch(makeMessage(NotificationSeverity::SUMMARY, 'override:1'));

    $log = NotificationLog::where('status', NotificationStatus::SENT->value)->first();
    expect($log)->not->toBeNull()
        ->and($log->recipient)->toBe('override-wins');
});

it('no usa variables de entorno: con BD vacía no resuelve ningún destinatario', function () {
    // Limpiamos todo lo que el helper sembró: si el módulo siguiera leyendo
    // de env caería al fallback. Como no lo hace, el log debe quedar FAILED.
    \App\Models\NotificationEventRoute::query()->delete();
    \App\Models\NotificationChannelConfig::query()->delete();
    \Illuminate\Support\Facades\Cache::flush();

    // Aunque haya valores en env, no deben influir.
    putenv('TELEGRAM_BOT_TOKEN=should-be-ignored');
    putenv('TELEGRAM_CHAT_CRITICAL=should-be-ignored');

    Notify::dispatch(makeMessage(NotificationSeverity::SUMMARY, 'no-env-fallback'));

    $log = NotificationLog::first();
    expect($log->status)->toBe(NotificationStatus::FAILED->value)
        ->and($log->recipient)->toBe('n/a');
});
