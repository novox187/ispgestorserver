<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
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

        $this->command->info('Planes creados: ' . count($plans));
    }
}