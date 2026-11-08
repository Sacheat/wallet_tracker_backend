<?php

namespace App\Http\Requests;

use App\Enums\Currency;
use App\Enums\TransactionType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePortfolioTransactionRequest extends FormRequest
{
    /**
     * Frankfurter's EUR history starts here: no exchange rate, no exact
     * profit in another currency, so earlier trade dates are refused.
     */
    public const EARLIEST_TRADE_DATE = '1999-01-04';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shared with UpdatePortfolioTransactionRequest and the optional
     * opening-price fields of StorePortfolioEntryRequest.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function priceRules(): array
    {
        return [
            // decimal(24,10): 14 integer digits. 0 is allowed (airdrop, gift).
            'unit_price' => ['numeric', 'gte:0', 'max:99999999999999'],
            'fee' => ['nullable', 'numeric', 'gte:0', 'max:999999999999'],
            'currency' => ['nullable', Rule::enum(Currency::class)],
            'executed_at' => ['date', 'after_or_equal:'.self::EARLIEST_TRADE_DATE, 'before_or_equal:now'],
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $price = self::priceRules();

        return [
            'type' => ['required', Rule::enum(TransactionType::class)],
            // Column is decimal(20,8): 12 integer digits.
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'unit_price' => ['required', ...$price['unit_price']],
            'fee' => $price['fee'],
            'currency' => $price['currency'],
            'executed_at' => ['required', ...$price['executed_at']],
        ];
    }
}
