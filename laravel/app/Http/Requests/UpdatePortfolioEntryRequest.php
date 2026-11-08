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
            // The quantity is now the net of the position's transactions:
            // editing it directly would silently desync the journal.
            'quantity' => 'prohibited',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.prohibited' => 'La quantité se calcule à partir des transactions : ajoutez un achat ou une vente sur cette position.',
        ];
    }
}
