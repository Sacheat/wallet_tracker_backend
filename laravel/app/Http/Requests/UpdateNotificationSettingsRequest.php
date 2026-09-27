<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationSettingsRequest extends FormRequest
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
            'email_notifications_enabled' => 'sometimes|boolean',
            'discord_webhook_url' => 'sometimes|nullable|url|max:255|starts_with:https://discord.com/api/webhooks/,https://discordapp.com/api/webhooks/',
            'daily_digest_enabled' => 'sometimes|boolean',
            'weekly_digest_enabled' => 'sometimes|boolean',
        ];
    }
}
