<?php

declare(strict_types=1);

namespace App\Modules\Merchant\Http\Requests;

use App\Core\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mövcud staff-ın yenilənməsi. Email və role dəyişdirilə bilər (audit log),
 * lakin `merchant_id` immutable — merchant arası transfer admin işidir.
 *
 * Parol bu endpoint-də DEYİL — ayrı password-reset axını lazımdır (future).
 */
class StaffUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = (int) $this->route('staff')->id;

        return [
            'name'      => ['required', 'string', 'max:255'],
            'email'     => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone'     => ['nullable', 'string', 'max:32', 'regex:/^\+?\d{6,15}$/'],
            'role'      => [
                'required',
                'string',
                Rule::in([UserRole::Cashier->value, UserRole::MerchantStaff->value]),
            ],
            'is_active' => ['required', 'boolean'],

            // Bu endpoint-də parol və merchant_id dəyişdirilmir.
            'password'    => ['prohibited'],
            'merchant_id' => ['prohibited'],
        ];
    }
}
