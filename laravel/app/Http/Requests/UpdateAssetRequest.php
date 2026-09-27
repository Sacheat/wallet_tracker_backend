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
            // Provider identifiers only use these characters (CoinGecko ids:
            // bitcoin, usd-coin; Yahoo tickers: GC=F, ^GSPC, BRK-B, 0700.HK).
            // It ends up in outbound request paths, so nothing else gets in.
            'external_id' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._=^-]+$/', Rule::unique('assets', 'external_id')->ignore($this->route('asset'))],
            // Logos go through POST /{resource}/{id}/logo (LogoStorage);
            // 'prohibited' makes a client still sending a URL fail loudly
            // instead of having it silently dropped (null is still accepted).
            'logo_url' => 'prohibited',
        ];
    }
}
