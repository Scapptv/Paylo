<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Resources\V1;

use App\Core\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LedgerEntry
 */
final class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'uid'           => $this->uid,
            'type'          => $this->type->value,
            'type_label'    => $this->type->label(),
            'is_credit'     => $this->type->isCredit(),
            'amount'        => (int) $this->amount,
            'balance_after' => (int) $this->balance_after,
            'ref'           => $this->ref,
            'created_at'    => $this->created_at?->toIso8601String(),
            // `whenLoaded` callback — N+1 lazy-load qarşısı.
            'merchant'      => $this->whenLoaded('merchant', fn () => [
                'id'       => $this->merchant->id,
                'code'     => $this->merchant->code,
                'name'     => $this->merchant->name,
                'category' => $this->merchant->category,
            ]),
        ];
    }
}
