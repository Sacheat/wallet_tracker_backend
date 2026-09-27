<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePlatformRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:platforms,name',
            // Logos go through POST /{resource}/{id}/logo (LogoStorage);
            // 'prohibited' makes a client still sending a URL fail loudly
            // instead of having it silently dropped (null is still accepted).
            'logo_url' => 'prohibited',
        ];
    }
}
