<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Models\AuditLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Audit jurnalı — admin görünüşü (roadmap Phase 3.1).
 *
 * `AuditLogger` dual-write ilə hər hadisəni `audit_logs` cədvəlinə yazır; bu
 * controller həmin cədvəli read-only siyahılayır: event (exact) + tarix aralığı
 * filtri, pagination, aktor (request olan hadisələrdə). Append-only — UI-dan
 * heç bir dəyişiklik/silmə yoxdur.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $logs = AuditLog::query()
            ->with('actor:id,name,email')
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->string('event')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('to')))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/AuditLogs', [
            'logs'    => $logs,
            'filters' => $request->only(['event', 'from', 'to']),
            'events'  => AuditLog::query()->select('event')->distinct()->orderBy('event')->pluck('event'),
        ]);
    }
}
