<?php

namespace App\Http\Requests;

use App\Enums\AssetType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetRequest extends FormRequest
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
            'symbol' => ['sometimes', 'string', 'max:10', Rule::unique('assets', 'symbol')->ignore($this->route('asset'))],
            'type' => ['sometimes', Rule::enum(AssetType::class)],
            'external_id' => ['nullable', 'string', 'max:255', Rule::unique('assets', 'external_id')->ignore($this->route('asset'))],
            'logo_url' => 'nullable|url',
        ];
    }
}
