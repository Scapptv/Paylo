<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Models\Merchant;
use App\Core\Services\SettlementReconciler;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settlement reconciliation — admin HTTP wrapper (roadmap Phase 2.4).
 *
 * Read-only hesabat: per-bucket counter-lər vs `ledger_entries` toplamı uyğunluğu.
 * Hesablama `SettlementReconciler` servisindədir — CLI cron (`loyalty:settlement-reconcile`)
 * ilə EYNİ mənbə (drift dublikatı yox).
 *
 *  - index(): seçilmiş scope üçün reconcile-ı işlədir və nəticəni göstərir.
 *             SIDE-EFFECT YOX — audit yazılmır, sadəcə baxış.
 *  - run():   "İndi işlət" — reconcile + audit log (rəsmi, qeydə alınmış icra).
 *
 * Yalnız `today|yesterday|all` scope-u (UI dropdown) qəbul olunur ki, etibarsız
 * tarix-format throw yolu UI-dan baş verə bilməsin.
 */
class SettlementController extends Controller
{
    private const SCOPES = ['today', 'yesterday', 'all'];

    public function index(Request $request, SettlementReconciler $reconciler): Response
    {
        $for        = $this->resolveFor($request);
        $merchantId = $request->filled('merchant_id') ? (int) $request->input('merchant_id') : null;

        $report = $reconciler->run($for, $merchantId);

        return Inertia::render('Admin/Settlements', [
            'report'    => $report,
            'filters'   => ['for' => $for, 'merchant_id' => $merchantId],
            'scopes'    => self::SCOPES,
            'merchants' => Merchant::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function run(Request $request, SettlementReconciler $reconciler): RedirectResponse
    {
        $for        = $this->resolveFor($request);
        $merchantId = $request->filled('merchant_id') ? (int) $request->input('merchant_id') : null;

        $report = $reconciler->run($for, $merchantId);
        $reconciler->logCompletion($report, $request); // rəsmi icra — audit izi (admin aktor) qalsın

        $count = count($report['mismatches']);
        $msg   = $count === 0
            ? "Reconcile tamamlandı (scope: {$report['scope']}): {$report['checked']} bucket yoxlanıldı, uyğunsuzluq yoxdur."
            : "Reconcile tamamlandı (scope: {$report['scope']}): {$report['checked']} bucket-dən {$count}-də uyğunsuzluq tapıldı.";

        return redirect()
            ->route('admin.settlements', array_filter(['for' => $for, 'merchant_id' => $merchantId]))
            ->with($count === 0 ? 'success' : 'error', $msg);
    }

    /** Yalnız icazəli scope dəyərləri (default: today — yüngül səhifə yükü). */
    private function resolveFor(Request $request): string
    {
        $for = (string) $request->input('for', 'today');

        return in_array($for, self::SCOPES, true) ? $for : 'today';
    }
}
