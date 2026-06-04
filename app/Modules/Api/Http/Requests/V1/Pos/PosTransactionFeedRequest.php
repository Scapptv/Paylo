<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Requests\V1\Pos;

use App\Core\Enums\TransactionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reconciliation feed query parametrləri.
 *
 * POSNET (və ya digər M2M client) öz lokal `(merchant_id, receipt_no)` görünüşünü
 * Paylo-nun yazılmış vəziyyəti ilə müqayisə etmək üçün cursor-paginated feed
 * çəkir. Filter dəsti hadisə vaxtına (`occurred_at`) əsaslanır, çünki POSNET
 * adətən "son uğurlu sync-dan bəri nə baş verib" sualı verir.
 */
class PosTransactionFeedRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string'],
            'since'  => ['nullable', 'date'],
            'until'  => ['nullable', 'date', 'after_or_equal:since'],
            'status' => ['nullable', Rule::in(array_map(fn ($s) => $s->value, TransactionStatus::cases()))],
            'limit'  => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'since.date'      => 'since ISO-8601 və ya YYYY-MM-DD formatında olmalıdır.',
            'until.date'      => 'until ISO-8601 və ya YYYY-MM-DD formatında olmalıdır.',
            'until.after_or_equal' => 'until since-dən kiçik ola bilməz.',
            'limit.max'       => 'limit 200-dən böyük ola bilməz (single request).',
            'status.in'       => 'status yalnız: completed | reversed | refunded.',
        ];
    }
}
