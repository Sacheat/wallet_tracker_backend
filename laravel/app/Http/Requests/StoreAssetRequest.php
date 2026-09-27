<?php

namespace App\Http\Requests;

use App\Enums\AssetType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'symbol' => 'required|string|max:10|unique:assets,symbol',
            'type' => ['required', Rule::enum(AssetType::class)],
            'external_id' => 'nullable|string|max:255|unique:assets,external_id',
            // Logos go through POST /{resource}/{id}/logo (LogoStorage);
            // 'prohibited' makes a client still sending a URL fail loudly
            // instead of having it silently dropped (null is still accepted).
            'logo_url' => 'prohibited',
        ];
    }
}
