<?php

namespace App\Traits;

use App\Models\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait Auditable
{
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
            // Obtener cambios
            $changes = $model->getChanges();
            // Eliminar updated_at de los cambios si no es relevante para la auditoría de negocio
            // (Opcional, pero suele ensuciar el log. Lo dejamos por precisión)
            
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

        Audit::create([
            'table_name' => $model->getTable(),
            'operation' => $operation,
            'record_id' => (string) $model->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'user_id' => Auth::id(), // ID del usuario autenticado o null
            'ip_address' => Request::ip(), // IP del cliente
        ]);
    }
}
