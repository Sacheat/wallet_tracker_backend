<?php

namespace App\Http\Requests;

use App\Enums\AlertType;
use App\Enums\AlertWindow;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePriceAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    private function typeIn(array $types): \Closure
    {
        return fn () => in_array($this->input('type'), array_map(fn ($t) => $t->value, $types), true);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $assetTypes = [AlertType::PriceAbove, AlertType::PriceBelow, AlertType::PercentChange];
        $thresholdTypes = [AlertType::PriceAbove, AlertType::PriceBelow, AlertType::PortfolioTotalAbove, AlertType::PortfolioTotalBelow];

        return [
            'type' => ['required', Rule::enum(AlertType::class)],
            'asset_id' => [
                Rule::requiredIf($this->typeIn($assetTypes)),
                Rule::prohibitedIf($this->typeIn([AlertType::PortfolioTotalAbove, AlertType::PortfolioTotalBelow, AlertType::AllocationDrift])),
                'nullable', 'integer', 'exists:assets,id',
            ],
            // Max: decimal(20,8) column, and the value is converted to USD
            // before being stored (CHF→USD can grow it) — 1e11 keeps a
            // margin under the 12 integer digits for any supported currency.
            'threshold' => [
                Rule::requiredIf($this->typeIn($thresholdTypes)),
                Rule::prohibitedIf($this->typeIn([AlertType::PercentChange, AlertType::AllocationDrift])),
                'nullable', 'numeric', 'gt:0', 'max:100000000000',
            ],
            // allocation_drift: points of gap between a type's share and its
            // target, so at most 100.
            'percent_threshold' => [
                Rule::requiredIf($this->typeIn([AlertType::PercentChange, AlertType::AllocationDrift])),
                Rule::prohibitedIf($this->typeIn($thresholdTypes)),
                'nullable', 'numeric', 'gt:0', $this->typeIn([AlertType::AllocationDrift])() ? 'max:100' : 'max:1000',
            ],
            'window' => [
                Rule::requiredIf($this->typeIn([AlertType::PercentChange])),
                Rule::prohibitedIf($this->typeIn([...$thresholdTypes, AlertType::AllocationDrift])),
                'nullable', Rule::enum(AlertWindow::class),
            ],
        ];
    }

    /**
     * A drift alert measures the gap to the allocation targets: without
     * targets it could never fire, so it is refused up front.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->typeIn([AlertType::AllocationDrift])() && empty($this->user()?->allocation_targets)) {
                    $validator->errors()->add('type', 'Définissez d\'abord vos objectifs de répartition (PUT /api/allocation-targets).');
                }
            },
        ];
    }
}
