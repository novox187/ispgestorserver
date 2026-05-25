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
    config(['queue.default' => 'sync']);
    config(['notifications.queue.connection' => null]);
    config(['notifications.retry.max_attempts' => 3]);
    config(['notifications.retry.backoff_seconds' => [0, 0, 0]]);
    config(['notifications.meta_failure_notification' => false]);
    config(['notifications.deduplication.store' => 'array']);
    config(['cache.default' => 'array']);

    seedTelegramChannel(
        botToken: 'test-token',
        defaultAddress: 'chat-x',
        routes: [
            NotificationCategory::SERVICE_HEALTH->value => 'chat-x',
        ]
    );
});

function dispatchOnce(string $dedupe = 'one-shot'): NotificationMessage
{
    $msg = new NotificationMessage(
        category:  NotificationCategory::SERVICE_HEALTH,
        severity:  NotificationSeverity::SUMMARY,
        title:     'Mensaje de prueba',
        body:      'Cuerpo de prueba',
        dedupeKey: $dedupe,
    );
    Notify::dispatch($msg);
    return $msg;
}

it('envía con éxito a la API de Telegram y registra external_id', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 123]], 200),
    ]);

    dispatchOnce('telegram:ok');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === 'chat-x';
    });

    $log = NotificationLog::where('status', NotificationStatus::SENT->value)->first();
    expect($log)->not->toBeNull()
        ->and($log->external_id)->toBe('123');
});

it('marca FAILED ante HTTP 400 sin reintentar', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400),
    ]);

    dispatchOnce('telegram:bad-chat');

    $log = NotificationLog::first();
    expect($log->status)->toBe(NotificationStatus::FAILED->value)
        ->and($log->last_error)->toContain('chat not found')
        ->and($log->attempts)->toBe(1); // sin reintentos
});

it('marca HTTP 500 como error reintentable (señal para el queue worker)', function () {
    // En sync mode Laravel ejecuta el ciclo completo: cuando handle() lanza
    // TransientChannelException no hay retry, Laravel llama directamente a
    // failed() que marca el log como EXHAUSTED. En producción (cola db/redis)
    // el worker capturaría la excepción y replanificaría con backoff.
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'internal'], 500),
    ]);

    try {
        dispatchOnce('telegram:500');
    } catch (\App\Notifications\Core\Exceptions\TransientChannelException $e) {
        // En algunas versiones la excepción se propaga; en otras la captura el
        // runner sync silenciosamente. Ambos caminos son válidos.
    }

    $log = NotificationLog::first();
    expect($log->status)->toBe(NotificationStatus::EXHAUSTED->value)
        ->and($log->last_error)->toContain('internal');
});

it('reintenta ante HTTP 429 (rate limit) — señal reintentable', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'too many requests'], 429),
    ]);

    try {
        dispatchOnce('telegram:429');
        $this->fail('Debió lanzar TransientChannelException');
    } catch (\App\Notifications\Core\Exceptions\TransientChannelException $e) {
        expect($e->getMessage())->toContain('too many requests');
    }

    $log = NotificationLog::first();
    expect($log->last_error)->toContain('too many requests');
});

it('failed() handler marca el log como EXHAUSTED', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'internal'], 500),
    ]);

    // Disparamos un envío que falla y luego ejecutamos failed() manualmente para
    // simular el último reintento del queue worker.
    try {
        dispatchOnce('telegram:exhaust');
    } catch (\App\Notifications\Core\Exceptions\TransientChannelException $e) {
        // capturada
    }

    $log = NotificationLog::first();
    // Reconstruimos el job y llamamos su failed() handler como haría Laravel.
    $job = new \App\Notifications\Core\Jobs\SendNotificationJob(
        logId:     $log->id,
        payload:   ['category' => $log->category, 'title' => $log->title],
        recipient: ['channel' => $log->channel, 'address' => $log->recipient],
    );
    $job->failed(new \RuntimeException('exhausted retries'));

    expect($log->refresh()->status)->toBe(NotificationStatus::EXHAUSTED->value);
});

it('isEnabled retorna false sin bot_token en BD', function () {
    // Builder::update() NO aplica el cast `encrypted:array`; hay que usar
    // la instancia del modelo para que se cifre correctamente.
    $row = \App\Models\NotificationChannelConfig::where('channel_key', 'telegram')->first();
    $row->credentials = [];
    $row->save();
    \Illuminate\Support\Facades\Cache::flush();

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
    ]);

    dispatchOnce('telegram:no-token');

    $log = NotificationLog::first();
    expect($log->status)->toBe(NotificationStatus::FAILED->value)
        ->and($log->last_error)->toContain('faltan credenciales');
});

it('falla con mensaje claro cuando el canal está deshabilitado en BD', function () {
    \App\Models\NotificationChannelConfig::where('channel_key', 'telegram')->update(['enabled' => false]);
    \Illuminate\Support\Facades\Cache::flush();

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
    ]);

    dispatchOnce('telegram:disabled');

    // Como el canal está deshabilitado, NotificationConfigRepository::routesForCategory
    // descarta sus rutas y el dispatcher registra "sin destinatarios".
    $log = NotificationLog::first();
    expect($log->status)->toBe(NotificationStatus::FAILED->value)
        ->and($log->last_error)->toContain('sin destinatarios');
    Http::assertNothingSent();
});
