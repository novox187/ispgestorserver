<?php

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('primer login crea super admin si no hay empleados', function () {
    Permission::create(['nombre' => 'Acceso Total', 'slug' => 'acceso_total']);

    $response = $this->postJson('/api/employee/login', [
        'email' => 'root@example.com',
        'password' => 'secret123',
    ]);

    $response
        ->assertOk()
        ->assertJsonStructure(['token', 'employee' => ['id', 'email', 'nombre', 'role', 'role_slug']])
        ->assertJsonPath('employee.email', 'root@example.com')
        ->assertJsonPath('employee.role_slug', 'super_admin');

    expect(Employee::count())->toBe(1);

    $employee = Employee::where('email', 'root@example.com')->first();
    expect($employee)->not->toBeNull();

    $role = Role::where('slug', 'super_admin')->first();
    expect($role)->not->toBeNull();
    expect($employee->role_id)->toBe($role->id);
    expect($role->permissions()->count())->toBe(1);
});

test('si ya existe un empleado, no se crea otro en login', function () {
    Permission::create(['nombre' => 'Acceso Total', 'slug' => 'acceso_total']);

    $this->postJson('/api/employee/login', [
        'email' => 'root@example.com',
        'password' => 'secret123',
    ])->assertOk();

    $this->postJson('/api/employee/login', [
        'email' => 'otro@example.com',
        'password' => 'secret123',
    ])->assertStatus(422);

    expect(Employee::count())->toBe(1);
});

