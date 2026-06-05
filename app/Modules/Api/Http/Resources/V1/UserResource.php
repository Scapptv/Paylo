<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Resources\V1;

use App\Core\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * Audit Api-6: `email_verified` field-i çıxarıldı. User modeli
     * MustVerifyEmail implement etmir, listener yox idi — field heç vaxt true
     * olmurdu və mobile app-i yalnış istiqamətə yönəldirdi. Email verification
     * gələcəkdə implement edilərsə (queued mail + verify endpoint), bu field
     * yenidən əlavə olunmalıdır.
     *
     * Audit Api-16: `locale` field-i sabit `null` olaraq qaytarılır — DB-də
     * sütun mövcud olmadığı üçün `PUT /me`-dəki `locale` parametri yalnız
     * validate olunur, persist olunmur. Mobile app interface contract-ı
     * qorumaq üçün field response-da əlçatandır. Gələcəkdə `users.locale`
     * sütunu əlavə edildikdə burada `$this->locale` qaytarılmalıdır.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'email'       => $this->email,
            'phone'       => $this->phone,
            'role'        => $this->role->value,
            'customer_qr' => $this->customer_qr,
            'locale'      => null,
        ];
    }
}
