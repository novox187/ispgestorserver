<?php

use App\Notifications\Core\Enums\FormatHint;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Messages\NotificationMessage;

uses(Tests\TestCase::class);

it('rechaza título vacío', function () {
    new NotificationMessage(
        category: NotificationCategory::WORKER_SUMMARY,
        severity: NotificationSeverity::SUMMARY,
        title:    '',
        body:     'cuerpo',
    );
})->throws(InvalidArgumentException::class);

it('rechaza cuerpo vacío', function () {
    new NotificationMessage(
        category: NotificationCategory::WORKER_SUMMARY,
        severity: NotificationSeverity::SUMMARY,
        title:    'titulo',
        body:     '',
    );
})->throws(InvalidArgumentException::class);

it('genera un id estable cuando se reconstruye desde array', function () {
    $original = new NotificationMessage(
        category: NotificationCategory::WORKER_SUMMARY,
        severity: NotificationSeverity::SUMMARY,
        title:    'titulo',
        body:     'cuerpo',
    );

    $rebuilt = NotificationMessage::fromArray($original->toArray());

    expect($rebuilt->id)->toBe($original->id);
});

it('calcula dedupeKey explícito cuando fue provisto', function () {
    $msg = new NotificationMessage(
        category:  NotificationCategory::MIKROTIK_CONNECTIVITY,
        severity:  NotificationSeverity::CRITICAL,
        title:     'down',
        body:      'body',
        dedupeKey: 'custom:key:99',
    );

    expect($msg->effectiveDedupeKey())->toBe('custom:key:99');
});

it('calcula dedupeKey determinístico cuando no se provee', function () {
    $a = new NotificationMessage(
        category: NotificationCategory::WORKER_SUMMARY,
        severity: NotificationSeverity::SUMMARY,
        title:    'titulo',
        body:     'cuerpo',
        context:  ['foo' => 'bar'],
    );
    $b = new NotificationMessage(
        category: NotificationCategory::WORKER_SUMMARY,
        severity: NotificationSeverity::SUMMARY,
        title:    'titulo',
        body:     'cuerpo',
        context:  ['foo' => 'bar'],
    );

    expect($a->effectiveDedupeKey())->toBe($b->effectiveDedupeKey());
});

it('serializa y deserializa formato preservando enums', function () {
    $msg = new NotificationMessage(
        category:   NotificationCategory::INFO_TASK_COMPLETED,
        severity:   NotificationSeverity::INFO,
        title:      't',
        body:       'b',
        formatHint: FormatHint::PLAIN,
    );

    $rebuilt = NotificationMessage::fromArray($msg->toArray());
    expect($rebuilt->formatHint)->toBe(FormatHint::PLAIN);
});
