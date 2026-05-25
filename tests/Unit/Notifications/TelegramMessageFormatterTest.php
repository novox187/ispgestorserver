<?php

use App\Notifications\Channels\Telegram\TelegramMessageFormatter;
use App\Notifications\Core\Enums\FormatHint;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Messages\NotificationMessage;

uses(Tests\TestCase::class);

it('escapa los caracteres reservados de MarkdownV2 en texto plano', function () {
    $formatter = new TelegramMessageFormatter();
    $msg = new NotificationMessage(
        category:   NotificationCategory::INFO_TASK_COMPLETED,
        severity:   NotificationSeverity::INFO,
        title:      'Mantenimiento 2026-01-15',
        body:       'Cuerpo cualquiera',
        formatHint: FormatHint::PLAIN,
    );

    $output = $formatter->format($msg);

    // El título escapado debe llevar barras invertidas antes de los puntos y guiones.
    expect($output)->toContain('Mantenimiento 2026\\-01\\-15');
});

it('produce un encabezado con icono de severidad', function () {
    $formatter = new TelegramMessageFormatter();
    $msg = new NotificationMessage(
        category: NotificationCategory::SERVICE_HEALTH,
        severity: NotificationSeverity::CRITICAL,
        title:    'algo',
        body:     'body',
    );

    expect($formatter->format($msg))->toStartWith('🔴');
});

it('trunca cuerpos largos preservando el límite de Telegram', function () {
    $formatter = new TelegramMessageFormatter();
    $msg = new NotificationMessage(
        category:   NotificationCategory::WORKER_SUMMARY,
        severity:   NotificationSeverity::SUMMARY,
        title:      'titulo',
        body:       str_repeat('a', 6000),
        formatHint: FormatHint::PLAIN,
    );

    $output = $formatter->format($msg);

    expect(mb_strlen($output))->toBeLessThanOrEqual(4096);
});
