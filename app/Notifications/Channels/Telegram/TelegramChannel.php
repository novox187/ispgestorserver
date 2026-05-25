<?php

namespace App\Notifications\Channels\Telegram;

use App\Notifications\Core\Contracts\NotificationChannel;
use App\Notifications\Core\Messages\ChannelDeliveryResult;
use App\Notifications\Core\Messages\ChannelRecipient;
use App\Notifications\Core\Messages\NotificationMessage;
use App\Notifications\Core\NotificationConfigRepository;

/**
 * Canal de notificaciones para Telegram Bot API.
 *
 * Implementa la interfaz Strategy. Lee credenciales y settings mediante
 * NotificationConfigRepository — así honra los cambios hechos desde el panel
 * sin necesidad de reiniciar la app ni re-cachear config.
 */
class TelegramChannel implements NotificationChannel
{
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
        return $cfg['enabled'] && !empty($cfg['credentials']['bot_token']);
    }

    public function supports(NotificationMessage $message): bool
    {
        return true;
    }

    public function send(NotificationMessage $message, ChannelRecipient $recipient): ChannelDeliveryResult
    {
        $cfg     = $this->configRepo->channelConfig('telegram');
        $client  = $this->makeClient($cfg);
        $text    = $this->formatter->format($message);
        $parse   = $cfg['settings']['parse_mode'] ?? 'MarkdownV2';

        $threadId = $recipient->metadata['thread_id'] ?? null;

        $result = $client->sendMessage(
            chatId:        $recipient->address,
            text:          $text,
            parseMode:     $parse,
            messageThreadId: $threadId,
        );

        if ($result->success && !empty($message->attachments)) {
            foreach ($message->attachments as $attachment) {
                $type    = $attachment['type'] ?? 'document';
                $url     = $attachment['url'] ?? null;
                $caption = $attachment['caption'] ?? null;
                if (!is_string($url) || $url === '') continue;

                if ($type === 'photo') {
                    $client->sendPhoto($recipient->address, $url, $caption, $parse);
                } else {
                    $client->sendDocument($recipient->address, $url, $caption, $parse);
                }
            }
        }

        return $result;
    }

    private function makeClient(array $cfg): TelegramClient
    {
        return new TelegramClient(
            botToken: (string) ($cfg['credentials']['bot_token'] ?? ''),
            baseUrl:  (string) ($cfg['settings']['base_url'] ?? 'https://api.telegram.org'),
            timeout:  (int)    ($cfg['settings']['timeout']  ?? 10),
        );
    }
}
