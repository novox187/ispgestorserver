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
            'issuer_ruc'     => 'RUC / Identificación fiscal (13 dígitos)',
            'issuer_address' => 'Dirección fiscal',
            'issuer_city'    => 'Ciudad del emisor',
            'issuer_country' => 'País del emisor',
            'issuer_email'   => 'Correo de facturación',
        ],
        'tax' => [
            'tax_rate' => 'Tasa de IVA',
            'tax_name' => 'Nombre del impuesto',
        ],
        'currency' => [
            'currency_code'   => 'Código ISO de moneda',
            'currency_symbol' => 'Símbolo de moneda',
        ],
        'legal' => [
            'sri_establishment_code' => 'Código de establecimiento SRI (3 dígitos)',
            'sri_emission_point'     => 'Código del punto de emisión SRI (3 dígitos)',
        ],
    ];

    // ── Business rules applied after presence check ───────────────────────────

    private const TAX_RATE_MAX = 1.0;

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
                $reason = $this->businessRule($key, $value);
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

    private function businessRule(string $key, string $value): ?string
    {
        return match ($key) {
            'tax_rate'               => $this->validateTaxRate($value),
            'issuer_email'           => $this->validateEmail($value),
            'issuer_ruc'             => $this->validateRuc($value),
            'sri_establishment_code',
            'sri_emission_point'     => $this->validateSriCode($value),
            default                  => null,
        };
    }

    private function validateTaxRate(string $value): ?string
    {
        $rate = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($rate === false) {
            return 'Debe ser un número decimal (ej: 0.15 para 15%)';
        }
        if ($rate < 0 || $rate >= self::TAX_RATE_MAX) {
            return 'Debe estar entre 0 y ' . self::TAX_RATE_MAX . ' (exclusivo). Valor actual: ' . $value;
        }
        return null;
    }

    /**
     * Valida el RUC ecuatoriano:
     * - Exactamente 13 dígitos numéricos
     * - Primeros 2 dígitos: código de provincia (01–24)
     * - Últimos 3 dígitos (código de establecimiento): no pueden ser 000
     */
    private function validateRuc(string $value): ?string
    {
        $clean = preg_replace('/\D/', '', $value);

        if (strlen($clean) !== 13) {
            return 'El RUC debe tener exactamente 13 dígitos numéricos. Actual: ' . strlen($clean) . ' dígito(s).';
        }

        $province = (int) substr($clean, 0, 2);
        if ($province < 1 || $province > 24) {
            return 'Los primeros 2 dígitos deben ser un código de provincia ecuatoriana (01-24). Actual: ' . substr($clean, 0, 2);
        }

        if (substr($clean, 10, 3) === '000') {
            return 'Los últimos 3 dígitos (código de establecimiento) no pueden ser 000.';
        }

        return null;
    }

    /**
     * Valida el código de establecimiento o punto de emisión SRI:
     * - Exactamente 3 dígitos numéricos
     * - No puede ser 000
     */
    private function validateSriCode(string $value): ?string
    {
        if (!preg_match('/^\d{3}$/', $value)) {
            return 'Debe ser exactamente 3 dígitos numéricos (ej: 001). Valor actual: ' . $value;
        }
        if ($value === '000') {
            return 'El código no puede ser 000. Use 001 o superior.';
        }
        return null;
    }

    private function validateEmail(string $value): ?string
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'Correo electrónico inválido. Valor actual: ' . $value;
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
