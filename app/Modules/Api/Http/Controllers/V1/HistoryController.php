<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers\V1;

use App\Core\Enums\LedgerEntryType;
use App\Core\Models\Bucket;
use App\Core\Models\LedgerEntry;
use App\Http\Controllers\Controller;
use App\Modules\Api\Http\Resources\V1\LedgerEntryResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;

/**
 * GET /api/v1/history — müştərinin ledger tarixçəsi (cursor-paginated).
 *
 * Query:
 *   - cursor       (opsional)  Opaque cursor (next ə ya prev)
 *   - type         (opsional)  earn|redeem|refund|reversal|expire|adjustment|transfer
 *   - merchant_id  (opsional)  Yalnız müəyyən merchant-ın yazıları (yalnız `index`)
 *   - from         (opsional)  ISO8601 / Y-m-d — created_at >= from
 *   - to           (opsional)  ISO8601 / Y-m-d — created_at <= to
 *   - limit        (opsional)  Default 20, max 50
 */
final class HistoryController extends Controller
{
    /**
     * Hər iki endpoint üçün ortaq filter qaydaları. `index` əlavə olaraq
     * `merchant_id` qəbul edir; `forBucket`-də o, bucket-dən gəlir.
     */
    private const COMMON_FILTER_RULES = [
        'cursor' => ['nullable', 'string'],
        'type'   => ['nullable', 'string'],
        'from'   => ['nullable', 'date'],
        'to'     => ['nullable', 'date', 'after_or_equal:from'],
        'limit'  => ['nullable', 'integer', 'min:1', 'max:50'],
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate(self::COMMON_FILTER_RULES + [
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
        ]);

        $query = LedgerEntry::with('merchant:id,code,name,category')
            ->where('user_id', $user->id);

        if (! empty($validated['merchant_id'])) {
            $query->where('merchant_id', (int) $validated['merchant_id']);
        }

        return $this->paginate($query, $request, $validated);
    }

    /**
     * GET /api/v1/buckets/{bucket}/history — bir bucket-ə aid ledger yazıları.
     *
     * Təhlükəsizlik: sahiblik yoxlaması — başqa istifadəçinin bucket-inə
     * açmaq cəhdi 403 qaytarır.
     */
    public function forBucket(Request $request, Bucket $bucket): JsonResponse
    {
        $user = $request->user();

        abort_unless($bucket->user_id === $user->id, 403);

        $validated = $request->validate(self::COMMON_FILTER_RULES);

        $query = LedgerEntry::with('merchant:id,code,name,category')
            ->where('user_id', $user->id)
            ->where('merchant_id', $bucket->merchant_id);

        return $this->paginate($query, $request, $validated);
    }

    /**
     * Ortaq filter tətbiqi + cursor paginate + JSON response shape.
     *
     * `merchant_id` filtri burada DEYİL, çağıran metodda tətbiq olunur —
     * çünki `forBucket` onu route-dan (bucket-dən) götürür, `index` isə
     * query-dən. Qalan filter-lərin (type/from/to/cursor/limit) məntiqi
     * hər iki endpoint üçün eyni qaldığından bu yerdə birləşdirilib.
     *
     * @param  array<string,mixed>  $validated
     */
    private function paginate(Builder $query, Request $request, array $validated): JsonResponse
    {
        if (! empty($validated['type'])) {
            $type = LedgerEntryType::tryFrom($validated['type']);
            if ($type) {
                $query->where('type', $type);
            }
        }

        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', Carbon::parse($validated['from'])->startOfDay());
        }

        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', Carbon::parse($validated['to'])->endOfDay());
        }

        $cursor = isset($validated['cursor'])
            ? Cursor::fromEncoded($validated['cursor'])
            : null;

        $limit = (int) ($validated['limit'] ?? 20);

        $page = $query->orderByDesc('id')->cursorPaginate($limit, ['*'], 'cursor', $cursor);

        return response()->json([
            'data'        => LedgerEntryResource::collection($page->items())->toArray($request),
            'next_cursor' => $page->nextCursor()?->encode(),
            'prev_cursor' => $page->previousCursor()?->encode(),
            'has_more'    => $page->nextCursor() !== null,
        ]);
    }
}
