<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasFactory;

    protected $table = 'permissions';
    protected $primaryKey = 'id';

    protected $fillable = [
        'nombre',
        'slug',
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
