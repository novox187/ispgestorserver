<?php

namespace App\Notifications\Core\Enums;

/**
 * Sugerencia de formato para el cuerpo de un NotificationMessage.
 * Cada canal decide si la respeta o la normaliza a su sintaxis nativa.
 */
enum FormatHint: string
{
    case PLAIN    = 'plain';
    case MARKDOWN = 'markdown';
    case HTML     = 'html';
}
