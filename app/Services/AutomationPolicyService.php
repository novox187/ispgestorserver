<?php

namespace App\Services;

use App\Models\AutomationSetting;
use App\Models\Invoice;
use Illuminate\Support\Collection;

/**
 * Límites y coherencia de los workers automáticos.
 *
 * El panel permitía cualquier cadencia en cualquier worker y guardaba sin
 * mirar el conjunto: se podía poner la facturación mensual «cada cinco
 * minutos», o programar el corte antes que el cobro y dejar a los abonados sin
 * servicio sin haberles intentado cobrar. El servidor solo validaba formatos.
 *
 * Aquí viven las dos reglas que faltaban:
 *
 *   1. Qué cadencias admite cada worker (`config/automations.php`), rechazadas
 *      en el servidor y no solo escondidas en la interfaz.
 *   2. Qué combinaciones entre workers son incoherentes aunque cada uno por
 *      separado sea válido — que es la clase de error que nadie ve al mirar
 *      una sola tarjeta.
 */
class AutomationPolicyService
{
    /** Política declarada para un worker, con los defaults aplicados. */
    public function policyFor(string $key): array
    {
        $default = config('automations.default_policy');
        $policy  = config("automations.policies.{$key}", []);

        return array_merge($default, $policy);
    }

    /**
     * Cadencias que el worker admite.
     *
     * Incluye siempre la que tiene guardada aunque ya no esté permitida: si una
     * configuración anterior quedó fuera del catálogo, el operador tiene que
     * poder verla y cambiarla, no encontrarse un desplegable que miente sobre
     * lo que está corriendo ahora mismo.
     */
    public function allowedSchedules(AutomationSetting $setting): array
    {
        $allowed = $this->policyFor($setting->key)['schedules'];

        if ($setting->schedule_type && !in_array($setting->schedule_type, $allowed, true)) {
            $allowed[] = $setting->schedule_type;
        }

        return array_values($allowed);
    }

    /**
     * @return array<string, string> Errores por campo; vacío si la cadencia vale.
     */
    public function validateScheduleType(AutomationSetting $setting, string $scheduleType): array
    {
        if ($scheduleType === $setting->schedule_type) {
            return []; // No se está cambiando: no se bloquea lo que ya corre.
        }

        if (in_array($scheduleType, $this->policyFor($setting->key)['schedules'], true)) {
            return [];
        }

        $porque = $this->policyFor($setting->key)['why_schedule'];

        return [
            'schedule_type' => trim(
                "Esta frecuencia no está permitida para «{$setting->name}». " . (string) $porque
            ),
        ];
    }

