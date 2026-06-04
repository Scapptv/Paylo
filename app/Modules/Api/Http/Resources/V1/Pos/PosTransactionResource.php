<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Resources\V1\Pos;

use App\Core\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Reconciliation feed üçün Transaction-un POSNET-yönlü forması.
 *
 * Müştəri PII-si daxil edilmir — POSNET inteqrasiyası transaksiyanın baş
 * verdiyini, məbləğini və statusunu yoxlamaq üçün gəlir, customer profili
 * üçün deyil. `user_id` qaytarılır ki, POSNET öz lokal sale qeydlərində
 * müştəri linkini yenidən qura bilsin.
 *
 * @mixin Transaction
 */
final class PosTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'transaction_id'   => $this->id,
            'receipt_no'       => $this->receipt_no,
            'branch_id'        => $this->branch_id,
            'customer_id'      => $this->user_id,
            'cashier_id'       => $this->cashier_id,
            'sale_amount'      => (int) $this->sale_amount,
            'earned_amount'    => (int) $this->earned_amount,
            'redeemed_amount'  => (int) $this->redeemed_amount,
            'status'           => $this->status->value,
            'occurred_at'      => $this->occurred_at?->toIso8601String(),
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}
