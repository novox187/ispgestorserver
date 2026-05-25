<?php

use App\Models\NotificationChannelConfig;
use App\Models\NotificationEventRoute;
use App\Models\NotificationLog;
use App\Notifications\Core\Enums\NotificationCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['queue.default' => 'sync']);
    config(['notifications.queue.connection' => null]);
    config(['notifications.enabled' => true]);
    config(['notifications.channels.telegram.enabled' => true]);
    config(['notifications.channels.telegram.config' => [
        'bot_token'  => 'env-token',
        'base_url'   => 'https://api.telegram.org',
        'timeout'    => 5,
        'parse_mode' => 'MarkdownV2',
    ]]);
    config(['notifications.severity_routes' => [
        'critical' => [['channel' => 'telegram', 'address' => 'chat-critical']],
        'summary'  => [['channel' => 'telegram', 'address' => 'chat-summary']],
        'info'     => [['channel' => 'telegram', 'address' => 'chat-info']],
    ]]);
    config(['notifications.deduplication.store' => 'array']);

    $admin = makeSuperAdminEmployee();
    Sanctum::actingAs($admin, ['*']);
});

it('expone el catálogo de canales y categorías', function () {
    $res = $this->getJson('/api/admin/notifications/catalog');
    $res->assertOk();
    $body = $res->json();
    expect($body['channels'])->not->toBeEmpty()
        ->and($body['categories'])->not->toBeEmpty()
        ->and($body['severities'])->toContain('critical');
});

it('lista canales con credenciales enmascaradas y settings visibles', function () {
    NotificationChannelConfig::create([
        'channel_key' => 'telegram',
        'enabled'     => true,
        'credentials' => ['bot_token' => 'real-secret-token'],
        'settings'    => ['default_address' => 'real-chat-id'],
    ]);

    $res = $this->getJson('/api/admin/notifications/channels');
    $res->assertOk();
    $tg = collect($res->json('channels'))->firstWhere('key', 'telegram');

    expect($tg['enabled'])->toBeTrue()
        // bot_token (sensitive) viene enmascarado
        ->and($tg['credentials']['bot_token'])->toBe('********')
        // default_address (no sensitive) viene en claro para que el admin lo vea
        ->and($tg['settings']['default_address'])->toBe('real-chat-id')
        ->and($tg['has_db_override'])->toBeTrue();
});

it('actualiza credenciales del canal y persiste enabled', function () {
    $res = $this->putJson('/api/admin/notifications/channels/telegram', [
        'enabled'     => true,
        'credentials' => ['bot_token' => 'new-secret-1234'],
        'settings'    => ['default_address' => '-100123456', 'parse_mode' => 'MarkdownV2'],
    ]);
    $res->assertOk();

    $row = NotificationChannelConfig::where('channel_key', 'telegram')->firstOrFail();
    expect($row->enabled)->toBeTrue()
        ->and($row->credentials['bot_token'])->toBe('new-secret-1234')
        ->and($row->settings['default_address'])->toBe('-100123456');
});

it('preserva la credencial previa cuando el frontend envía valor enmascarado o vacío', function () {
    NotificationChannelConfig::create([
        'channel_key' => 'telegram',
        'enabled'     => true,
        'credentials' => ['bot_token' => 'previous-token'],
    ]);

    $this->putJson('/api/admin/notifications/channels/telegram', [
        'enabled'     => true,
        'credentials' => ['bot_token' => '********'],
    ])->assertOk();

    $row = NotificationChannelConfig::where('channel_key', 'telegram')->first();
    expect($row->credentials['bot_token'])->toBe('previous-token');
});

it('rechaza actualizar un canal coming_soon', function () {
    $this->putJson('/api/admin/notifications/channels/email', [
        'enabled' => true,
    ])->assertStatus(422);
});

