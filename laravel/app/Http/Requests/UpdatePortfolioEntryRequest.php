<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePortfolioEntryRequest extends FormRequest
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
            // Column is decimal(20,8): 12 integer digits. Past that, MySQL
            // rejects the write and the client got a bare 500.
            'quantity' => 'required|numeric|gt:0|max:999999999999',
        ];
    }
}
