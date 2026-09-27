<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Everyone gets their own history, as before. An admin can also ask for
     * ?scope=all — who changed what in the shared catalog is exactly what
     * they need to moderate it. Only id/name of the author are attached:
     * enough to identify them, no email or settings.
     */
    public function index(Request $request)
    {
        $scope = $request->validate(['scope' => 'sometimes|in:mine,all'])['scope'] ?? 'mine';

        if ($scope === 'all') {
            abort_unless($request->user()->is_admin, 403, "Seul un administrateur peut consulter l'historique de tous les utilisateurs.");

            return AuditLog::with('user:id,name')->latest()->paginate(25)->withQueryString();
        }

        return AuditLog::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(25);
    }
}
