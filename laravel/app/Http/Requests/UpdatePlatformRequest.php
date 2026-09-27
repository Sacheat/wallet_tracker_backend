<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlatformRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('platforms', 'name')->ignore($this->route('platform'))],
            // Logos go through POST /{resource}/{id}/logo (LogoStorage);
            // 'prohibited' makes a client still sending a URL fail loudly
            // instead of having it silently dropped (null is still accepted).
            'logo_url' => 'prohibited',
        ];
    }
}
