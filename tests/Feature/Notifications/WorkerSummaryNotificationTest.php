<?php

use App\Jobs\Concerns\NotifiesWorkerSummary;
use App\Models\NotificationLog;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Enums\NotificationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['queue.default' => 'sync']);
    config(['notifications.queue.connection' => null]);

    config(['notifications.enabled' => true]);
    config(['notifications.channels.telegram.enabled' => true]);
    config(['notifications.channels.telegram.config' => [
        'bot_token'  => 'fake-token',
        'base_url'   => 'https://api.telegram.org',
        'timeout'    => 2,
        'parse_mode' => 'MarkdownV2',
    ]]);
    config(['notifications.severity_routes' => [
        'critical' => [['channel' => 'telegram', 'address' => 'chat-critical']],
        'summary'  => [['channel' => 'telegram', 'address' => 'chat-summary']],
        'info'     => [['channel' => 'telegram', 'address' => 'chat-info']],
    ]]);
    config(['notifications.deduplication.store' => 'array']);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
    ]);
});

class TestJobWithSummary
{
    use NotifiesWorkerSummary;

    public function runHappy(): void
    {
        $this->notifyWorkerSummary('TestJobHappy', [
            'total_processed' => 10,
            'successful'      => 10,
            'failed'          => 0,
        ], 'Procesar registros');
    }

    public function runWithErrors(): void
    {
        $this->notifyWorkerSummary('TestJobBad', [
            'total_processed' => 10,
            'successful'      => 7,
            'failed'          => 3,
        ], 'Procesar registros');
    }

    public function runFailure(): void
    {
        $ex = new \RuntimeException('all broken');
        $this->notifyWorkerFailure('TestJobBoom', $ex, 'Procesar registros');
    }
}

it('envía un resumen SUMMARY al canal de resúmenes cuando no hay errores', function () {
    (new TestJobWithSummary())->runHappy();

    $log = NotificationLog::where('status', NotificationStatus::SENT->value)->first();
    expect($log)->not->toBeNull()
        ->and($log->severity)->toBe(NotificationSeverity::SUMMARY->value)
        ->and($log->category)->toBe(NotificationCategory::WORKER_SUMMARY->value)
        ->and($log->recipient)->toBe('chat-summary');
});

it('escala a CRITICAL y enruta al canal crítico cuando hay errores', function () {
    (new TestJobWithSummary())->runWithErrors();

    $log = NotificationLog::where('status', NotificationStatus::SENT->value)->first();
    expect($log)->not->toBeNull()
        ->and($log->severity)->toBe(NotificationSeverity::CRITICAL->value)
        ->and($log->category)->toBe(NotificationCategory::WORKER_FAILURE->value)
        ->and($log->recipient)->toBe('chat-critical');
});

it('emite alerta CRITICAL cuando se invoca notifyWorkerFailure', function () {
    (new TestJobWithSummary())->runFailure();

    $log = NotificationLog::where('status', NotificationStatus::SENT->value)->first();
    expect($log)->not->toBeNull()
        ->and($log->severity)->toBe(NotificationSeverity::CRITICAL->value)
        ->and($log->category)->toBe(NotificationCategory::WORKER_FAILURE->value)
        ->and($log->body)->toContain('all broken');
});
