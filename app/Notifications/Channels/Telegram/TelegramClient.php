<?php

namespace App\Notifications\Channels\Telegram;

use App\Notifications\Core\Messages\ChannelDeliveryResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP minimal para la Bot API de Telegram.
 *
 * Mantiene la lógica de transporte (timeout, parseo de respuestas, clasificación
 * de errores en transient vs permanent) separada del canal y del formatter, lo
 * que permite testear con Http::fake() sin tocar lógica de presentación.
 */
class TelegramClient
{
    public function __construct(
        private readonly string $botToken,
        private readonly string $baseUrl = 'https://api.telegram.org',
        private readonly int    $timeout = 10,
    ) {
    }

    public function sendMessage(string $chatId, string $text, string $parseMode = 'MarkdownV2', ?string $messageThreadId = null): ChannelDeliveryResult
    {
        $payload = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'disable_web_page_preview' => true,
        ];
        // `plain` es nuestra convención interna para "sin formato": Telegram
        // entiende esto omitiendo el parámetro parse_mode.
        if ($parseMode !== '' && strtolower($parseMode) !== 'plain') {
            $payload['parse_mode'] = $parseMode;
        }
        if ($messageThreadId !== null && $messageThreadId !== '') {
            $payload['message_thread_id'] = (int) $messageThreadId;
        }
        return $this->call('sendMessage', $payload);
    }

    public function sendPhoto(string $chatId, string $photoUrl, ?string $caption = null, string $parseMode = 'MarkdownV2'): ChannelDeliveryResult
    {
        $payload = [
            'chat_id' => $chatId,
            'photo'   => $photoUrl,
        ];
        if ($caption !== null) {
            $payload['caption']    = $caption;
            $payload['parse_mode'] = $parseMode;
        }
        return $this->call('sendPhoto', $payload);
    }

    public function sendDocument(string $chatId, string $documentUrl, ?string $caption = null, string $parseMode = 'MarkdownV2'): ChannelDeliveryResult
    {
        $payload = [
            'chat_id'  => $chatId,
            'document' => $documentUrl,
        ];
        if ($caption !== null) {
            $payload['caption']    = $caption;
            $payload['parse_mode'] = $parseMode;
        }
        return $this->call('sendDocument', $payload);
    }

    private function call(string $method, array $payload): ChannelDeliveryResult
    {
        $url = rtrim($this->baseUrl, '/') . '/bot' . $this->botToken . '/' . $method;

        try {
            $response = Http::timeout($this->timeout)
                // Forzar IPv4: muchos servidores tienen DNS que devuelve AAAA
                // (IPv6) para api.telegram.org pero la ruta IPv6 no es alcanzable.
                // Sin esto, cURL gasta el timeout entero intentando IPv6 antes
                // de caer a IPv4.
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            return ChannelDeliveryResult::transientFailure('connection error: ' . $e->getMessage());
        }

        $status = $response->status();
        $body   = $response->json();

        if ($response->successful() && ($body['ok'] ?? false) === true) {
            $messageId = $body['result']['message_id'] ?? null;
            return ChannelDeliveryResult::success(
                externalId: $messageId !== null ? (string) $messageId : null
            );
        }

        $description = $body['description'] ?? 'unexpected response';
        $sanitized   = 'telegram api error: ' . $description;

        // 429 = rate-limit reintentable. 5xx = error del lado de Telegram, reintentable.
        // 400/401/403/404 = error de configuración/permiso, no reintentable.
        if ($status === 429 || $status >= 500) {
            return ChannelDeliveryResult::transientFailure($sanitized);
        }

        return ChannelDeliveryResult::permanentFailure($sanitized);
    }
}
