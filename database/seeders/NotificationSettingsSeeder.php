<?php

namespace Database\Seeders;

use App\Models\NotificationChannelConfig;
use App\Models\NotificationEventRoute;
use App\Notifications\Core\CategoryCatalog;
use Illuminate\Database\Seeder;

/**
 * Rehidrata la configuración del módulo de notificaciones desde variables de
 * entorno. Pensado para correr tras `migrate:fresh --seed` y restaurar el
 * estado del panel sin tener que reconfigurar todo manualmente.
 *
 * Idempotente: usa updateOrCreate / firstOrCreate para no duplicar filas si
 * se vuelve a ejecutar sobre datos existentes.
 *
 *   php artisan db:seed --class=NotificationSettingsSeeder
 */
class NotificationSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedTelegram();
        $this->seedDefaultRoutes();
    }

    private function seedTelegram(): void
    {
        $botToken      = env('TELEGRAM_BOT_TOKEN');
        $chatCritical  = env('TELEGRAM_CHAT_CRITICAL');
        $chatSummary   = env('TELEGRAM_CHAT_SUMMARY');
        $chatInfo      = env('TELEGRAM_CHAT_INFO');

        // Usamos el chat crítico como "default_address" si está definido,
        // ya que es el único de los tres que típicamente está poblado en setups
        // iniciales. El admin puede sobreescribirlo desde el panel.
        $defaultAddress = $chatCritical ?: $chatSummary ?: $chatInfo;

        $credentials = [];
        if ($botToken) {
            $credentials['bot_token'] = $botToken;
        }

        $settings = [
            'parse_mode' => env('TELEGRAM_PARSE_MODE', 'MarkdownV2'),
        ];
        if ($defaultAddress) {
            $settings['default_address'] = $defaultAddress;
        }

        // Solo seedea si tenemos al menos un valor útil — no creamos filas
        // vacías que confundirían al admin con un canal "habilitado pero roto".
        if (empty($credentials) && empty($defaultAddress)) {
            $this->command?->info('NotificationSettingsSeeder: TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_* no están en .env, se omite seed de Telegram.');
            return;
        }

        NotificationChannelConfig::updateOrCreate(
            ['channel_key' => 'telegram'],
            [
                'enabled'     => (bool) env('TELEGRAM_ENABLED', true),
                'credentials' => $credentials,
                'settings'    => $settings,
            ]
        );

        $this->command?->info('NotificationSettingsSeeder: Canal Telegram rehidratado desde .env.');
    }

    /**
     * Habilita por defecto todas las categorías expuestas en el catálogo para
     * cada canal habilitado. Sin esto, después de un seed el admin tiene
     * el canal configurado pero ningún evento ruteado hacia él.
     */
    private function seedDefaultRoutes(): void
    {
        $enabledChannels = NotificationChannelConfig::where('enabled', true)
            ->pluck('channel_key')
            ->all();

        if (empty($enabledChannels)) {
            return;
        }

        foreach (CategoryCatalog::groups() as $group) {
            foreach ($group['items'] as $item) {
                foreach ($enabledChannels as $channelKey) {
                    NotificationEventRoute::firstOrCreate(
                        ['category' => $item['key'], 'channel_key' => $channelKey],
                        ['enabled' => true]
                    );
                }
            }
        }

        $this->command?->info('NotificationSettingsSeeder: Rutas por defecto creadas para ' . count($enabledChannels) . ' canal(es).');
    }
}
