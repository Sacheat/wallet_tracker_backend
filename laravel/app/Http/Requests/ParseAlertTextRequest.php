<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ParseAlertTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'text' => 'required|string|min:5|max:500',
        ];
    }
}
