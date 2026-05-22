<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'email'       => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone'       => ['required', 'string', 'max:32'],
            'password'    => ['required', 'string', 'confirmed', Password::min(8)],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }
}
