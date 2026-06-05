<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin roadmap Phase 2.1 — Per-merchant Buckets read-view.
 *
 * Hər (user × merchant) bucket-in balansı + lifetime counter-ləri (earned/redeemed/
 * expired). READ-ONLY: heç bir mutasiya yoxdur — bonus dəyişiklikləri yalnız
 * Manual Adj. (credit) və ya reverse/refund (debit) yolu ilə, append-only ledger-dən.
 */
class BucketController extends Controller
{
    /** GET /admin/buckets — bucket siyahısı + filter (merchant, user axtarışı). */
    public function index(Request $request): Response
    {
        $buckets = Bucket::query()
            ->with(['user:id,name,email', 'merchant:id,code,name'])
            ->when($request->filled('merchant_id'), fn ($q) => $q->where('merchant_id', $request->integer('merchant_id')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $needle = '%' . $request->string('q') . '%';
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', $needle)->orWhere('email', 'like', $needle));
            })
            ->orderByDesc('balance')
            ->paginate(50)
            ->withQueryString();

        // Cəm bloklanmış balans (filtr tətbiq olunmuşsa onun daxilində) — admin üçün xülasə.
        $totalLocked = (int) Bucket::query()
            ->when($request->filled('merchant_id'), fn ($q) => $q->where('merchant_id', $request->integer('merchant_id')))
            ->sum('balance');

        return Inertia::render('Admin/Buckets', [
            'buckets'     => $buckets,
            'merchants'   => Merchant::query()->orderBy('name')->get(['id', 'code', 'name']),
            'filters'     => $request->only(['merchant_id', 'q']),
            'totalLocked' => $totalLocked,
        ]);
    }
}
