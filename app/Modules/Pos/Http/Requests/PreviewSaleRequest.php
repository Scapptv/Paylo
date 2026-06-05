<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Requests;

use App\Core\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Merchant scope EnsureMerchantScope middleware tərəfindən qoyulur.
        // Burada istifadə olunur ki, cashier başqa merchant-ın branch-i və ya
        // customer rolundan kənar user_id payload-da göndərə bilməsin.
        $merchantId = (int) $this->attributes->get('merchant_id', 0);

        return [
            // Yalnız Customer rolundakı user-lər qəbul edilir — bu cashier-in
            // başqa rolların ID-lərini enumerate etməsinin qarşısını alır.
            //
            // Audit 2026-06-04 WEB-2: `is_active=true` da şərtdir. Əks halda
            // deaktivləşdirilmiş/anonimləşdirilmiş hesabın numerik id-si bilinirsə
            // ona bonus preview/earn edilə bilərdi. Bu, lookupCustomer-dəki P-1
            // invariantını (`is_active=true`) preview/complete axınına da genişləndirir.
            'customer_id'       => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q
                    ->where('role', UserRole::Customer->value)
                    ->where('is_active', true)),
            ],
            // Pul dəyəri yalnız integer qəpiklə (cents). Float qəbul edilmir.
            'sale_amount_cents' => ['required', 'integer', 'min:1', 'max:99999999'],
            'use_bonus'         => ['boolean'],
            // Audit P-8: `redeem_cents` yalnız `use_bonus=true` halda göndərilə bilər.
            // `use_bonus=false` ilə birgə gəlsə client bug-ı və ya yanlış UI niyyəti
            // göstərir; silent clamp yox, açıq 422.
            'redeem_cents'      => ['prohibited_unless:use_bonus,true', 'nullable', 'integer', 'min:0', 'max:99999999'],

            // Köhnə float-əsaslı sahələr açıq şəkildə qadafandır.
            'sale_amount'       => ['prohibited'],
            'redeem_azn'        => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'sale_amount.prohibited'             => 'sale_amount artıq qəbul edilmir. sale_amount_cents (integer, qəpik) istifadə edin.',
            'redeem_azn.prohibited'              => 'redeem_azn artıq qəbul edilmir. redeem_cents (integer, qəpik) istifadə edin.',
            'redeem_cents.prohibited_unless'     => 'redeem_cents yalnız use_bonus=true halında göndərilə bilər.',
        ];
    }
}
