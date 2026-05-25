<?php

namespace App\Notifications\Channels\Telegram;

use App\Notifications\Core\Contracts\NotificationChannel;
use App\Notifications\Core\Exceptions\ChannelNotConfiguredException;
use App\Notifications\Core\Messages\ChannelDeliveryResult;
use App\Notifications\Core\Messages\ChannelRecipient;
use App\Notifications\Core\Messages\NotificationMessage;
use App\Notifications\Core\NotificationConfigRepository;

/**
 * Canal de notificaciones para Telegram Bot API.
 *
 * Implementa la interfaz Strategy. Todas las credenciales y settings se leen
 * desde la BD vía NotificationConfigRepository — el canal nunca consulta
 * variables de entorno ni archivos de configuración estática.
 */
class TelegramChannel implements NotificationChannel
{
    public const DEFAULT_BASE_URL   = 'https://api.telegram.org';
    public const DEFAULT_TIMEOUT    = 10;
    public const DEFAULT_PARSE_MODE = 'MarkdownV2';

    public function __construct(
        private readonly TelegramMessageFormatter      $formatter,
        private readonly NotificationConfigRepository  $configRepo,
    ) {
    }

    public function key(): string
    {
        return 'telegram';
    }

    public function isEnabled(): bool
    {
        $cfg = $this->configRepo->channelConfig('telegram');
        return $cfg['enabled'] && $this->hasBotToken($cfg);
    }

    public function supports(NotificationMessage $message): bool
    {
        return true;
    }

    public function send(NotificationMessage $message, ChannelRecipient $recipient): ChannelDeliveryResult
    {
        $cfg = $this->configRepo->channelConfig('telegram');

        if (!$cfg['enabled']) {
            return ChannelDeliveryResult::permanentFailure(
                'telegram channel is disabled in database (notification_channel_configs.enabled=false)'
            );
        }

        if (!$this->hasBotToken($cfg)) {
            return ChannelDeliveryResult::permanentFailure(
                'telegram bot_token missing in database (notification_channel_configs.credentials.bot_token)'
            );
        }

        if ($recipient->address === '') {
            return ChannelDeliveryResult::permanentFailure(
                'telegram recipient address is empty — configure address_override en la ruta de la categoría o default_address en el canal'
            );
        }

        $client    = $this->makeClient($cfg);
        $text      = $this->formatter->format($message);
        $parseMode = (string) ($cfg['settings']['parse_mode'] ?? self::DEFAULT_PARSE_MODE);
        $threadId  = $recipient->metadata['thread_id'] ?? null;

        $result = $client->sendMessage(
            chatId:          $recipient->address,
            text:            $text,
            parseMode:       $parseMode,
            messageThreadId: $threadId,
        );

        if ($result->success && !empty($message->attachments)) {
            foreach ($message->attachments as $attachment) {
                $type    = $attachment['type'] ?? 'document';
                $url     = $attachment['url'] ?? null;
                $caption = $attachment['caption'] ?? null;
                if (!is_string($url) || $url === '') continue;

                if ($type === 'photo') {
                    $client->sendPhoto($recipient->address, $url, $caption, $parseMode);
                } else {
                    $client->sendDocument($recipient->address, $url, $caption, $parseMode);
                }
            }
        }

        return $result;
    }

    private function hasBotToken(array $cfg): bool
    {
        $token = $cfg['credentials']['bot_token'] ?? null;
        return is_string($token) && $token !== '';
    }

    private function makeClient(array $cfg): TelegramClient
    {
        $baseUrl = (string) ($cfg['settings']['base_url'] ?? self::DEFAULT_BASE_URL);
        $timeout = (int)    ($cfg['settings']['timeout']  ?? self::DEFAULT_TIMEOUT);

        return new TelegramClient(
            botToken: (string) $cfg['credentials']['bot_token'],
            baseUrl:  $baseUrl !== '' ? $baseUrl : self::DEFAULT_BASE_URL,
            timeout:  $timeout > 0  ? $timeout  : self::DEFAULT_TIMEOUT,
        );
    }
}
