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
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'email'          => $this->email,
            'phone'          => $this->phone,
            'role'           => $this->role->value,
            'customer_qr'    => $this->customer_qr,
            'email_verified' => $this->email_verified_at !== null,
        ];
    }
}
