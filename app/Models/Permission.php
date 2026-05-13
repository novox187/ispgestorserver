<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class Permission extends Model
{
    use HasFactory, Auditable;

    protected $table = 'permissions';
    protected $primaryKey = 'id';

    protected $fillable = [
        'nombre',
        'slug',
        'descripcion',
    ];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permission', 'fk_permission_id', 'fk_role_id');
    }

    public function isAssignedToRole(string $roleSlug): bool
    {
        return $this->roles->contains('slug', $roleSlug);
    }
}
