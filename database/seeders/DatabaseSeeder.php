<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Client;
use App\Models\Servicio;
use App\Models\Support;
use App\Models\Administrador;
use App\Models\Rol;
use App\Models\ClientPlan;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Crear 5 planes diferentes con datos específicos (únicos) usando sequence.
        // Esto debe ir primero ya que los Servicios dependen de los Planes.
        $plans = Plan::factory()
            ->count(5)
            ->sequence(
                [
                    'name' => 'Plan Básico 50Mbps',
                    'slug' => 'plan-basico-50mbps',
                    'download_speed' => 50, 
                    'upload_speed' => 10, 
                    'monthly_price' => 30.50,
                    'category' => 'residential',
                    'setup_price' => 0,
                    'is_featured' => false,
                    'description' => 'Perfecto para uso familiar básico y navegación web. Velocidades 50Mbps descarga / 10Mbps subida.',
                    'mikrotik_queue_name' => 'Plan_50M_10M',
                    'download_limit' => '50M',
                    'upload_limit' => '10M',
                    'burst_limit' => '75M/15M',
                ],
                [
                    'name' => 'Plan Hogar 100Mbps',
                    'slug' => 'plan-hogar-100mbps',
                    'download_speed' => 100, 
                    'upload_speed' => 20, 
                    'monthly_price' => 55.90,
                    'category' => 'residential', 
                    'setup_price' => 0,
                    'is_featured' => true,
                    'description' => 'Ideal para hogares con múltiples dispositivos y streaming en HD. Velocidades 100Mbps descarga / 20Mbps subida.',
                    'mikrotik_queue_name' => 'Plan_100M_20M',
                    'download_limit' => '100M',
                    'upload_limit' => '20M',
                    'burst_limit' => '150M/30M',
                ],
                [
                    'name' => 'Plan Avanzado 200Mbps',
                    'slug' => 'plan-avanzado-200mbps',
                    'download_speed' => 200, 
                    'upload_speed' => 40, 
                    'monthly_price' => 75.00,
                    'category' => 'premium',
                    'setup_price' => 25.00,
                    'is_featured' => true,
                    'description' => 'Experiencia premium para usuarios exigentes y gaming. Velocidades 200Mbps descarga / 40Mbps subida.',
                    'mikrotik_queue_name' => 'Plan_200M_40M',
                    'download_limit' => '200M',
                    'upload_limit' => '40M',
                    'burst_limit' => '300M/60M',
                ],
                [
                    'name' => 'Plan Empresarial 500Mbps',
                    'slug' => 'plan-empresarial-500mbps',
                    'download_speed' => 500, 
                    'upload_speed' => 100, 
                    'monthly_price' => 99.99,
                    'category' => 'business',
                    'setup_price' => 50.00,
                    'is_featured' => false,
                    'description' => 'Solución profesional para empresas con alta demanda. Velocidades 500Mbps descarga / 100Mbps subida.',
                    'mikrotik_queue_name' => 'Plan_500M_100M',
                    'download_limit' => '500M',
                    'upload_limit' => '100M',
                    'burst_limit' => '750M/150M',
                ],
                [
                    'name' => 'Plan Corporativo 1Gbps',
                    'slug' => 'plan-corporativo-1gbps',
                    'download_speed' => 1000, 
                    'upload_speed' => 200, 
                    'monthly_price' => 150.00,
                    'category' => 'business',
                    'setup_price' => 75.00,
                    'is_featured' => false,
                    'description' => 'Máximo rendimiento para corporaciones y centros de datos. Velocidades 1Gbps descarga / 200Mbps subida.',
                    'mikrotik_queue_name' => 'Plan_1000M_200M',
                    'download_limit' => '1000M',
                    'upload_limit' => '200M',
                    'burst_limit' => '1500M/300M',
                ],
            )
            ->create(); 

        // 1.1. Crear características para cada plan
        $this->createPlanFeatures($plans);

/* CLIENT PLAN ---inicio---*/

// Primero crear algunos clients para asignarles planes
$clients = Client::factory()
    ->count(20)
    ->active() // Ahora el método active() existe y funciona
    ->withIp() // Asegurar que tengan IP
    ->create();

$plans = Plan::active()->get();

foreach ($clients as $client) {
    ClientPlan::factory()
        ->forClient($client)
        ->forPlan($plans->random())
        ->active()
        ->withIp()
        ->create();
}

// Crear algunos planes suspendidos
ClientPlan::factory()
    ->count(5)
    ->suspended()
    ->create();

// Crear planes que vencen pronto
ClientPlan::factory()
    ->count(3)
    ->expiringSoon()
    ->create();

