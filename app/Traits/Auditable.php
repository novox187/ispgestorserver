<?php

namespace App\Traits;

use App\Models\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

trait Auditable
{
    /**
     * Campos excluidos de las auditorías de UPDATE: cambian en cada save()
     * y no aportan valor de negocio (la fecha del evento ya queda en
     * audits.created_at).
     */
    protected static array $auditExcludedFields = ['updated_at', 'created_at'];

    /**
     * Exclusiones ADICIONALES propias de cada modelo.
     *
     * Existe como método y no como propiedad porque PHP prohíbe redeclarar en
     * la clase una propiedad del trait con un valor distinto (error fatal de
     * composición). Los modelos que reciben escrituras de alta frecuencia en
     * campos sin valor de negocio —p. ej. los de salud que el monitor de
     * conectividad reescribe cada 5 minutos— la sobrescriben para no ahogar el
     * historial con ruido.
     *
     * @return list<string>
     */
    protected static function auditIgnoredFields(): array
    {
        return [];
    }

    /**
     * Lista efectiva de campos a ignorar: los comunes más los del modelo.
     *
     * @return list<string>
     */
    protected static function resolvedAuditExcludedFields(): array
    {
        return array_merge(static::$auditExcludedFields, static::auditIgnoredFields());
    }

    /**
     * Nombre con el que este modelo se identifica en `audits.table_name`.
     *
     * Por defecto es la tabla real, que es lo correcto salvo cuando varios
     * modelos comparten tabla o cuando una tabla se renombra: en ambos casos el
     * historial se partiría en dos y el visor mostraría la vida de un mismo
     * equipo troceada. `MikrotikRouter` y `NetworkDevice` conviven sobre
     * `network_devices` y ambos devuelven ese nombre, de modo que el historial
     * de un router es uno solo se escriba desde donde se escriba.
     */
    protected static function auditTableName(Model $model): string
    {
        return $model->getTable();
    }

    /**
     * Boot the trait.
     */
    public static function bootAuditable()
    {
        static::created(function ($model) {
            self::logAudit('INSERT', $model);
        });

        static::updated(function ($model) {
            self::logAudit('UPDATE', $model);
        });

        static::deleted(function ($model) {
            self::logAudit('DELETE', $model);
        });
    }

    /**
     * Log the audit record.
     */
    protected static function logAudit($operation, $model)
    {
        $oldValues = null;
        $newValues = null;

        if ($operation === 'INSERT') {
            $newValues = $model->attributesToArray();
        } elseif ($operation === 'UPDATE') {
            // Obtener cambios excluyendo timestamps automáticos (ruido)
            $changes = array_diff_key(
                $model->getChanges(),
                array_flip(static::resolvedAuditExcludedFields())
            );

            // Touch puro (solo updated_at): sin valor de auditoría, no registrar
            if (empty($changes)) {
                return;
            }

            $newValues = $changes;

            // Obtener valores originales solo de los campos cambiados
            $original = $model->getOriginal();
            $oldValues = array_intersect_key($original, $changes);
        } elseif ($operation === 'DELETE') {
            $oldValues = $model->attributesToArray();
        }

        // Filtrar atributos ocultos (hidden) si es necesario para seguridad
        if ($model->getHidden()) {
            if ($oldValues) {
                $oldValues = array_diff_key($oldValues, array_flip($model->getHidden()));
            }
            if ($newValues) {
                $newValues = array_diff_key($newValues, array_flip($model->getHidden()));
            }
        }

        $user = Auth::user();

        // Un fallo al persistir la auditoría no debe romper la operación de
        // negocio que la originó: se registra en el log para revisión.
        try {
            Audit::create([
                'table_name' => static::auditTableName($model),
                'operation' => $operation,
                'record_id' => (string) $model->getKey(),
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'user_id' => $user ? $user->id : null,
                'user_type' => $user ? get_class($user) : null,
                'ip_address' => Request::ip(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Auditable: fallo al registrar auditoría.', [
                'table'     => static::auditTableName($model),
                'operation' => $operation,
                'record_id' => (string) $model->getKey(),
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
