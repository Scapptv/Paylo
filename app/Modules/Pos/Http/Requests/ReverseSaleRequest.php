<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Reverse YALNIZ məhsul qaytarıldıqda mümkündür — return qəbzinin nömrəsi
        // məcburidir (audit + SQL idempotency: unique(merchant_id, ref, type)).
        // Reason POS-da opsional (F-key axını), amma uzun mətn qadağandır.
        return [
            'return_receipt_no' => ['required', 'string', 'min:1', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'reason'            => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'return_receipt_no.required' => 'Məhsul qaytarma qəbzi nömrəsi məcburidir.',
            'return_receipt_no.regex'    => 'Qaytarma qəbzi yalnız hərf, rəqəm, nöqtə, alt-xətt və tire ola bilər.',
        ];
    }
}
