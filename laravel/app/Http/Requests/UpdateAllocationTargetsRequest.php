<?php

namespace App\Http\Requests;

use App\Enums\AssetType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAllocationTargetsRequest extends FormRequest
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
            // {} (or []) clears the targets.
            'targets' => ['present', 'array:'.implode(',', array_column(AssetType::cases(), 'value'))],
            'targets.*' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $targets = $this->input('targets');
                if ($validator->errors()->isNotEmpty() || ! is_array($targets) || $targets === []) {
                    return;
                }

                // Two decimals of slack: 33.33 + 33.33 + 33.34 is fine.
                $sum = array_sum(array_map('floatval', $targets));
                if (abs($sum - 100) > 0.01) {
                    $validator->errors()->add('targets', 'La somme des objectifs doit faire 100 % (actuellement '.round($sum, 2).' %).');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'targets.array' => 'Types d\'actif acceptés : '.implode(', ', array_column(AssetType::cases(), 'value')).'.',
        ];
    }
}
