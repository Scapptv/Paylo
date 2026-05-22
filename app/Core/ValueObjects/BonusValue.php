<?php

declare(strict_types=1);

namespace App\Core\ValueObjects;

use InvalidArgumentException;

/**
 * Bonus = pul deyil, **loyallıq dəyəridir**.
 * Bütün hesablamalar tam ədədlərlə (qəpik səviyyəsində) aparılır ki, float xətaları yaranmasın.
 *
 * `amount` daxili olaraq integer-dir, lakin AZN-ə format edilərkən `/100` olunur.
 */
final readonly class BonusValue
{
    public function __construct(public int $amount)
    {
        if ($amount < 0) {
            throw new InvalidArgumentException('BonusValue mənfi ola bilməz.');
        }
    }

    public static function fromAzn(float|int|string $azn): self
    {
        return new self((int) round((float) $azn * 100));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self($this->amount + $other->amount);
    }

    public function subtract(self $other): self
    {
        if ($other->amount > $this->amount) {
            throw new InvalidArgumentException('Mənfi balans yaranır — keçərsiz əməliyyat.');
        }

        return new self($this->amount - $other->amount);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->amount > $other->amount;
    }

    public function toAzn(): float
    {
        return round($this->amount / 100, 2);
    }

    public function format(): string
    {
        return number_format($this->amount / 100, 2, '.', ',') . ' AZN';
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
