<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Cloudinary\Cloudinary; // Requiere la instalación del SDK de Cloudinary (ej. con un Facade/Service)

class Attachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'cloudinary_public_id',
        'file_url',
        'original_name',
        'type',
        'size',
    ];

    /**
     * El adjunto pertenece a un mensaje.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * ACCESSOR: Genera una URL optimizada (ej. miniatura) usando el ID público de Cloudinary.
     * NOTA: Requiere que Cloudinary esté configurado en tu servicio.
     */
    public function getThumbnailUrlAttribute(): string
    {
        // Esto es un ejemplo conceptual; la implementación real depende del SDK que uses.
        if (! $this->cloudinary_public_id) {
            return '';
        }

        // Simulación: Genera una URL de Cloudinary para una imagen redimensionada (150x150)
        // Ejemplo de URL: https://res.cloudinary.com/cloud_name/image/upload/w_150,h_150,c_fill/public_id
        
        $cloudinary = app(Cloudinary::class); // Asumiendo que has ligado Cloudinary al contenedor de servicios
        
        return $cloudinary->image($this->cloudinary_public_id)
            ->resize('fill', 150, 150)
            ->toUrl();
    }
}