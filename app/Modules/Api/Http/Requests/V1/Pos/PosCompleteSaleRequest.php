<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Requests\V1\Pos;

use App\Core\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Web `CompleteSaleRequest` ilə eyni domain qaydaları, lakin merchant scope
 * Sanctum token sahibindən gəlir — POSNET request body-də başqa merchant göndərə
 * bilməz, scope override edilmir.
 */
class PosCompleteSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $merchantId = (int) ($this->user()?->merchant_id ?? 0);

        return [
            'customer_id'       => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', UserRole::Customer->value)),
            ],
            'sale_amount_cents' => ['required', 'integer', 'min:1', 'max:99999999'],
            // Domain-level idempotency: (merchant_id, receipt_no) unique.
            'receipt_no'        => ['required', 'string', 'regex:/^[A-Za-z0-9_\-]{1,64}$/'],
            'branch_id'         => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('merchant_id', $merchantId)),
            ],
            'use_bonus'         => ['boolean'],
            'redeem_cents'      => ['required_if:use_bonus,true', 'prohibited_unless:use_bonus,true', 'nullable', 'integer', 'min:0', 'max:99999999'],

            'sale_amount'       => ['prohibited'],
            'redeem_azn'        => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'sale_amount.prohibited'         => 'sale_amount artıq qəbul edilmir. sale_amount_cents (integer, qəpik) istifadə edin.',
            'redeem_azn.prohibited'          => 'redeem_azn artıq qəbul edilmir. redeem_cents (integer, qəpik) istifadə edin.',
            'redeem_cents.prohibited_unless' => 'redeem_cents yalnız use_bonus=true halında göndərilə bilər.',
            'receipt_no.regex'               => 'receipt_no yalnız hərf, rəqəm, "-" və "_" simvollarından ibarət ola bilər (1–64 simvol).',
        ];
    }
}
