<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Validation\ValidationException;

class SettingService
{
    public function __construct(private InvoiceConfigValidator $validator) {}

    /**
     * Build the immutable configuration snapshot.
     * Delegates validation to InvoiceConfigValidator and re-throws as
     * ValidationException so HTTP controllers get a clean 422 response.
     *
     * @throws ValidationException
     */
    public function buildInvoiceSnapshot(): array
    {
        $result = $this->validator->validate();

        if (!$result['valid']) {
            $messages = [];
            foreach ($result['missing'] as $group => $keys) {
                $messages["configuration_snapshot.{$group}"] = [
                    "Faltan configuraciones obligatorias en el grupo '{$group}': "
                    . implode(', ', array_keys($keys)),
                ];
            }
            foreach ($result['invalid'] as $group => $keys) {
                foreach ($keys as $key => $reason) {
                    $messages["configuration_snapshot.{$group}.{$key}"] = [$reason];
                }
            }
            throw ValidationException::withMessages($messages);
        }

        // Snapshot only includes facturacion module settings
        $settings = Setting::module('facturacion')->get();

        $snapshot = [];
        foreach ($settings as $setting) {
            $snapshot[$setting->key] = [
                'value'   => $setting->typed_value,
                '_public' => $setting->is_public,
            ];
        }

        return $snapshot;
    }

    public function taxRateFromSnapshot(array $snapshot): float
    {
        return (float) ($snapshot['tax_rate']['value'] ?? 0);
    }

    /**
     * Plazo de vencimiento (días) configurado en system_settings → facturacion.
     * Si el setting está ausente, se devuelve el fallback (15 días, comportamiento histórico).
     */
    public function invoiceDueDaysFromSnapshot(array $snapshot, int $fallback = 15): int
    {
        $raw = $snapshot['invoice_due_days']['value'] ?? $fallback;
        $days = (int) $raw;
        // El plazo debe ser >= 0; si llega un valor inválido, se usa el fallback.
        return $days >= 0 ? $days : $fallback;
    }

    public function publicEntries(array $snapshot): array
    {
        return array_filter($snapshot, fn ($entry) => ($entry['_public'] ?? false) === true);
    }

    public function publicFlatMap(array $snapshot): array
    {
        return array_map(fn ($entry) => $entry['value'], $this->publicEntries($snapshot));
    }
}
