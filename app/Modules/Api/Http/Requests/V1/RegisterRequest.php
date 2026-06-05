<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Audit Api-3: `Rule::unique('users','email')` qaldırıldı — mövcud email
     * üçün 422 "artıq qeydiyyatdadır" mesajı email enumeration imkanı verirdi.
     * İndi unique constraint controller-də idarə olunur (mövcud user üçün
     * silent re-trigger, yeni user üçün create) və hər iki halda eyni generic
     * 200 cavab qaytarılır.
     */
    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'email'       => ['required', 'string', 'email', 'max:255'],
            // Audit Api-8: E.164 yaxın forma — opsional '+', sonra 6-15 rəqəm.
            // Boşluq, tire və mötərizələrə icazə YOXDUR; mobile app normallaşdırıb
            // göndərməlidir (UI maska + submit-də strip).
            'phone'       => ['required', 'string', 'max:32', 'regex:/^\+?\d{6,15}$/'],
            'password'    => ['required', 'string', 'confirmed', Password::min(8)],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Telefon nömrəsi E.164 formatında olmalıdır: opsional "+" və sonra 6–15 rəqəm (boşluq və simvol olmadan).',
        ];
    }
}
