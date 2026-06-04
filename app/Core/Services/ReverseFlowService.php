<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Enums\TransactionStatus;
use App\Core\Models\Transaction;
use App\Modules\Api\Services\WebhookSender;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reverse axınının orchestration layer-i. Audit Sprint 8 D-1 (duplikat consolidation):
 * POS `SaleController::reverse()` və Admin `TransactionController::reverse()`-də
 * eyni try/catch + response shape sətir-sətir təkrarlanırdı. Bu service həm
 * idempotency yoxlamasını, həm exception handling-i, həm də response qurmasını
 * tək yerə yığır. Controller-lər yalnız nəticəni qaytarır.
 *
 * Audit invariant-ları:
 *  - IDEMPOTENT: artıq `Reversed` olan tx-ə yeni ledger entry yazılmır.
 *  - RACE-SAFE: paralel sorğu kontekstində LedgerService exception atırsa,
 *    tx-i refresh edib reversed olub-olmadığını yenidən yoxlayırıq.
 *  - InsufficientFundsException + digər `RuntimeException`-lər 422 olur (kontekst
 *    fərqi olsa da yenə uniform shape).
 *
 * Cavab forması (associative array — caller `response()->json(...)` edir):
 *   [
 *     'http_status'      => 200 | 404 | 422,
 *     'transaction_id'   => ?int,
 *     'receipt_no'       => ?string,
 *     'status'           => ?string,     // 'reversed' | 'not_found' | 'unprocessable'
 *     'already_reversed' => bool,        // 200 case-də
 *     'reverse_entries'  => array,       // yeni yaranmış uid+type+amount
 *     'message'          => ?string,     // 404/422 case-də
 *   ]
 */
final class ReverseFlowService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly WebhookSender $webhooks,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        ?Transaction $tx,
        int $actorId,
        string $returnReceiptNo,
        ?string $reason,
        string $logChannel = 'pos.sale.reverse',
    ): array {
        if ($tx === null) {
            return [
                'http_status' => 404,
                'status'      => 'not_found',
                'message'     => 'Bu qəbz tapılmadı.',
            ];
        }

        // Idempotent qisa-çıxış — yeni iş görmədən cari vəziyyəti qaytar.
        if ($tx->status === TransactionStatus::Reversed) {
            return $this->alreadyReversedResponse($tx);
        }

        try {
            $entries = $this->ledger->reverseTransaction($tx, $actorId, $returnReceiptNo, $reason);
        } catch (\RuntimeException $e) {
            // İki ehtimal: race (paralel artıq reverse etdi) və ya domain reddi (məs
            // InsufficientFunds — müştəri bonusu xərcləyib).
            $tx->refresh();
            if ($tx->status === TransactionStatus::Reversed) {
                return $this->alreadyReversedResponse($tx);
            }

            Log::warning($logChannel . '.failed', [
                'merchant_id' => $tx->merchant_id,
                'actor_id'    => $actorId,
                'tx_id'       => $tx->id,
                'receipt_no'  => $tx->receipt_no,
                'reason'      => $e->getMessage(),
            ]);

            return [
                'http_status' => 422,
                'status'      => 'unprocessable',
                'message'     => $e->getMessage(),
            ];
        }

        Log::info($logChannel . '.ok', [
            'merchant_id'   => $tx->merchant_id,
            'actor_id'      => $actorId,
            'tx_id'         => $tx->id,
            'receipt_no'    => $tx->receipt_no,
            'entries_count' => count($entries),
        ]);

        // POSNET-ə xəbər ver — yalnız POSNET-in özü reverse etməyibsə.
        // `api.*` log channel POSNET-dən gələn API request-i göstərir, bu halda
        // POSNET artıq tx-i bilir, webhook lazımsız (və double-counting yaradar).
        if (! str_starts_with($logChannel, 'api.')) {
            $this->safeEmitWebhook((int) $tx->merchant_id, 'admin_reverse', [
                'transaction_id'   => $tx->id,
                'receipt_no'       => $tx->receipt_no,
                'merchant_id'      => $tx->merchant_id,
                'customer_id'      => $tx->user_id,
                'return_receipt_no'=> $returnReceiptNo,
                'reason'           => $reason,
                'reversed_at'      => now()->toIso8601String(),
                'actor_id'         => $actorId,
                'source'           => $logChannel,
            ]);
        }

        return [
            'http_status'      => 200,
            'transaction_id'   => $tx->id,
            'receipt_no'       => $tx->receipt_no,
            'status'           => TransactionStatus::Reversed->value,
            'already_reversed' => false,
            'reverse_entries'  => array_map(
                fn ($e) => ['uid' => $e->uid, 'type' => $e->type->value, 'amount' => $e->amount],
                $entries,
            ),
        ];
    }

    /**
     * Webhook emit-i HEÇ vaxt reverse axınını sındırmamalıdır. Hər hansı
     * unforeseen istisna (DB lock, JSON encode error) ledger-i bloklamır —
     * audit log-da qalır, operator daha sonra `pos:webhook-redeliver` ilə
     * bərpa edə bilər.
     */
    private function safeEmitWebhook(int $merchantId, string $eventType, array $payload): void
    {
        try {
            $this->webhooks->emit($merchantId, $eventType, $payload);
        } catch (\Throwable $e) {
            Log::warning('webhook.emit.failed', [
                'merchant_id' => $merchantId,
                'event_type'  => $eventType,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function alreadyReversedResponse(Transaction $tx): array
    {
        return [
            'http_status'      => 200,
            'transaction_id'   => $tx->id,
            'receipt_no'       => $tx->receipt_no,
            'status'           => TransactionStatus::Reversed->value,
            'already_reversed' => true,
            'reverse_entries'  => [],
        ];
    }
}
