<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Solo el cliente autenticado puede proceder
        return Auth::check(); 
    }

    public function rules(): array
    {
        return [
            'message' => 'nullable|string|max:10000',
            
            // Adjuntos son obligatorios si no hay mensaje
            'attachments' => [
                'array',
                Rule::requiredIf(!$this->input('message')),
            ],
            'attachments.*' => 'file|max:5120|mimes:jpeg,png,gif,pdf,doc,docx,xlsx,zip', 
        ];
    }
    
    /**
     * Inyecta el client_id usando la autenticación.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'client_id' => Auth::id(), // <-- ID obtenido del token (Auth::user()->id)
            'employee_id' => null,     // Se asegura que es un cliente
        ]);
    }
}