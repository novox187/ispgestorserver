<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class Audit extends Model
{
    // Desactivar updated_at ya que los registros de auditoría son inmutables
    const UPDATED_AT = null;

    /**
     * Clases permitidas para resolver el usuario polimórfico. Evita instanciar
     * clases arbitrarias a partir del valor almacenado en `user_type`.
     */
    private const ALLOWED_USER_TYPES = [
        Employee::class,
        User::class,
        Client::class,
    ];

    protected $fillable = [
        'table_name',
        'operation',
        'record_id',
        'old_values',
        'new_values',
        'user_id',
        'user_type',
        'ip_address',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Los registros de auditoría son inmutables: una vez creados no pueden
     * modificarse ni eliminarse a través del modelo. Cualquier intento se
     * bloquea de forma explícita para que el fallo sea visible.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Los registros de auditoría son inmutables y no pueden modificarse.');
        });

        static::deleting(function () {
            throw new LogicException('Los registros de auditoría son inmutables y no pueden eliminarse.');
        });
    }

    /**
     * Relación polimórfica con el usuario que realizó la acción.
     */
    public function user()
    {
        return $this->morphTo();
    }

    /**
     * Resuelve el usuario que ejecutó la acción validando que `user_type`
     * sea una clase permitida. Devuelve null para registros de sistema
     * (user_id null) o registros antiguos sin user_type.
     */
    public function resolveUser(): ?Model
    {
        if (!$this->user_id || !$this->user_type) {
            return null;
        }

        if (!in_array($this->user_type, self::ALLOWED_USER_TYPES, true)) {
            return null;
        }

        return $this->user_type::find($this->user_id);
    }

    /**
     * Scope: auditorías de un registro concreto de una tabla.
     */
    public function scopeForRecord(Builder $query, string $table, string|int $recordId): Builder
    {
        return $query->where('table_name', $table)
            ->where('record_id', (string) $recordId);
    }

    /**
     * Representación uniforme para las respuestas de la API de auditoría.
     */
    public function toApiArray(): array
    {
        $user = $this->resolveUser();

        return [
            'id'          => $this->id,
            'table_name'  => $this->table_name,
            'operation'   => $this->operation,
            'record_id'   => $this->record_id,
            'old_values'  => $this->old_values,
            'new_values'  => $this->new_values,
            'user_id'     => $this->user_id,
            'user_type'   => $this->user_type ? class_basename($this->user_type) : null,
            'user_name'   => $user?->name ?? $user?->nombre ?? null,
            'user_email'  => $user?->email ?? null,
            'ip_address'  => $this->ip_address,
            'created_at'  => $this->created_at,
        ];
    }
}
