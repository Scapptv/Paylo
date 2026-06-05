<?php

declare(strict_types=1);

namespace App\Modules\Merchant\Http\Controllers;

use App\Core\Enums\UserRole;
use App\Core\Models\User;
use App\Core\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Modules\Merchant\Http\Requests\StaffStoreRequest;
use App\Modules\Merchant\Http\Requests\StaffUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Merchant owner-in staff (cashier, merchant_staff) idarəetməsi.
 *
 * Bütün route-lar `EnsureMerchantScope` middleware-i ilə merchant_id-yə bağlanır.
 * Owner yalnız `MerchantOwner` rolundadır — route group `role:merchant_owner`
 * ilə qorunur (bax: `Merchant/Routes/web.php`).
 *
 * Staff yaradılan və silinən zaman audit log yazılır.
 */
class StaffController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): Response
    {
        $merchantId = (int) $request->attributes->get('merchant_id');

        $staff = User::query()
            ->where('merchant_id', $merchantId)
            ->whereIn('role', [UserRole::Cashier, UserRole::MerchantStaff, UserRole::MerchantOwner])
            ->orderBy('role')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'role', 'is_active', 'created_at']);

        return Inertia::render('Merchant/Staff', [
            'staff' => $staff,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Merchant/StaffForm', [
            'mode' => 'create',
        ]);
    }

    public function store(StaffStoreRequest $request): RedirectResponse
    {
        $merchantId = (int) $request->attributes->get('merchant_id');
        $data       = $request->validated();

        $staff = User::create([
            'name'        => $data['name'],
            'email'       => $data['email'],
            'phone'       => $data['phone'] ?? null,
            'password'    => $data['password'],
            'role'        => UserRole::from($data['role']),
            'merchant_id' => $merchantId,
            'is_active'   => (bool) ($data['is_active'] ?? true),
        ]);

        $this->audit->log('merchant.staff.created', [
            'owner_id'    => (int) $request->user()->id,
            'staff_id'    => $staff->id,
            'merchant_id' => $merchantId,
            'role'        => $staff->role->value,
        ], $request);

        return redirect()
            ->route('merchant.staff')
            ->with('success', "İşçi {$staff->name} əlavə olundu.");
    }

    public function edit(Request $request, User $staff): Response
    {
        $this->ensureBelongsToScope($request, $staff);

        return Inertia::render('Merchant/StaffForm', [
            'mode'  => 'edit',
            'staff' => $staff,
        ]);
    }

    public function update(StaffUpdateRequest $request, User $staff): RedirectResponse
    {
        $this->ensureBelongsToScope($request, $staff);

        $data    = $request->validated();
        $staff->fill([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'phone'     => $data['phone'] ?? null,
            'role'      => UserRole::from($data['role']),
            'is_active' => (bool) $data['is_active'],
        ]);
        $changed = $staff->getDirty();
        $staff->save();

        $this->audit->log('merchant.staff.updated', [
            'owner_id'    => (int) $request->user()->id,
            'staff_id'    => $staff->id,
            'merchant_id' => (int) $request->attributes->get('merchant_id'),
            'changed'     => array_keys($changed),
        ], $request);

        return redirect()
            ->route('merchant.staff')
            ->with('success', 'İşçi yeniləndi.');
    }

    public function destroy(Request $request, User $staff): RedirectResponse
    {
        $this->ensureBelongsToScope($request, $staff);

        if ($staff->role === UserRole::MerchantOwner) {
            abort(403, 'Merchant owner staff endpoint-i ilə silinə bilməz.');
        }

        // GDPR uyğun anonimləşdirmə — hard-delete yox (audit izi qorunur, login bloklanır).
        $originalEmail = $staff->email;
        $staff->update([
            'name'      => 'Silinmiş işçi #' . $staff->id,
            'email'     => "deleted+{$staff->id}@paylo.deleted",
            'phone'     => null,
            'is_active' => false,
        ]);
        $staff->tokens()?->delete();

        $this->audit->log('merchant.staff.removed', [
            'owner_id'        => (int) $request->user()->id,
            'staff_id'        => $staff->id,
            'merchant_id'     => (int) $request->attributes->get('merchant_id'),
            'original_email'  => $originalEmail,
        ], $request);

        return redirect()
            ->route('merchant.staff')
            ->with('success', 'İşçi silindi.');
    }

    /**
     * Staff bu merchant-a aiddirsə icazə var; əks halda 404 (enumeration prevent).
     */
    private function ensureBelongsToScope(Request $request, User $staff): void
    {
        $merchantId = (int) $request->attributes->get('merchant_id');
        abort_unless($staff->merchant_id === $merchantId, 404);
    }
}
