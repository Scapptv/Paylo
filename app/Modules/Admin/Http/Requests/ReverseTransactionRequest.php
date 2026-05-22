<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route artıq `auth + role:admin` middleware-i ilə qorunur, burada
        // əlavə yoxlama yoxdur. Authorization üçün policy/role middleware-ə güvənirik.
        return true;
    }

    public function rules(): array
    {
        return [
            // Reverse yalnız məhsul qaytarıldıqda mümkündür — return qəbz nömrəsi məcburi.
            'return_receipt_no' => ['required', 'string', 'min:1', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            // Admin reverse audit üçün vacibdir — boş ola bilməz.
            'reason'            => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'return_receipt_no.required' => 'Məhsul qaytarma qəbzi nömrəsi məcburidir.',
            'return_receipt_no.regex'    => 'Qaytarma qəbzi yalnız hərf, rəqəm, nöqtə, alt-xətt və tire ola bilər.',
            'reason.required'            => 'Reverse səbəbi qeyd olunmalıdır (audit üçün).',
            'reason.min'                 => 'Səbəb minimum 3 simvol olmalıdır.',
        ];
    }
}
