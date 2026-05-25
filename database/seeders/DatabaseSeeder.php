<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // 1. Permisos primero
            PermissionSeeder::class,

            // 2. Roles y administradores
            RolSeeder::class,
            RolePermissionSeeder::class,
            //AdministradorSeeder::class,

            // 3. Planes y características
            // PlanSeeder::class,
            // PlanFeatureSeeder::class,

            // 4. Configuraciones del sistema
            SystemSettingsSeeder::class,

            // 5. Automatizaciones (workers/schedulers)
            AutomationSettingsSeeder::class,

            // 6. Notificaciones (rehidrata canales y rutas desde .env si las
            // tablas están vacías). Útil tras un migrate:fresh para no perder
            // la configuración del panel.
            NotificationSettingsSeeder::class,

            // --- Seeders de prueba (datos ficticios) ---
            // ClientSeeder::class,
            // ClientPlanSeeder::class,
            // WalletSeeder::class,
            // TransactionSeeder::class,
            // SupportSeeder::class,
        ]);

        $this->command->info('¡Base de datos poblada completamente!');
    }
}