<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Core\Enums\MerchantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Yeni merchant yaradılması üçün validation. Yalnız admin role-undan istifadə
 * olunur (route middleware təmin edir). `code` və `tin` unique olmalıdır
 * (cədvəl səviyyəsində constraint var, lakin friendly mesaj üçün burada da).
 */
class MerchantStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code'             => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_]{2,32}$/', Rule::unique('merchants', 'code')],
            'name'             => ['required', 'string', 'max:255'],
            'legal_name'       => ['required', 'string', 'max:255'],
            'tin'              => ['required', 'string', 'regex:/^\d{10}$/', Rule::unique('merchants', 'tin')],
            'mcc'              => ['required', 'integer', 'between:1000,9999'],
            'category'         => ['required', 'string', 'in:grocery,restaurant,fuel,pharmacy,retail'],
            'tier'             => ['required', 'string', 'in:standard,premium,enterprise'],
            'status'           => ['required', 'string', Rule::in(MerchantStatus::values())],
            'region'           => ['required', 'string', 'max:64'],
            // Azerbaijani IBAN: AZ + 2 check digits + 4 alphabetic bank code + 20 alphanumeric BBAN = 28 chars total.
            'settlement_iban'  => ['required', 'string', 'regex:/^AZ\d{2}[A-Z]{4}[A-Z0-9]{20}$/'],
            'settlement_cycle' => ['required', 'string', 'in:T+1,T+3,T+5'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex'             => 'Code yalnız kiçik hərf, rəqəm və alt-xəttdən ibarət ola bilər (2–32).',
            'tin.regex'              => 'TIN tam 10 rəqəm olmalıdır.',
            'settlement_iban.regex'  => 'IBAN AZ formatında olmalıdır: AZ + 2 rəqəm + 4 hərf bank kodu + 20 simvol (cəmi 28).',
        ];
    }
}
