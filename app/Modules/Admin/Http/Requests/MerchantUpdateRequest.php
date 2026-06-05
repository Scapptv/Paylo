<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Core\Enums\MerchantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mövcud merchant-ın yenilənməsi. `code` və `tin` immutable sayılır (ledger
 * inteqrasiya üçün dəyişdirilməməlidir), digər sahələr admin tərəfindən
 * redaktə oluna bilər.
 */
class MerchantUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:255'],
            'legal_name'       => ['required', 'string', 'max:255'],
            'mcc'              => ['required', 'integer', 'between:1000,9999'],
            'category'         => ['required', 'string', 'in:grocery,restaurant,fuel,pharmacy,retail'],
            'tier'             => ['required', 'string', 'in:standard,premium,enterprise'],
            'status'           => ['required', 'string', Rule::in(MerchantStatus::values())],
            'region'           => ['required', 'string', 'max:64'],
            'settlement_iban'  => ['required', 'string', 'regex:/^AZ\d{2}[A-Z]{4}[A-Z0-9]{20}$/'],
            'settlement_cycle' => ['required', 'string', 'in:T+1,T+3,T+5'],

            // Audit: `code` və `tin` immutable — yalnız yaradılma zamanı təyin olunur.
            'code' => ['prohibited'],
            'tin'  => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.prohibited' => 'Merchant code dəyişdirilə bilməz (ledger inteqrasiya üçün immutable).',
            'tin.prohibited'  => 'TIN dəyişdirilə bilməz.',
        ];
    }
}
