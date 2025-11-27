<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Hash;

class Employee extends Authenticatable
{
    use HasFactory;

    protected $table = 'employees';

    protected $primaryKey = 'id';

    protected $fillable = [
        'nombre',
        'email',
        'password',
        'telefono',
        'role_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id', 'id');
    }

    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = Hash::make($value);
    }

    public function hasPermission(string $slug): bool
    {
        return $this->role?->permissions->contains('slug', $slug) ?? false;
    }

    public function hasRole(string $slug): bool
    {
        return $this->role?->slug === $slug;
    }
}
