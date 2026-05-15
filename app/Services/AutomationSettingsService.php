<?php

namespace App\Services;

use App\Models\AutomationSetting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AutomationSettingsService
{
    public function all()
    {
        return Cache::rememberForever('automation_settings_all', function () {
            return AutomationSetting::orderBy('id')->get();
        });
    }

    public function get(string $key): ?AutomationSetting
    {
        return AutomationSetting::getCached($key);
    }

    public function applySchedule(Schedule $schedule, bool $testMode = false): void
    {
        $automations = AutomationSetting::where('enabled', true)->get();

        foreach ($automations as $automation) {
            try {
                $this->scheduleAutomation($schedule, $automation, $testMode);
            } catch (\Throwable $e) {
                Log::error("AutomationSettingsService: Error al programar '{$automation->key}': " . $e->getMessage());
            }
        }
    }

    private function scheduleAutomation(Schedule $schedule, AutomationSetting $a, bool $testMode): void
    {
        if (!class_exists($a->job_class)) {
            Log::warning("AutomationSettingsService: Clase {$a->job_class} no existe (key: {$a->key})");
            return;
        }

        $jobInstance = new $a->job_class();
        $event = $schedule->job($jobInstance, $a->queue);

        if ($testMode) {
            $event->everyFiveMinutes();
        } else {
            $this->applyFrequency($event, $a);
        }

        $event->withoutOverlapping()->onOneServer();
    }

    private function applyFrequency(Event $event, AutomationSetting $a): void
    {
        $config = $a->schedule_config ?? [];

        switch ($a->schedule_type) {
            case 'every_five_minutes':
                $event->everyFiveMinutes();
                break;
            case 'every_ten_minutes':
                $event->everyTenMinutes();
                break;
            case 'every_fifteen_minutes':
                $event->everyFifteenMinutes();
                break;
            case 'every_thirty_minutes':
                $event->everyThirtyMinutes();
                break;
            case 'hourly':
                $event->hourly();
                break;
            case 'daily':
                $event->dailyAt($config['time'] ?? '02:00');
                break;
            case 'monthly':
                $event->monthlyOn((int) ($config['day'] ?? 1), $config['time'] ?? '01:00');
                break;
            case 'cron':
                $event->cron($config['expression'] ?? '0 * * * *');
                break;
            default:
                $event->dailyAt('02:00');
        }
    }

    public function validateParams(AutomationSetting $a, array $params): array
    {
        $errors = [];
        $schema = $a->params_schema ?? [];

        foreach ($schema as $field => $rules) {
            $value = $params[$field] ?? null;

            if (($rules['required'] ?? false) && ($value === null || $value === '')) {
                $errors[$field] = "El campo '{$field}' es obligatorio";
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            switch ($rules['type'] ?? 'string') {
                case 'integer':
                    if (!is_numeric($value) || (int) $value != $value) {
                        $errors[$field] = "'{$field}' debe ser un entero";
                        break;
                    }
                    $intValue = (int) $value;
                    if (isset($rules['min']) && $intValue < $rules['min']) {
                        $errors[$field] = "'{$field}' debe ser >= {$rules['min']}";
                    }
                    if (isset($rules['max']) && $intValue > $rules['max']) {
                        $errors[$field] = "'{$field}' debe ser <= {$rules['max']}";
                    }
                    break;
                case 'boolean':
                    if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                        $errors[$field] = "'{$field}' debe ser true o false";
                    }
                    break;
                case 'string':
                    if (!is_string($value)) {
                        $errors[$field] = "'{$field}' debe ser texto";
                    }
                    break;
            }
        }

        return $errors;
    }

    public function validateSchedule(string $scheduleType, array $config): array
    {
        $errors = [];

        if (!in_array($scheduleType, AutomationSetting::SCHEDULE_TYPES, true)) {
            $errors['schedule_type'] = "Tipo de horario inválido";
            return $errors;
        }

        if ($scheduleType === 'daily') {
            $time = $config['time'] ?? null;
            if (!$time || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                $errors['schedule_config.time'] = 'Hora inválida — formato HH:MM (00:00 a 23:59)';
            }
        }

        if ($scheduleType === 'monthly') {
            $day = $config['day'] ?? null;
            $time = $config['time'] ?? null;
            if (!is_numeric($day) || $day < 1 || $day > 28) {
                $errors['schedule_config.day'] = 'Día inválido — debe ser entre 1 y 28';
            }
            if (!$time || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                $errors['schedule_config.time'] = 'Hora inválida — formato HH:MM (00:00 a 23:59)';
            }
        }

        if ($scheduleType === 'cron') {
            $expr = $config['expression'] ?? null;
            if (!$expr || !preg_match('/^(\S+\s+){4}\S+$/', $expr)) {
                $errors['schedule_config.expression'] = 'Expresión cron inválida — debe tener 5 campos';
            }
        }

        return $errors;
    }
}
