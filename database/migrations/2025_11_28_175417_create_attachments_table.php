<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->onDelete('cascade');
            $table->string('cloudinary_public_id')->unique();
            $table->string('file_url')->comment('URL del archivo en cloudinary'); 
            $table->string('original_name')->comment('Nombre original del archivo');
            $table->string('type')->nullable()->comment('Tipo de archivo'); 
            $table->unsignedBigInteger('size')->nullable()->comment('Tamaño del archivo en bytes');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