it('reemplaza el set completo de rutas y elimina las que no vienen', function () {
    // Sembrar una ruta que debe eliminarse
    NotificationEventRoute::create([
        'category' => NotificationCategory::SSL_EXPIRATION->value,
        'channel_key' => 'telegram',
        'enabled' => true,
    ]);

    $this->putJson('/api/admin/notifications/routes', [
        'routes' => [
            [
                'category' => NotificationCategory::MIKROTIK_CONNECTIVITY->value,
                'channel_key' => 'telegram',
                'enabled' => true,
                'address_override' => 'special-chat',
                'extra' => ['thread_id' => '42'],
            ],
            [
                'category' => NotificationCategory::WORKER_FAILURE->value,
                'channel_key' => 'telegram',
                'enabled' => true,
                'address_override' => null,
                'extra' => null,
            ],
        ],
    ])->assertOk();

    expect(NotificationEventRoute::count())->toBe(2)
        ->and(NotificationEventRoute::where('category', NotificationCategory::SSL_EXPIRATION->value)->exists())->toBeFalse();

    $row = NotificationEventRoute::where('category', NotificationCategory::MIKROTIK_CONNECTIVITY->value)->first();
    expect($row->address_override)->toBe('special-chat')
        ->and($row->extra)->toMatchArray(['thread_id' => '42']);
});

it('rechaza rutas con categorías no expuestas (ej: meta_failure)', function () {
    $this->putJson('/api/admin/notifications/routes', [
        'routes' => [
            [
                'category' => NotificationCategory::META_FAILURE->value,
                'channel_key' => 'telegram',
                'enabled' => true,
            ],
        ],
    ])->assertStatus(422);
});

it('envía notificación de prueba y devuelve estado del log', function () {
    NotificationChannelConfig::create([
        'channel_key' => 'telegram',
        'enabled' => true,
        'credentials' => ['bot_token' => 'real-token'],
        'settings' => ['default_address' => 'chat-test'],
    ]);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200),
    ]);

    $res = $this->postJson('/api/admin/notifications/channels/telegram/test', [
        'address' => 'chat-test',
    ]);
    $res->assertOk();
    expect($res->json('logs_sent'))->toBe(1);

    $log = NotificationLog::first();
    expect($log->channel)->toBe('telegram')
        ->and($log->recipient)->toBe('chat-test')
        ->and($log->status)->toBe('sent');
});

it('rechaza prueba si no hay destinatario disponible', function () {
    NotificationChannelConfig::create([
        'channel_key' => 'telegram',
        'enabled' => true,
        'credentials' => ['bot_token' => 'real-token'],
    ]);

    $res = $this->postJson('/api/admin/notifications/channels/telegram/test', []);
    $res->assertStatus(422);
});

it('lista logs y permite filtrar por canal y status', function () {
    NotificationLog::create([
        'notification_id' => (string) Illuminate\Support\Str::uuid(),
        'category' => 'worker_summary',
        'severity' => 'summary',
        'channel'  => 'telegram',
        'recipient'=> 'x',
        'title'    => 't', 'body' => 'b', 'context' => [],
        'status'   => 'sent',
        'attempts' => 1,
    ]);
    NotificationLog::create([
        'notification_id' => (string) Illuminate\Support\Str::uuid(),
        'category' => 'mikrotik_connectivity',
        'severity' => 'critical',
        'channel'  => 'telegram',
        'recipient'=> 'y',
        'title'    => 't', 'body' => 'b', 'context' => [],
        'status'   => 'failed',
        'attempts' => 2,
    ]);

    $this->getJson('/api/admin/notifications/logs?status=failed')
        ->assertOk()
        ->assertJsonCount(1, 'logs');
});

it('respeta rutas de BD sobre severity_routes (DB gana)', function () {
    NotificationChannelConfig::create([
        'channel_key' => 'telegram',
        'enabled' => true,
        'credentials' => ['bot_token' => 'real-token'],
    ]);
    NotificationEventRoute::create([
        'category' => NotificationCategory::WORKER_SUMMARY->value,
        'channel_key' => 'telegram',
        'enabled' => true,
        'address_override' => 'db-chat-override',
    ]);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
    ]);

    \App\Notifications\Core\Facades\Notify::dispatch(
        new \App\Notifications\Core\Messages\NotificationMessage(
            category:  NotificationCategory::WORKER_SUMMARY,
            severity:  \App\Notifications\Core\Enums\NotificationSeverity::SUMMARY,
            title:     'test routing',
            body:      'body',
            dedupeKey: 'route-priority-test',
        )
    );

    $log = NotificationLog::first();
    expect($log->recipient)->toBe('db-chat-override');
});