// Plan con precio personalizado (promoción)
ClientPlan::factory()
    ->forClient($clients->first())
    ->forPlan($plans->first())
    ->withCustomPrice(19.99)
    ->active()
    ->create();

/* CLIENT PLAN ---fin--- */
        
        // 2. Crear 30 clientes adicionales (total 50: 20 con planes + 30 normales)
        Client::factory()
            ->count(30)
            ->create();
        
        // 3. Crear 150 tickets de soporte.
        // Se asocian a clientes existentes creados en el paso anterior.
        Support::factory()->count(150)->create();

        // 4. Crear roles base del sistema RBAC
        $roles = [
            ['nombre' => 'Super Admin', 'slug' => 'super_admin', 'descripcion' => 'Acceso total al sistema'],
            ['nombre' => 'Facturación', 'slug' => 'facturacion', 'descripcion' => 'Gestión de cobros y facturas'],
            ['nombre' => 'Técnico', 'slug' => 'tecnico', 'descripcion' => 'Operaciones técnicas y soporte'],
        ];
        foreach ($roles as $r) {
            Rol::firstOrCreate(['slug' => $r['slug']], $r);
        }

        $super = Rol::where('slug', 'super_admin')->first();
        $factu = Rol::where('slug', 'facturacion')->first();
        $tec = Rol::where('slug', 'tecnico')->first();

        // 5. Crear 3 administradores asignados a los roles
        Administrador::factory()->create(['fk_rol_id' => $super?->id_rol]);
        Administrador::factory()->create(['fk_rol_id' => $factu?->id_rol]);
        Administrador::factory()->create(['fk_rol_id' => $tec?->id_rol]);

        $this->command->info('¡Base de datos poblada con éxito con 5 Planes, 50 Clientes, 150 Tickets, Roles base, 3 Administradores, Características de Planes y 24 ClientPlans!');
    }

    /**
     * Crear características específicas para cada plan
     */
    private function createPlanFeatures($plans): void
    {
        foreach ($plans as $plan) {
            $features = $this->getFeaturesForPlan($plan);
            
            foreach ($features as $index => $feature) {
                PlanFeature::factory()->create([
                    'plan_id' => $plan->id,
                    'feature' => $feature['text'],
                    'icon' => $feature['icon'],
                    'order' => $index,
                    'highlighted' => $feature['highlighted'],
                ]);
            }
        }
    }

    /**
     * Obtener características específicas según el tipo de plan
     */
    private function getFeaturesForPlan(Plan $plan): array
    {
        $baseFeatures = [
            ['text' => 'Soporte técnico', 'icon' => 'headphones', 'highlighted' => false],
            ['text' => 'Sin límite de datos', 'icon' => 'bar-chart', 'highlighted' => true],
            ['text' => 'Conexión estable', 'icon' => 'zap', 'highlighted' => false],
        ];

        switch ($plan->category) {
            case 'residential':
                $specificFeatures = [
                    ['text' => 'Instalación gratuita', 'icon' => 'tool', 'highlighted' => true],
                    ['text' => 'Router WiFi incluido', 'icon' => 'wifi', 'highlighted' => false],
                    ['text' => 'Streaming en HD', 'icon' => 'tv', 'highlighted' => true],
                    ['text' => 'Múltiples dispositivos', 'icon' => 'monitor', 'highlighted' => false],
                ];
                break;

            case 'premium':
                $specificFeatures = [
                    ['text' => 'Router WiFi 6', 'icon' => 'wifi', 'highlighted' => true],
                    ['text' => 'Streaming 4K Ultra HD', 'icon' => 'tv', 'highlighted' => true],
                    ['text' => 'Gaming profesional', 'icon' => 'gamepad', 'highlighted' => true],
                    ['text' => 'Prioridad en la red', 'icon' => 'star', 'highlighted' => true],
                    ['text' => 'Soporte prioritario', 'icon' => 'phone', 'highlighted' => false],
                ];
                break;

            case 'business':
                $specificFeatures = [
                    ['text' => 'IP estática incluida', 'icon' => 'server', 'highlighted' => true],
                    ['text' => 'SLA 99.9%', 'icon' => 'activity', 'highlighted' => true],
                    ['text' => 'Soporte empresarial', 'icon' => 'briefcase', 'highlighted' => true],
                    ['text' => 'Conexión dedicada', 'icon' => 'link', 'highlighted' => true],
                    ['text' => 'Monitoreo 24/7', 'icon' => 'eye', 'highlighted' => false],
                    ['text' => 'Backup automático', 'icon' => 'hard-drive', 'highlighted' => false],
                ];
                break;

            default:
                $specificFeatures = [];
                break;
        }

        // Combinar características base con las específicas
        return array_merge($baseFeatures, $specificFeatures);
    }
}