    /**
     * Incoherencias entre workers: cada uno es válido por separado, el conjunto
     * no. Son avisos, no bloqueos — puede haber una razón para dejarlo así, y
     * un panel que impide una configuración legítima es su propia avería. Lo
     * que no puede pasar es que nadie lo diga.
     *
     * @param  Collection<int, AutomationSetting>  $settings
     * @return array<int, array{level: string, title: string, detail: string, keys: array}>
     */
    public function coherenceWarnings(Collection $settings): array
    {
        $porClave = $settings->keyBy('key');
        $avisos   = [];

        $corte        = $porClave->get('client_suspension');
        $cobro        = $porClave->get('auto_billing');
        $reactivacion = $porClave->get('auto_reactivation');
        $facturacion  = $porClave->get('monthly_invoices');
        $integridad   = $porClave->get('billing_integrity');

        // El corte debe mirar una cartera ya cobrada esa madrugada.
        if ($corte?->enabled && $cobro?->enabled
            && $corte->schedule_type === 'daily' && $cobro->schedule_type === 'daily') {
            $horaCorte = $corte->schedule_config['time'] ?? null;
            $horaCobro = $cobro->schedule_config['time'] ?? null;

            if ($horaCorte && $horaCobro && $horaCorte <= $horaCobro) {
                $avisos[] = [
                    'level'  => 'warning',
                    'title'  => 'El corte corre antes que el cobro',
                    'detail' => "Cortas a las {$horaCorte} y cobras a las {$horaCobro}, así que cada madrugada se suspende a gente a la que todavía no se le ha intentado cobrar ese día. Programa el corte al menos una hora después del cobro.",
                    'keys'   => ['client_suspension', 'auto_billing'],
                ];
            }
        }

        // Cortar sin reactivar deja el servicio dependiendo de que cada abonado
        // recargue: quien pague por otra vía se queda fuera.
        if ($corte?->enabled && $reactivacion && !$reactivacion->enabled) {
            $avisos[] = [
                'level'  => 'warning',
                'title'  => 'Se corta pero no se restablece',
                'detail' => 'La suspensión automática está activa y el barrido de reactivación no. Un cliente que salde su deuda por una vía distinta a la recarga de billetera seguirá cortado hasta que alguien lo reactive a mano.',
                'keys'   => ['client_suspension', 'auto_reactivation'],
            ];
        }

        // Se puede cortar sin cobrar automáticamente —la cohorte va por fecha de
        // vencimiento— pero conviene saber que se está haciendo.
        if ($corte?->enabled && $cobro && !$cobro->enabled) {
            $avisos[] = [
                'level'  => 'warning',
                'title'  => 'Se corta sin haber intentado cobrar',
                'detail' => 'Los cobros automáticos están apagados. Se seguirá cortando por fecha de vencimiento, pero a nadie se le habrá intentado cobrar antes salvo en el último intento del propio corte.',
                'keys'   => ['client_suspension', 'auto_billing'],
            ];
        }

        // Sin emisión no hay nada que cobrar ni que vencer.
        if ($facturacion && !$facturacion->enabled) {
            $avisos[] = [
                'level'  => 'warning',
                'title'  => 'No se está emitiendo ninguna factura',
                'detail' => 'La generación mensual está apagada: no se emiten cargos nuevos, así que con el tiempo no habrá nada que cobrar ni que vencer. El resto del ciclo seguirá corriendo en vacío.',
                'keys'   => ['monthly_invoices'],
            ];
        }

        if ($integridad && !$integridad->enabled) {
            $avisos[] = [
                'level'  => 'info',
                'title'  => 'Sin conciliación diaria',
                'detail' => 'La verificación de integridad está apagada. Es la única que detecta un cliente cortado que sigue navegando o uno al corriente que quedó bloqueado en el router.',
                'keys'   => ['billing_integrity'],
            ];
        }

        // El monitor de agentes debe pasar bastante más a menudo que el umbral
        // que él mismo usa para dar por caído a un agente.
        $monitorAgentes = $porClave->get('provisioning_agent_monitor');
        if ($monitorAgentes?->enabled) {
            $umbral = (int) ($monitorAgentes->params['offline_after_minutes'] ?? 0);
            $cada   = $this->intervalMinutes($monitorAgentes->schedule_type);

            if ($umbral > 0 && $cada > 0 && $umbral < $cada) {
                $avisos[] = [
                    'level'  => 'warning',
                    'title'  => 'El monitor de agentes avisa tarde',
                    'detail' => "Da por caído a un agente a los {$umbral} min, pero solo comprueba cada {$cada} min: la alerta llegará siempre con retraso. El umbral debería ser holgadamente mayor que la frecuencia.",
                    'keys'   => ['provisioning_agent_monitor'],
                ];
            }
        }

        return $avisos;
    }

    /**
     * A cuántos clientes afectaría el worker con unos parámetros dados, para
     * poder decirlo ANTES de guardar.
     *
     * Bajar los días de gracia aplica en menos de un minuto y sobre toda la
     * cartera vencida a la vez; sin esta cuenta, el operador solo descubre
     * cuánta gente ha cortado a la mañana siguiente.
     *
     * @return array{supported: bool, count?: int, current_count?: int, label?: string}
     */
    public function previewImpact(AutomationSetting $setting, array $params): array
    {
        if ($setting->key !== 'client_suspension') {
            return ['supported' => false];
        }

        $graceDays = (int) ($params['grace_days'] ?? $setting->params['grace_days'] ?? 3);
        $actuales  = (int) ($setting->params['grace_days'] ?? 3);

        return [
            'supported'     => true,
            'count'         => $this->cohortSize($graceDays),
            'current_count' => $this->cohortSize($actuales),
            'label'         => 'clientes entrarían en la próxima corrida',
        ];
    }

    /** Clientes distintos —no facturas— que caerían en la corrida. */
    private function cohortSize(int $graceDays): int
    {
        return Invoice::suspensionCohort(max(0, $graceDays))
            ->distinct('client_id')
            ->count('client_id');
    }

    /** Minutos entre corridas de una cadencia fija, o 0 si no aplica. */
    private function intervalMinutes(?string $scheduleType): int
    {
        return match ($scheduleType) {
            'every_five_minutes'    => 5,
            'every_ten_minutes'     => 10,
            'every_fifteen_minutes' => 15,
            'every_thirty_minutes'  => 30,
            'hourly'                => 60,
            default                 => 0,
        };
    }
}
