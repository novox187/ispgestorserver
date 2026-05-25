<?php

namespace App\Notifications\Messages;

use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Messages\NotificationMessage;

/**
 * Factory: construye un NotificationMessage de resumen al finalizar un worker
 * automatizado.
 *
 * Si el resultado contiene `errors > 0` (o `failed > 0`) la severidad escala
 * automáticamente a CRITICAL para que la alerta llegue al canal correspondiente.
 */
class WorkerCompletedNotification
{
    public static function build(string $workerName, array $result, ?string $objective = null): NotificationMessage
    {
        $hasErrors = self::detectErrors($result);
        $severity  = $hasErrors ? NotificationSeverity::CRITICAL : NotificationSeverity::SUMMARY;
        $category  = $hasErrors ? NotificationCategory::WORKER_FAILURE : NotificationCategory::WORKER_SUMMARY;
        $icon      = $hasErrors ? '⚠️' : '✅';

        $body = "*Worker:* `{$workerName}`\n";
        if (filled($objective)) {
            $body .= "*Objetivo:* {$objective}\n";
        }
        $body .= "*Finalizó:* " . now()->toIso8601String() . "\n\n";
        $body .= "*Métricas:*\n";
        $body .= self::renderMetrics($result);

        if ($hasErrors) {
            $body .= "\n\n{$icon} *Se detectaron errores durante la ejecución. Revisar logs para detalles.*";
        }

        return new NotificationMessage(
            category:   $category,
            severity:   $severity,
            title:      "{$icon} {$workerName} — " . ($hasErrors ? 'completado con errores' : 'completado'),
            body:       $body,
            context:    [
                'worker'    => $workerName,
                'objective' => $objective,
                'result'    => $result,
                'has_errors'=> $hasErrors,
            ],
            // dedupeKey ligero: una corrida por worker por ventana de 60s.
            dedupeKey:  "worker:summary:{$workerName}:" . now()->format('YmdHi'),
        );
    }

    private static function detectErrors(array $result): bool
    {
        foreach (['errors', 'failed', 'failures'] as $key) {
            if (isset($result[$key]) && is_numeric($result[$key]) && (int) $result[$key] > 0) {
                return true;
            }
        }
        return false;
    }

    private static function renderMetrics(array $result, int $indent = 0): string
    {
        $lines = [];
        $pad   = str_repeat('  ', $indent);
        foreach ($result as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $rendered = $value === null ? 'null' : (string) $value;
                $lines[] = "{$pad}• *" . self::humanize((string) $key) . ":* `{$rendered}`";
            } elseif (is_array($value) && !self::isList($value)) {
                $lines[] = "{$pad}• *" . self::humanize((string) $key) . ":*";
                $lines[] = self::renderMetrics($value, $indent + 1);
            } else {
                $lines[] = "{$pad}• *" . self::humanize((string) $key) . ":* `" . count((array) $value) . " items`";
            }
        }
        return implode("\n", $lines);
    }

    private static function humanize(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    private static function isList(array $arr): bool
    {
        return array_is_list($arr);
    }
}
