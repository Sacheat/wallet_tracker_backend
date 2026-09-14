<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        return AuditLog::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(25);
    }
}
