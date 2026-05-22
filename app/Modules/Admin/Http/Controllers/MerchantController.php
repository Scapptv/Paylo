<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Models\Merchant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MerchantController extends Controller
{
    public function index(Request $request): Response
    {
        $merchants = Merchant::query()
            ->withCount(['branches', 'users', 'buckets'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->string('category')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $needle = '%' . $request->string('q') . '%';
                $q->where(function ($qq) use ($needle) {
                    $qq->where('name', 'like', $needle)
                        ->orWhere('legal_name', 'like', $needle)
                        ->orWhere('tin', 'like', $needle)
                        ->orWhere('code', 'like', $needle);
                });
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Merchants', [
            'merchants' => $merchants,
            'filters'   => $request->only(['status', 'category', 'q']),
        ]);
    }

    public function show(Merchant $merchant): Response
    {
        $merchant->load(['branches', 'users']);

        return Inertia::render('Admin/MerchantDetail', [
            'merchant'    => $merchant,
            'bucketTotal' => (int) $merchant->buckets()->sum('balance'),
            'ledgerCount' => $merchant->ledgerEntries()->count(),
        ]);
    }
}
