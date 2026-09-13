<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCryptoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'symbol' => ['sometimes', 'string', 'max:10', Rule::unique('cryptos', 'symbol')->ignore($this->route('crypto'))],
            'coingecko_id' => ['sometimes', 'string', 'max:255', Rule::unique('cryptos', 'coingecko_id')->ignore($this->route('crypto'))],
            'logo_url' => 'nullable|url',
        ];
    }
}
