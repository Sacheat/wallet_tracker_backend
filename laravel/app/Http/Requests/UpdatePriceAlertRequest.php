<?php

namespace App\Http\Requests;

use App\Enums\AlertWindow;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * type and asset_id are immutable after creation — to change what an alert
 * watches, delete it and create a new one. Only the trigger parameters and
 * active state can be adjusted in place.
 */
class UpdatePriceAlertRequest extends FormRequest
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
            // decimal(20,8) column, and the value is converted to USD before
            // being stored (CHF→USD can grow it): 1e11 keeps a margin under
            // the 12 integer digits for any supported currency.
            'threshold' => 'sometimes|nullable|numeric|gt:0|max:100000000000',
            'percent_threshold' => 'sometimes|nullable|numeric|gt:0|max:1000',
            'window' => ['sometimes', 'nullable', Rule::enum(AlertWindow::class)],
            'is_active' => 'sometimes|boolean',
        ];
    }
}
