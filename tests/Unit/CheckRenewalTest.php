<?php

namespace Tests\Unit;

use App\Console\Commands\CheckRenewal;
use DomainException;
use PHPUnit\Framework\TestCase;

class CheckRenewalTest extends TestCase
{
    /**
     * @dataProvider validMoneyProvider
     */
    public function testStoredMoneyAcceptsOnlyBoundedNonNegativeIntegers($value, int $expected): void
    {
        $this->assertSame($expected, (new TestableCheckRenewal())->money($value));
    }

    public function validMoneyProvider(): array
    {
        return [
            'zero integer' => [0, 0],
            'zero string' => ['0', 0],
            'leading zero string' => ['00042', 42],
            'maximum value' => ['2147483647', 2147483647]
        ];
    }

    /**
     * @dataProvider invalidMoneyProvider
     */
    public function testStoredMoneyRejectsInvalidValues($value): void
    {
        $this->expectException(DomainException::class);
        (new TestableCheckRenewal())->money($value);
    }

    public function invalidMoneyProvider(): array
    {
        return [
            'negative integer' => [-1],
            'negative string' => ['-1'],
            'fractional float' => [1.5],
            'fractional string' => ['1.5'],
            'scientific notation' => ['1e3'],
            'positive infinity' => [INF],
            'not a number' => [NAN],
            'integer overflow' => ['2147483648'],
            'boolean' => [true],
            'null' => [null],
            'array' => [[]]
        ];
    }
}

class TestableCheckRenewal extends CheckRenewal
{
    public function money($value): int
    {
        return $this->normalizeMoney($value, '测试金额');
    }
}
