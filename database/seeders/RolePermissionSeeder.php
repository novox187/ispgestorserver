<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $superAdmin = Role::where('slug', 'super_admin')->first();
        $facturacion = Role::where('slug', 'facturacion')->first();
        $tecnico = Role::where('slug', 'tecnico')->first();

        // Super Admin gets all permissions
        if ($superAdmin) {
            $allPermissions = Permission::all();
            $superAdmin->permissions()->attach($allPermissions);
        }

        // Facturacion gets billing related permissions
        if ($facturacion) {
            $billingPermissions = Permission::whereIn('slug', [
                'crear_factura',
                'editar_factura',
                'eliminar_factura',
                'ver_facturas',
                'crear_cliente',
                'editar_cliente',
                'eliminar_cliente',
                'ver_clientes',
                'ver_usuarios',
            ])->get();
            $facturacion->permissions()->attach($billingPermissions);
        }

        // Tecnico gets technical permissions
        if ($tecnico) {
            $technicalPermissions = Permission::whereIn('slug', [
                'crear_plan',
                'editar_plan',
                'eliminar_plan',
                'ver_planes',
                'ver_clientes',
                'ver_usuarios',
            ])->get();
            $tecnico->permissions()->attach($technicalPermissions);
        }

        $this->command->info('Permisos asignados a roles');
    }
}