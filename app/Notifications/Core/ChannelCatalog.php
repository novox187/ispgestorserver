<?php

namespace App\Notifications\Core;

/**
 * Catálogo declarativo de canales que el frontend puede listar.
 *
 * Contiene metadatos descriptivos (label, ícono, status, esquema de credentials)
 * que el panel usa para construir las pestañas y los formularios. Mover esto al
 * controller mezclaría preocupaciones — vive aquí para que extender el panel a
 * un canal nuevo solo requiera agregar una entrada acá.
 */
class ChannelCatalog
{
    /**
     * @return array<int, array{
     *   key:string, label:string, status:string, description:string,
     *   credentials_schema:array<int,array{key:string,label:string,sensitive:bool,required:bool,placeholder?:string}>,
     *   settings_schema:array<int,array{key:string,label:string,sensitive?:bool,required?:bool,placeholder?:string,description?:string}>,
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'key'         => 'telegram',
                'label'       => 'Telegram',
                'status'      => 'available',
                'description' => 'Envía alertas a chats o grupos de Telegram via Bot API.',
                'credentials_schema' => [
                    ['key' => 'bot_token', 'label' => 'Bot API Token', 'sensitive' => true, 'required' => true, 'placeholder' => '123456:ABC...'],
                ],
                'settings_schema' => [
                    // default_address (chat_id) NO es una credencial: es como una
                    // dirección de correo. El admin necesita verlo después de
                    // guardar para confirmar que es el chat correcto.
                    ['key' => 'default_address', 'label' => 'Chat ID por defecto', 'type' => 'text', 'sensitive' => false, 'required' => false, 'placeholder' => '-1001234567890', 'description' => 'Si una categoría no especifica destinatario propio, se usa este.'],
                    [
                        'key'         => 'parse_mode',
                        'label'       => 'Parse Mode',
                        'type'        => 'select',
                        'sensitive'   => false,
                        'required'    => false,
                        'description' => 'Cómo interpretará Telegram el texto del mensaje. MarkdownV2 es el formato recomendado del módulo: produce mensajes formateados (negritas, bloques de código, listas). HTML acepta etiquetas básicas. Plain envía texto sin formato — útil si tienes problemas con caracteres especiales.',
                        'options' => [
                            ['value' => 'MarkdownV2', 'label' => 'MarkdownV2 (recomendado)'],
                            ['value' => 'HTML',       'label' => 'HTML'],
                            ['value' => 'Markdown',   'label' => 'Markdown (legacy)'],
                            ['value' => 'plain',      'label' => 'Sin formato'],
                        ],
                    ],
                ],
            ],
            [
                'key'         => 'email',
                'label'       => 'Email',
                'status'      => 'coming_soon',
                'description' => 'Notificaciones por correo electrónico vía SMTP (próximamente).',
                'credentials_schema' => [],
                'settings_schema' => [],
            ],
            [
                'key'         => 'slack',
                'label'       => 'Slack',
                'status'      => 'coming_soon',
                'description' => 'Mensajes a canales de Slack vía webhook (próximamente).',
                'credentials_schema' => [],
                'settings_schema' => [],
            ],
            [
                'key'         => 'webhook',
                'label'       => 'Webhook',
                'status'      => 'coming_soon',
                'description' => 'Envío genérico HTTP POST a un endpoint propio (próximamente).',
                'credentials_schema' => [],
                'settings_schema' => [],
            ],
        ];
    }

    public static function byKey(string $key): ?array
    {
        foreach (self::all() as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }
        return null;
    }
}
