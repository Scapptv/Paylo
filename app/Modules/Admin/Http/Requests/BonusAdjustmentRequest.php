<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Core\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Admin manual bonus düzəlişi (Audit 2026-06-04 CANON-4) — CREDIT-only.
 *
 * Route artıq `auth + role:admin` ilə qorunur; authorize() role middleware-ə güvənir.
 */
class BonusAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Yalnız AKTİV Customer — deaktiv/anonimləşdirilmiş hesaba kredit yox
            // (WEB-2 invariantı ilə eyni qayda).
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q
                    ->where('role', UserRole::Customer->value)
                    ->where('is_active', true)),
            ],
            'merchant_id' => ['required', 'integer', Rule::exists('merchants', 'id')],
            // CREDIT-only: müsbət integer qəpik. Bonus AZALTMAQ debit istiqamətidir —
            // reverse/refund yolu ilə (adjust() kontraktı credit-only, audit C-3).
            'amount_cents' => ['required', 'integer', 'min:1', 'max:99999999'],
            // Audit üçün məcburi səbəb — immutable ledger meta-da saxlanır.
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            // Köhnə float-əsaslı sahə açıq qadağandır.
            'amount' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.prohibited' => 'amount artıq qəbul edilmir. amount_cents (integer, qəpik) istifadə edin.',
            'amount_cents.min'  => 'Düzəliş məbləği müsbət olmalıdır. Bonus azaltmaq üçün reverse/refund istifadə edin.',
            'reason.required'   => 'Düzəliş səbəbi qeyd olunmalıdır (audit üçün).',
            'reason.min'        => 'Səbəb minimum 3 simvol olmalıdır.',
        ];
    }
}
