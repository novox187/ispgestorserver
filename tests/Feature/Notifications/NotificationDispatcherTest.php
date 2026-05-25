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

    config(['notifications.enabled' => true]);
    config(['notifications.channels.telegram.enabled' => true]);
    config(['notifications.channels.telegram.config' => [
        'bot_token'  => 'fake-token',
        'base_url'   => 'https://api.telegram.org',
        'timeout'    => 5,
        'parse_mode' => 'MarkdownV2',
    ]]);
    config(['notifications.severity_routes' => [
        'critical' => [['channel' => 'telegram', 'address' => 'chat-critical']],
        'summary'  => [['channel' => 'telegram', 'address' => 'chat-summary']],
        'info'     => [['channel' => 'telegram', 'address' => 'chat-info']],
    ]]);
    config(['notifications.category_overrides' => []]);
    config(['notifications.deduplication.store' => 'array']);
    config(['cache.default' => 'array']);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200),
    ]);
});

function makeMessage(NotificationSeverity $severity = NotificationSeverity::SUMMARY, ?string $dedupe = null): NotificationMessage
{
    return new NotificationMessage(
        category:  NotificationCategory::WORKER_SUMMARY,
        severity:  $severity,
        title:     'Resumen de prueba',
        body:      'Cuerpo de prueba',
        context:   ['k' => 'v'],
        dedupeKey: $dedupe,
    );
}

it('enruta al chat de la severidad correspondiente y persiste log SENT', function () {
    Notify::dispatch(makeMessage(NotificationSeverity::CRITICAL, 'unique:1'));

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

it('marca FAILED cuando no hay destinatarios resueltos', function () {
    config(['notifications.severity_routes.summary' => []]);

    Notify::dispatch(makeMessage(NotificationSeverity::SUMMARY, 'no-recipients'));

    $log = NotificationLog::first();
    expect($log->status)->toBe(NotificationStatus::FAILED->value)
        ->and($log->last_error)->toContain('no recipients');
});

it('no genera filas cuando el módulo está globalmente deshabilitado', function () {
    config(['notifications.enabled' => false]);

    Notify::dispatch(makeMessage());

    expect(NotificationLog::count())->toBe(0);
});

it('envía a múltiples canales cuando la severidad tiene varios destinos', function () {
    config(['notifications.severity_routes.summary' => [
        ['channel' => 'telegram', 'address' => 'chat-summary'],
        ['channel' => 'telegram', 'address' => 'chat-mirror'],
    ]]);

    Notify::dispatch(makeMessage(NotificationSeverity::SUMMARY, 'multi:1'));

    $recipients = NotificationLog::where('status', NotificationStatus::SENT->value)
        ->pluck('recipient')
        ->toArray();

    expect($recipients)->toContain('chat-summary')
        ->and($recipients)->toContain('chat-mirror');
});

it('respeta category_overrides sobre severity_routes', function () {
    config(['notifications.category_overrides' => [
        NotificationCategory::WORKER_SUMMARY->value => [
            ['channel' => 'telegram', 'address' => 'chat-override'],
        ],
    ]]);

    Notify::dispatch(makeMessage(NotificationSeverity::SUMMARY, 'override:1'));

    $log = NotificationLog::where('status', NotificationStatus::SENT->value)->first();
    expect($log->recipient)->toBe('chat-override');
});
