<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Generates permissions from the module × action matrix.
 *
 * Slug format: `{module}.{action}` — e.g. `usuarios.crear`, `clientes.ver`
 *
 * To add a new module or action:
 *   1. Add the entry to the MODULES constant below.
 *   2. Run `php artisan db:seed --class=PermissionSeeder`
 *   3. Mirror the change in ispgestoradmin/src/lib/config/permissions.ts
 *   4. Apply the new middleware slug to the relevant route in routes/api.php
 */
class PermissionSeeder extends Seeder
{
    private const MODULES = [
        ['slug' => 'usuarios',      'label' => 'Usuarios',       'actions' => ['ver', 'crear', 'editar', 'eliminar']],
        ['slug' => 'clientes',      'label' => 'Clientes',        'actions' => ['ver', 'crear', 'editar', 'eliminar']],
        ['slug' => 'planes',        'label' => 'Planes',          'actions' => ['ver', 'crear', 'editar', 'eliminar']],
        ['slug' => 'facturas',      'label' => 'Facturación',     'actions' => ['ver', 'crear', 'editar', 'eliminar']],
        ['slug' => 'mikrotik',      'label' => 'MikroTik',        'actions' => ['ver', 'gestionar']],
        ['slug' => 'proveedores',   'label' => 'Proveedores ISP', 'actions' => ['ver', 'crear', 'editar', 'eliminar']],
        ['slug' => 'soporte',       'label' => 'Soporte / Chat',  'actions' => ['ver', 'gestionar']],
        ['slug' => 'configuracion', 'label' => 'Configuración',   'actions' => ['ver', 'gestionar']],
    ];

    public function run(): void
    {
        $newSlugs = ['acceso_total'];

        foreach (self::MODULES as $module) {
            foreach ($module['actions'] as $action) {
                $slug = "{$module['slug']}.{$action}";

                Permission::firstOrCreate(
                    ['slug' => $slug],
                    [
                        'nombre'      => "{$module['label']} — " . ucfirst($action),
                        'descripcion' => "Permite {$action} en el módulo de {$module['label']}",
                    ]
                );

                $newSlugs[] = $slug;
            }
        }

        // acceso_total is always kept
        Permission::firstOrCreate(
            ['slug' => 'acceso_total'],
            ['nombre' => 'Acceso Total', 'descripcion' => 'Acceso completo a todo el sistema']
        );

        // Remove old-format permissions that are not in the new matrix AND have no roles assigned
        $obsolete = Permission::whereNotIn('slug', $newSlugs)
            ->withCount('roles')
            ->get()
            ->filter(fn ($p) => $p->roles_count === 0);

        foreach ($obsolete as $p) {
            $p->delete();
        }

        $this->command->info('Permisos sincronizados: ' . count($newSlugs));

        if ($obsolete->count() > 0) {
            $this->command->warn("Permisos obsoletos eliminados: {$obsolete->count()} (sin roles asignados)");
        }
    }
}
