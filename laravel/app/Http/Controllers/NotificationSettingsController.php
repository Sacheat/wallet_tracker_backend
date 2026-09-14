<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateNotificationSettingsRequest;
use Illuminate\Http\Request;

class NotificationSettingsController extends Controller
{
    public function show(Request $request)
    {
        return $request->user()->only([
            'email_notifications_enabled',
            'discord_webhook_url',
            'daily_digest_enabled',
            'weekly_digest_enabled',
        ]);
    }

    public function update(UpdateNotificationSettingsRequest $request)
    {
        $user = $request->user();
        $user->update($request->validated());

        return $user->only([
            'email_notifications_enabled',
            'discord_webhook_url',
            'daily_digest_enabled',
            'weekly_digest_enabled',
        ]);
    }
}
