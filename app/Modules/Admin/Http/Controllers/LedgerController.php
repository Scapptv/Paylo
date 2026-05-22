<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Enums\LedgerEntryType;
use App\Core\Models\LedgerEntry;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    public function index(Request $request): Response
    {
        $entries = LedgerEntry::query()
            ->with(['user:id,name,email', 'merchant:id,code,name', 'cashier:id,name'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('merchant_id'), fn ($q) => $q->where('merchant_id', $request->integer('merchant_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $needle = '%' . $request->string('q') . '%';
                $q->where(function ($qq) use ($needle) {
                    $qq->where('uid', 'like', $needle)
                        ->orWhere('ref', 'like', $needle);
                });
            })
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Ledger', [
            'entries' => $entries,
            'filters' => $request->only(['type', 'merchant_id', 'user_id', 'q']),
            'types'   => array_map(fn ($t) => ['value' => $t->value, 'label' => $t->label()], LedgerEntryType::cases()),
        ]);
    }

    public function show(LedgerEntry $entry): Response
    {
        $entry->load(['user', 'merchant', 'branch', 'cashier', 'reversesEntry']);

        return Inertia::render('Admin/LedgerEntry', [
            'entry' => $entry,
        ]);
    }
}
