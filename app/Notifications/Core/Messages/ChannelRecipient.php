<?php

namespace App\Notifications\Core\Messages;

/**
 * Destino concreto de una notificación en un canal específico.
 */
final class ChannelRecipient
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public readonly string $channelKey,
        public readonly string $address,
        public readonly array  $metadata = [],
    ) {
        if ($this->channelKey === '') {
            throw new \InvalidArgumentException('ChannelRecipient channelKey cannot be empty.');
        }
        if ($this->address === '') {
            throw new \InvalidArgumentException('ChannelRecipient address cannot be empty.');
        }
    }

    public function toArray(): array
    {
        return [
            'channel'  => $this->channelKey,
            'address'  => $this->address,
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            channelKey: $data['channel'],
            address:    $data['address'],
            metadata:   $data['metadata'] ?? [],
        );
    }
}
