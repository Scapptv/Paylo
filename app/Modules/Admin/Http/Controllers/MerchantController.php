<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Models\Merchant;
use App\Core\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\MerchantStoreRequest;
use App\Modules\Admin\Http\Requests\MerchantUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MerchantController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

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

    public function create(): Response
    {
        return Inertia::render('Admin/MerchantForm', [
            'mode' => 'create',
        ]);
    }

    public function store(MerchantStoreRequest $request): RedirectResponse
    {
        $merchant = Merchant::create($request->validated());

        $this->audit->log('admin.merchant.created', [
            'admin_id'    => (int) $request->user()->id,
            'merchant_id' => $merchant->id,
            'code'        => $merchant->code,
        ], $request);

        return redirect()
            ->route('admin.merchants.show', $merchant)
            ->with('success', "Merchant {$merchant->code} yaradıldı.");
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

    public function edit(Merchant $merchant): Response
    {
        return Inertia::render('Admin/MerchantForm', [
            'mode'     => 'edit',
            'merchant' => $merchant,
        ]);
    }

    public function update(MerchantUpdateRequest $request, Merchant $merchant): RedirectResponse
    {
        $merchant->fill($request->validated());
        $changed = $merchant->getDirty();
        $merchant->save();

        $this->audit->log('admin.merchant.updated', [
            'admin_id'    => (int) $request->user()->id,
            'merchant_id' => $merchant->id,
            'changed'     => array_keys($changed),
        ], $request);

        return redirect()
            ->route('admin.merchants.show', $merchant)
            ->with('success', 'Merchant yeniləndi.');
    }
}
