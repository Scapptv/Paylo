<?php

declare(strict_types=1);

namespace App\Modules\Merchant\Http\Requests;

use App\Core\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Merchant owner tərəfindən staff (cashier və ya merchant_staff) yaradılması.
 * Yalnız MerchantOwner role-undan istifadə olunur (route middleware).
 *
 * Audit qeydi: yaradılan user-in `merchant_id`-si controller-dən gəlir, payload-da
 * deyil — başqa merchant-a cashier inject etmək cəhdinin qarşısı alınır.
 */
class StaffStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone'    => ['nullable', 'string', 'max:32', 'regex:/^\+?\d{6,15}$/'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            'role'     => [
                'required',
                'string',
                Rule::in([UserRole::Cashier->value, UserRole::MerchantStaff->value]),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Telefon E.164 formatında olmalıdır: opsional "+" və 6–15 rəqəm.',
            'role.in'     => 'Yalnız "cashier" və ya "merchant_staff" rolu yaradıla bilər.',
        ];
    }
}
