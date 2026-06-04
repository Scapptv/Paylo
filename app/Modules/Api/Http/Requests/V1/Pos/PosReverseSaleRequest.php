<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Requests\V1\Pos;

use Illuminate\Foundation\Http\FormRequest;

class PosReverseSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
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
