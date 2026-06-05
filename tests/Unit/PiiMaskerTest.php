<?php

declare(strict_types=1);

use App\Core\Support\PiiMasker;

/*
|--------------------------------------------------------------------------
| Sprint 8 D-6 — PiiMasker utility deterministik davranışı.
|--------------------------------------------------------------------------
*/

it('masks 13-char phone keeping head 4 and tail 3', function () {
    expect(PiiMasker::phone('+994501234567'))->toBe('+994******567');
});

it('masks short phone (≤7 chars) fully', function () {
    expect(PiiMasker::phone('12345'))->toBe('*****');
    expect(PiiMasker::phone('1234567'))->toBe('*******');
});

it('masks empty phone to empty (no exception)', function () {
    expect(PiiMasker::phone(''))->toBe('');
});

it('masks email local part keeping first and last char', function () {
    expect(PiiMasker::email('aysel@example.com'))->toBe('a***l@example.com');
});

it('masks short email local part (≤2 chars) fully', function () {
    expect(PiiMasker::email('ab@example.com'))->toBe('**@example.com');
});

it('masks IBAN keeping country+check head and 4 trailing digits', function () {
    expect(PiiMasker::iban('AZ21NABZ00000000137010001944'))
        ->toBe('AZ21********************1944');
});

it('masks short IBAN (≤8) fully', function () {
    expect(PiiMasker::iban('AZ21NABZ'))->toBe('********');
});

it('is deterministic — same input yields same output', function () {
    $a = PiiMasker::phone('+994501234567');
    $b = PiiMasker::phone('+994501234567');
    expect($a)->toBe($b);
});
