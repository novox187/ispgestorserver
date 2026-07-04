<?php

namespace App\Traits;

use App\Models\Audit;
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
                array_flip(static::$auditExcludedFields)
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
                'table_name' => $model->getTable(),
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
                'table'     => $model->getTable(),
                'operation' => $operation,
                'record_id' => (string) $model->getKey(),
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
