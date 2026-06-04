<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Requests\V1\Pos;

use App\Core\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Web `PreviewSaleRequest` ilə eyni biznes qaydaları, lakin merchant scope
 * istifadəçinin token-indən gəlir (web tərəfdə middleware atributu).
 */
class PosPreviewSaleRequest extends FormRequest
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
            'use_bonus'         => ['boolean'],
            'redeem_cents'      => ['prohibited_unless:use_bonus,true', 'nullable', 'integer', 'min:0', 'max:99999999'],
            'branch_id'         => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('merchant_id', $merchantId)),
            ],

            // Köhnə float-əsaslı sahələr açıq şəkildə qadağandır.
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
        ];
    }
}
