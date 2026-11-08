<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GDPR right to data portability (art. 20): everything this app stores
 * about the requesting user, in a structured, machine-readable format.
 * Served as a download (Content-Disposition) rather than displayed.
 *
 * Only data tied to this account: the shared catalog isn't theirs, so
 * assets/platforms appear by name inside the entries that reference them.
 * Sessions (IP, user agent) are left out on purpose — they only exist
 * while a session is open and are not account data.
 */
class DataExportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = [
            'exported_at' => now()->toIso8601String(),
            'account' => $user->only([
                'id', 'name', 'email', 'email_verified_at', 'currency', 'avatar_url',
                'onboarding_completed_at', 'created_at', 'updated_at',
            ]),
            'allocation_targets' => $user->allocation_targets,
            'notification_settings' => $user->only([
                'email_notifications_enabled', 'discord_webhook_url',
                'daily_digest_enabled', 'weekly_digest_enabled',
            ]),
            'portfolio_entries' => $user->portfolioEntries()->with(['asset', 'platform', 'transactions'])->get()
                ->map(fn ($entry) => [
                    'asset' => $entry->asset->name,
                    'asset_symbol' => $entry->asset->symbol,
                    'platform' => $entry->platform->name,
                    'quantity' => $entry->quantity,
                    'transactions' => $entry->transactions->sortBy('executed_at')->values()
                        ->map(fn ($transaction) => [
                            'type' => $transaction->type,
                            'quantity' => $transaction->quantity,
                            'unit_price' => $transaction->unit_price,
                            'fee' => $transaction->fee,
                            'currency' => $transaction->currency,
                            'executed_at' => $transaction->executed_at,
                        ]),
                    'created_at' => $entry->created_at,
                    'updated_at' => $entry->updated_at,
                ]),
            'price_alerts' => $user->priceAlerts()->with('asset')->get()
                ->map(fn ($alert) => [
                    'type' => $alert->type,
                    'asset' => $alert->asset?->name,
                    'threshold_usd' => $alert->threshold,
                    'percent_threshold' => $alert->percent_threshold,
                    'window' => $alert->window,
                    'is_active' => $alert->is_active,
                    'last_triggered_at' => $alert->last_triggered_at,
                    'created_at' => $alert->created_at,
                ]),
            'audit_log' => AuditLog::where('user_id', $user->id)->orderBy('id')
                ->get(['auditable_type', 'auditable_id', 'action', 'source', 'changes', 'created_at']),
        ];

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="export-donnees-'.now()->format('Y-m-d').'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
