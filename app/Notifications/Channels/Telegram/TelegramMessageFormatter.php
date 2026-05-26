<?php

namespace App\Notifications\Channels\Telegram;

use App\Notifications\Core\Contracts\NotificationFormatter;
use App\Notifications\Core\Enums\FormatHint;
use App\Notifications\Core\Messages\NotificationMessage;

/**
 * Formatea NotificationMessage para envío vía Telegram.
 *
 * Telegram soporta tres parse_modes: MarkdownV2 (estricto), HTML y sin formato.
 * Este formatter genera salida MarkdownV2 con escape correcto de los caracteres
 * reservados, encabezado con icono+severidad+título y cuerpo con el body original.
 *
 * Truncamiento: la API tiene un límite de 4096 caracteres por mensaje. Si se
 * excede, se trunca conservando los últimos caracteres con elipsis al final.
 */
class TelegramMessageFormatter implements NotificationFormatter
{
    private const MAX_LENGTH = 4096;

    /**
     * Caracteres reservados en MarkdownV2 que deben escaparse con `\`.
     * Lista oficial: https://core.telegram.org/bots/api#markdownv2-style
     */
    private const RESERVED = [
        '_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!',
    ];

    public function format(NotificationMessage $message): string
    {
        $icon  = $message->severity->icon();
        $title = $this->escape($message->title);
        $body  = $message->formatHint === FormatHint::PLAIN
            ? $this->escape($message->body)
            : $this->normalizeMarkdown($message->body);

        $header = "{$icon} *{$title}*";

        $rendered = $header . "\n\n" . $body;

        if (mb_strlen($rendered) > self::MAX_LENGTH) {
            // El carácter `…` (ellipsis) no está en la lista reservada de MarkdownV2,
            // por lo que no requiere escape y consume solo un code-point.
            $rendered = mb_substr($rendered, 0, self::MAX_LENGTH - 1) . '…';
        }

        return $rendered;
    }

    /**
     * Escapa caracteres reservados de MarkdownV2 en una cadena plana.
     */
    public function escape(string $text): string
    {
        $escaped = '';
        $len     = mb_strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($text, $i, 1);
            $escaped .= in_array($char, self::RESERVED, true) ? '\\' . $char : $char;
        }
        return $escaped;
    }

    /**
     * Acepta texto que ya viene en estilo Markdown (con sintaxis legítima de
     * negrita/cursiva/código) y escapa solo los caracteres "fuera de sintaxis".
     *
     * Estrategia conservadora: el productor del mensaje es responsable de generar
     * MarkdownV2 válido. Aquí solo escapamos los `.` (puntos) en líneas no técnicas,
     * que son la fuente principal de error y casi nunca son intencionales como sintaxis.
     *
     * NOTA: si en el futuro se quiere soportar Markdown clásico (sin escape), basta
     * con cambiar parse_mode en config a 'Markdown' (legacy) o 'HTML' y reemplazar
     * este método por uno que convierta a HTML.
     */
    private function normalizeMarkdown(string $text): string
    {
        $lines = preg_split("/\r?\n/", $text);
        $out   = [];
        foreach ($lines as $line) {
            // Líneas con bloque de código triple: pasar tal cual.
            if (str_starts_with(ltrim($line), '```')) {
                $out[] = $line;
                continue;
            }

            // Escapar todos los reservados de MarkdownV2 que aparecen como texto.
            // Solo preservamos `_`, `*`, `[`, `]` y `` ` `` que los productores usan
            // intencionalmente como sintaxis (negrita, código inline, links futuros).
            // El resto (.!-+=>{}()~#|) se escapa siempre — Telegram rechaza el mensaje
            // si encuentra alguno sin escapar fuera de su contexto sintáctico.
            $out[] = preg_replace_callback(
                '/[\.!\-+=>\{\}()~#|]/u',
                fn($m) => '\\' . $m[0],
                $line
            );
        }
        return implode("\n", $out);
    }
}
