<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use App\Enums\TransactionType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePortfolioTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every field optional, none nullable once sent: a price can be filled
     * in on a backfilled opening buy, never erased again.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $price = StorePortfolioTransactionRequest::priceRules();

        return [
            'type' => ['sometimes', 'required', Rule::enum(TransactionType::class)],
            'quantity' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:999999999999'],
            'unit_price' => ['sometimes', 'required', ...$price['unit_price']],
            'fee' => ['sometimes', 'required', 'numeric', 'gte:0', 'max:999999999999'],
            'currency' => ['sometimes', 'required', Rule::enum(Currency::class)],
            'executed_at' => ['sometimes', 'required', ...$price['executed_at']],
        ];
    }
}
