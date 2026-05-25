<?php

namespace App\Notifications\Messages;

use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Messages\NotificationMessage;

/**
 * Factory: construye un NotificationMessage CRITICAL cuando un worker lanza
 * excepción no capturada y queda en `failed_jobs`.
 */
class WorkerFailedNotification
{
    public static function build(string $workerName, \Throwable $exception, ?string $objective = null): NotificationMessage
    {
        $body = "*Worker:* `{$workerName}`\n";
        if (filled($objective)) {
            $body .= "*Objetivo:* {$objective}\n";
        }
        $body .= "*Falló:* " . now()->toIso8601String() . "\n";
        $body .= "*Excepción:* `" . class_basename($exception) . "`\n";
        $body .= "*Mensaje:* `" . substr($exception->getMessage(), 0, 280) . "`\n";
        $body .= "*Archivo:* `" . basename($exception->getFile()) . ':' . $exception->getLine() . "`\n\n";
        $body .= "Recomendaciones: revisar la entrada `failed_jobs`, evaluar reintento manual y consultar `storage/logs/laravel.log` para el stack completo.";

        return new NotificationMessage(
            category:   NotificationCategory::WORKER_FAILURE,
            severity:   NotificationSeverity::CRITICAL,
            title:      "❌ {$workerName} falló",
            body:       $body,
            context:    [
                'worker'           => $workerName,
                'objective'        => $objective,
                'exception_class'  => $exception::class,
                'exception_message'=> $exception->getMessage(),
                'file'             => $exception->getFile(),
                'line'             => $exception->getLine(),
                'failed_at'        => now()->toIso8601String(),
            ],
            dedupeKey:  "worker:failed:{$workerName}:" . now()->format('YmdHi'),
        );
    }
}
