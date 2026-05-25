<?php

namespace Database\Seeders;

use App\Models\NotificationChannelConfig;
use App\Notifications\Core\ChannelCatalog;
use Illuminate\Database\Seeder;

/**
 * Crea filas placeholder en `notification_channel_configs` para cada canal
 * disponible en el catálogo, en estado deshabilitado y sin credenciales.
 *
 * El módulo de notificaciones depende **exclusivamente de la base de datos**:
 * no se leen variables de entorno para credenciales, chat IDs ni settings.
 * Este seeder existe únicamente para que el panel de administración tenga una
 * fila visible por canal después de un `migrate:fresh --seed`; el admin debe
 * entrar al panel, configurar credenciales, habilitar el canal y elegir las
 * categorías a rutear.
 *
 * Idempotente: `firstOrCreate` nunca pisa configuración existente.
 *
 *   php artisan db:seed --class=NotificationSettingsSeeder
 */
class NotificationSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $created = 0;

        foreach (ChannelCatalog::all() as $channel) {
            if (($channel['status'] ?? null) !== 'available') {
                continue;
            }

            $row = NotificationChannelConfig::firstOrCreate(
                ['channel_key' => $channel['key']],
                [
                    'enabled'     => false,
                    'credentials' => [],
                    'settings'    => [],
                ]
            );

            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->command?->info(
            "NotificationSettingsSeeder: {$created} placeholder(s) creado(s). "
            . 'Habilite cada canal desde el panel (Configuraciones → Notificaciones) '
            . 'y registre las credenciales correspondientes.'
        );
    }
}
