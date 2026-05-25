<?php

namespace App\Console\Commands;

use App\Notifications\Core\Enums\FormatHint;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Facades\Notify;
use App\Notifications\Core\Messages\NotificationMessage;
use Illuminate\Console\Command;

/**
 * Comando para verificar que el módulo de notificaciones está correctamente
 * configurado, despachando un mensaje de prueba con la severidad seleccionada.
 *
 * Uso:
 *   php artisan notifications:test               # severidad summary
 *   php artisan notifications:test critical
 *   php artisan notifications:test info
 */
class TestNotificationCommand extends Command
{
    protected $signature = 'notifications:test {severity=summary : critical | summary | info}';
    protected $description = 'Despacha una notificación de prueba para verificar la configuración del módulo';

    public function handle(): int
    {
        $input = (string) $this->argument('severity');
        $severity = NotificationSeverity::tryFrom($input);

        if (!$severity) {
            $this->error("Severidad inválida: '{$input}'. Use critical | summary | info.");
            return self::FAILURE;
        }

        $category = match ($severity) {
            NotificationSeverity::CRITICAL => NotificationCategory::SERVICE_HEALTH,
            NotificationSeverity::SUMMARY  => NotificationCategory::WORKER_SUMMARY,
            NotificationSeverity::INFO     => NotificationCategory::INFO_TASK_COMPLETED,
        };

        $message = new NotificationMessage(
            category:   $category,
            severity:   $severity,
            title:      "Notificación de prueba ({$severity->label()})",
            body:       "Este es un mensaje de prueba emitido desde `php artisan notifications:test`.\n\n"
                       . "*Hora:* " . now()->toIso8601String() . "\n"
                       . "*Entorno:* " . app()->environment() . "\n\n"
                       . "Si recibe este mensaje, el canal está correctamente configurado.",
            context:    [
                'source'      => 'artisan',
                'environment' => app()->environment(),
                'dispatched_at' => now()->toIso8601String(),
            ],
            formatHint: FormatHint::MARKDOWN,
            dedupeKey:  'test:' . $severity->value . ':' . now()->format('YmdHis'),
        );

        $logs = Notify::dispatch($message);

        if (empty($logs)) {
            $this->warn(
                'No se generaron logs. Verifique que el canal esté habilitado en la BD '
                . '(notification_channel_configs) y con credenciales válidas desde el panel.'
            );
            return self::SUCCESS;
        }

        $this->info("Notificación encolada. Se crearon " . count($logs) . " entrada(s) en notification_logs:");
        foreach ($logs as $log) {
            $this->line("  • #{$log->id}  channel={$log->channel}  recipient={$log->recipient}  status={$log->status}");
        }

        $this->newLine();
        $this->comment('Tip: corra `php artisan queue:work` para procesar el envío y vea el resultado en notification_logs.');

        return self::SUCCESS;
    }
}
