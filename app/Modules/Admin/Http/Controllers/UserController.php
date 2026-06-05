<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Enums\UserRole;
use App\Core\Models\User;
use App\Core\Services\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin — bütün istifadəçilərin idarəetmə görünüşü (roadmap Phase 2.2).
 *
 * Əhatə:
 *   - index(): siyahı (ad/email, rol, merchant, aktivlik, qeydiyyat tarixi) +
 *     filter (rol, aktivlik, ad/email axtarışı), pagination.
 *   - toggleActive(): `is_active` çevirmə (deaktivləşdirmə/aktivləşdirmə).
 *
 * Deaktivləşdirmə = `EnsureUserIsActive` middleware ilə dərhal blok: növbəti
 * sorğuda user 403 alır + bütün Sanctum token-ləri revoke olunur (mobil/API
 * dərhal düşür). Bu HARD-DELETE və ya anonimləşdirmə DEYİL — audit izi qorunur,
 * yalnız giriş bağlanır. (Anonimləşdirmə ayrıca GDPR axınıdır.)
 *
 * Privilege qaydası: admin öz hesabının statusunu dəyişə bilməz (özünü
 * kilidləməsin). Endpoint-ə yalnız aktiv admin çata bildiyi üçün sistemdə
 * həmişə ən azı bir aktiv admin (icraçının özü) qalır.
 */
class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(Request $request): Response
    {
        $users = User::query()
            ->select(['id', 'name', 'email', 'role', 'is_active', 'merchant_id', 'created_at'])
            ->with('merchant:id,code,name')
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->input('active') === '1'))
            ->when($request->filled('q'), function ($q) use ($request) {
                $needle = '%' . $request->string('q') . '%';
                $q->where(fn ($qq) => $qq->where('name', 'like', $needle)->orWhere('email', 'like', $needle));
            })
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Users', [
            'users'   => $users,
            'filters' => $request->only(['role', 'active', 'q']),
            'roles'   => array_map(
                fn (UserRole $r) => ['value' => $r->value, 'label' => $r->label()],
                UserRole::cases()
            ),
            'authId'  => (int) $request->user()->id,
        ]);
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        // Privilege qaydası: admin öz hesabının statusunu dəyişə bilməz.
        if ($user->id === $admin->id) {
            return back()->with('error', 'Öz hesabınızın statusunu dəyişə bilməzsiniz.');
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        // Deaktiv edildikdə bütün aktiv token-ləri revoke et — mobil/API sessiyaları
        // dərhal düşsün (EnsureUserIsActive onsuz da növbəti sorğuda bloklayır,
        // lakin token-i indi silmək ölü token-lərin təmizlənməsini sürətləndirir).
        if (! $user->is_active) {
            $user->tokens()?->delete();
        }

        $this->audit->log($user->is_active ? 'admin.user.activated' : 'admin.user.deactivated', [
            'admin_id' => (int) $admin->id,
            'user_id'  => $user->id,
            'role'     => $user->role->value,
        ], $request);

        $verb = $user->is_active ? 'aktivləşdirildi' : 'deaktivləşdirildi';

        return back()->with('success', "İstifadəçi {$user->display_name} {$verb}.");
    }
}
