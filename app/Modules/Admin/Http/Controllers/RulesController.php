<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Models\LoyaltyRule;
use App\Core\Services\AuditLogger;
use App\Core\Services\LoyaltyRuleResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Loyalty qaydaları — admin redaktə (roadmap Phase 4.2).
 *
 * Earn faizləri (bp), tier multiplier-ləri, redemption və expiration ayarları.
 * Dəyişikliklər `loyalty_rules` cədvəlinə yazılır və `LoyaltyRuleResolver` ilə
 * config-ə tətbiq olunur (növbəti request-dən etibarən). `EarnCalculator`
 * dəyişməz qalır — kanonik integer hesablama qorunur.
 *
 * Validasiya reyestrin min/max hüdudlarına görədir (məs. earn rate 0..10000 bp,
 * redemption 0..100%) ki, EarnCalculator-un fail-fast şərtləri (mənfi rate)
 * heç vaxt pozulmasın.
 */
class RulesController extends Controller
{
    public function __construct(
        private readonly LoyaltyRuleResolver $resolver,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(): Response
    {
        return Inertia::render('Admin/Rules', [
            'rules' => $this->resolver->effective(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $key  = (string) $request->input('key');
        $rule = $this->resolver->ruleFor($key);

        if ($rule === null) {
            return back()->with('error', 'Naməlum qayda açarı.');
        }

        $validated = $request->validate([
            'value' => ['required', 'integer', "min:{$rule['min']}", "max:{$rule['max']}"],
        ]);
        $value = (int) $validated['value'];
        $old   = (int) config('loyalty.' . $key);

        LoyaltyRule::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => (int) $request->user()->id],
        );
        $this->resolver->flushCache();

        $this->audit->log('admin.loyalty_rule.updated', [
            'admin_id' => (int) $request->user()->id,
            'key'      => $key,
            'old'      => $old,
            'new'      => $value,
        ], $request);

        return back()->with('success', "Qayda yeniləndi: {$rule['label']} = {$value} {$rule['unit']}.");
    }

    public function reset(Request $request): RedirectResponse
    {
        $key  = (string) $request->input('key');
        $rule = $this->resolver->ruleFor($key);

        if ($rule === null) {
            return back()->with('error', 'Naməlum qayda açarı.');
        }

        LoyaltyRule::query()->where('key', $key)->delete();
        $this->resolver->flushCache();

        $this->audit->log('admin.loyalty_rule.reset', [
            'admin_id' => (int) $request->user()->id,
            'key'      => $key,
        ], $request);

        return back()->with('success', "Qayda fayl default-una qaytarıldı: {$rule['label']}.");
    }
}
