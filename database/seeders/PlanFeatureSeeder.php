<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Database\Seeder;

class PlanFeatureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = Plan::all();

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

        $this->command->info('espesificaciones creadas');
    }
}