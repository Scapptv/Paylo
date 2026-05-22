<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Requests;

use App\Core\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Merchant scope EnsureMerchantScope middleware tərəfindən qoyulur.
        // Cashier payload-da başqa merchant-ın branch_id-sini və ya Customer
        // rolundan kənar user_id ötürə bilməz — cross-merchant data yazılışının
        // və user enumeration-ın qarşısını alır.
        $merchantId = (int) $this->attributes->get('merchant_id', 0);

        return [
            'customer_id'       => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('role', UserRole::Customer->value)),
            ],
            // Pul dəyəri yalnız integer qəpiklə (cents). Float qəbul edilmir.
            'sale_amount_cents' => ['required', 'integer', 'min:1', 'max:99999999'],
            // receipt_no GLOBAL unique deyil — yalnız merchant səviyyəsində unikaldır.
            // Eyni (merchant + receipt) ikinci dəfə gələrsə SaleController idempotent
            // davranır və mövcud transaction-u qaytarır (POS retry-ə görə).
            'receipt_no'        => ['required', 'string', 'max:64'],
            // Branch yalnız cari merchant-a aid ola bilər — başqa merchant-ın
            // branch-inə sale yazılışının qarşısını alır.
            'branch_id'         => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('merchant_id', $merchantId)),
            ],
            'use_bonus'         => ['boolean'],
            'redeem_cents'      => ['required_if:use_bonus,true', 'nullable', 'integer', 'min:0', 'max:99999999'],

            // Köhnə float-əsaslı sahələr açıq şəkildə qadağandır.
            'sale_amount'       => ['prohibited'],
            'redeem_azn'        => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'sale_amount.prohibited' => 'sale_amount artıq qəbul edilmir. sale_amount_cents (integer, qəpik) istifadə edin.',
            'redeem_azn.prohibited'  => 'redeem_azn artıq qəbul edilmir. redeem_cents (integer, qəpik) istifadə edin.',
        ];
    }
}
