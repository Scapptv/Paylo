<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Resources\V1;

use App\Core\Models\Bucket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Bucket
 */
final class BucketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'balance'          => (int) $this->balance,
            'earned_total'     => (int) $this->earned_total,
            'redeemed_total'   => (int) $this->redeemed_total,
            'expired_total'    => (int) $this->expired_total,
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            // `whenLoaded` callback formasi — relation əvvəlcədən load olunmayıbsa
            // MissingValue qaytarır və lazy-load N+1 problemini qarşılayır.
            'merchant'         => $this->whenLoaded('merchant', fn () => [
                'id'       => $this->merchant->id,
                'code'     => $this->merchant->code,
                'name'     => $this->merchant->name,
                'category' => $this->merchant->category,
                'tier'     => $this->merchant->tier,
            ]),
        ];
    }
}
