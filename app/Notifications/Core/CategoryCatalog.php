<?php

namespace App\Notifications\Core;

use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;

/**
 * Catálogo de categorías expuestas al panel de configuración.
 *
 * Incluye agrupación visual (MikroTik, Workers, Sistema), severidad sugerida y
 * label descriptiva. Solo se listan aquí las categorías que el admin debe poder
 * configurar — internas como META_FAILURE quedan fuera del panel.
 */
class CategoryCatalog
{
    /**
     * @return array<int, array{
     *   group:string,
     *   group_label:string,
     *   items: array<int, array{key:string,label:string,description:string,default_severity:string}>
     * }>
     */
    /**
     * Solo se exponen al panel las categorías que algún componente del sistema
     * efectivamente emite hoy. Las categorías declaradas en el enum pero sin
     * productor (SSL_EXPIRATION, RESOURCE_USAGE, DB_SYNC_FAILURE, SERVICE_HEALTH,
     * INFO_TASK_COMPLETED) permanecen disponibles para implementaciones futuras
     * pero no se ofrecen como opciones configurables — así el admin no se
     * encuentra con checkboxes que no hacen nada.
     */
    public static function groups(): array
    {
        return [
            [
                'group'       => 'mikrotik',
                'group_label' => 'MikroTik',
                'items'       => [
                    self::item(NotificationCategory::MIKROTIK_CONNECTIVITY, 'Pérdida de conectividad', 'Alerta crítica cuando un router deja de responder a los chequeos de salud.'),
                    self::item(NotificationCategory::MIKROTIK_RECOVERY,     'Recuperación de conectividad', 'Aviso informativo cuando un router desconectado vuelve a responder.'),
                ],
            ],
            [
                'group'       => 'workers',
                'group_label' => 'Workers automáticos',
                'items'       => [
                    self::item(NotificationCategory::WORKER_SUMMARY,  'Resumen de ejecución',  'Métricas y resultados al final de cada corrida exitosa de un worker.'),
                    self::item(NotificationCategory::WORKER_FAILURE,  'Fallos de workers',     'Excepciones no capturadas o resultados con errores agregados.'),
                ],
            ],
        ];
    }

    private static function item(NotificationCategory $cat, string $label, string $description): array
    {
        return [
            'key'              => $cat->value,
            'label'            => $label,
            'description'      => $description,
            'default_severity' => $cat->defaultSeverity()->value,
        ];
    }

    public static function isExposed(string $categoryKey): bool
    {
        foreach (self::groups() as $g) {
            foreach ($g['items'] as $item) {
                if ($item['key'] === $categoryKey) return true;
            }
        }
        return false;
    }

    /**
     * @return string[]
     */
    public static function severities(): array
    {
        return array_map(fn (NotificationSeverity $s) => $s->value, NotificationSeverity::cases());
    }
}
