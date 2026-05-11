<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Pure validation service — no side-effects, no exceptions.
 *
 * Returns a structured result so callers can decide how to handle failures
 * (throw, log, return 422, show UI alert, etc.) without coupling validation
 * logic to any particular HTTP or exception strategy.
 */
class InvoiceConfigValidator
{
    // ── Required keys grouped by category ────────────────────────────────────

    public const REQUIRED = [
        'issuer' => [
            'issuer_name'    => 'Razón social del emisor',
            'issuer_nit'     => 'NIT / Identificación fiscal',
            'issuer_address' => 'Dirección fiscal',
            'issuer_city'    => 'Ciudad del emisor',
            'issuer_country' => 'País del emisor',
            'issuer_email'   => 'Correo de facturación',
        ],
        'tax' => [
            'tax_rate' => 'Tasa de impuesto',
            'tax_name' => 'Nombre del impuesto',
        ],
        'currency' => [
            'currency_code'   => 'Código ISO de moneda',
            'currency_symbol' => 'Símbolo de moneda',
        ],
        'legal' => [
            'invoice_resolution_number' => 'Número de resolución',
            'invoice_resolution_date'   => 'Fecha de resolución',
        ],
    ];

    // ── Business rules applied after presence check ───────────────────────────

    /** tax_rate must be a number between 0 and 1 (exclusive upper). */
    private const TAX_RATE_MAX = 1.0;

    /** invoice_resolution_date must not be in the future (it's a grant date). */

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run the full validation.
     *
     * Returns an array with shape:
     * [
     *   'valid'    => bool,
     *   'missing'  => [ 'group' => ['key' => 'label', ...], ... ],
     *   'invalid'  => [ 'group' => ['key' => 'reason', ...], ... ],
     *   'messages' => string[]   // human-readable, suitable for UI alerts
     * ]
     */
    public function validate(): array
    {
        $allKeys  = $this->allRequiredKeys();
        $existing = Setting::whereIn('key', $allKeys)->pluck('value', 'key');

        $missing = [];
        $invalid = [];

        foreach (self::REQUIRED as $group => $keys) {
            foreach ($keys as $key => $label) {
                $value = isset($existing[$key]) ? trim((string) $existing[$key]) : null;

                // 1. Presence check
                if ($value === null || $value === '') {
                    $missing[$group][$key] = $label;
                    continue;
                }

                // 2. Business-rule checks
                $reason = $this->businessRule($group, $key, $value);
                if ($reason !== null) {
                    $invalid[$group][$key] = $reason;
                }
            }
        }

        $messages = $this->buildMessages($missing, $invalid);

        return [
            'valid'    => empty($missing) && empty($invalid),
            'missing'  => $missing,
            'invalid'  => $invalid,
            'messages' => $messages,
        ];
    }

    /**
     * Convenience wrapper — throws \RuntimeException with a structured payload
     * when validation fails. Used by services that prefer exception flow.
     *
     * @throws \RuntimeException
     */
    public function assertValid(): void
    {
        $result = $this->validate();
        if (!$result['valid']) {
            throw new \RuntimeException(json_encode([
                'type'     => 'invoice_config_invalid',
                'missing'  => $result['missing'],
                'invalid'  => $result['invalid'],
                'messages' => $result['messages'],
            ]));
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function businessRule(string $group, string $key, string $value): ?string
    {
        return match ($key) {
            'tax_rate' => $this->validateTaxRate($value),
            'invoice_resolution_date' => $this->validateResolutionDate($value),
            'issuer_email' => $this->validateEmail($value),
            default => null,
        };
    }

    private function validateTaxRate(string $value): ?string
    {
        $rate = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($rate === false) {
            return 'Debe ser un número decimal (ej: 0.15)';
        }
        if ($rate < 0 || $rate >= self::TAX_RATE_MAX) {
            return "Debe estar entre 0 y " . self::TAX_RATE_MAX . " (exclusivo). Valor actual: {$value}";
        }
        return null;
    }

    private function validateResolutionDate(string $value): ?string
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            return "Formato inválido. Use YYYY-MM-DD. Valor actual: {$value}";
        }
        // Resolution dates should not be in the future (they are official grants)
        if ($date > new \DateTime('today')) {
            return "La fecha de resolución no puede ser futura. Valor actual: {$value}";
        }
        return null;
    }

    private function validateEmail(string $value): ?string
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return "Correo electrónico inválido. Valor actual: {$value}";
        }
        return null;
    }

    private function buildMessages(array $missing, array $invalid): array
    {
        $messages = [];

        foreach ($missing as $group => $keys) {
            $labels = array_values($keys);
            $messages[] = "Faltan datos en '{$group}': " . implode(', ', $labels) . '.';
        }

        foreach ($invalid as $group => $keys) {
            foreach ($keys as $key => $reason) {
                $messages[] = "Configuración inválida en '{$group}' → {$key}: {$reason}";
            }
        }

        return $messages;
    }

    private function allRequiredKeys(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::REQUIRED)));
    }
}
