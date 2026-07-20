<?php

namespace Tests\Unit;

use App\Console\Commands\CheckCommission;
use Tests\TestCase;

class CheckCommissionTest extends TestCase
{
    private $originalV2boardConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalV2boardConfig = config('v2board');
    }

    protected function tearDown(): void
    {
        config(['v2board' => $this->originalV2boardConfig]);
        parent::tearDown();
    }

    public function testDistributionSharesAreValidated(): void
    {
        config([
            'v2board.commission_distribution_enable' => 1,
            'v2board.commission_distribution_l1' => 50,
            'v2board.commission_distribution_l2' => 30,
            'v2board.commission_distribution_l3' => 20
        ]);

        $this->assertSame([50, 30, 20], (new TestableCheckCommission())->shareLevels());
    }

    /**
     * @dataProvider invalidDistributionProvider
     */
    public function testInvalidDistributionSharesAreRejected($l1, $l2, $l3): void
    {
        config([
            'v2board.commission_distribution_enable' => 1,
            'v2board.commission_distribution_l1' => $l1,
            'v2board.commission_distribution_l2' => $l2,
            'v2board.commission_distribution_l3' => $l3
        ]);

        $this->expectException(\InvalidArgumentException::class);
        (new TestableCheckCommission())->shareLevels();
    }

    public function invalidDistributionProvider(): array
    {
        return [
            'negative' => [-1, 0, 0],
            'over one hundred' => [101, 0, 0],
            'fractional' => [33.3, 33, 33],
            'sum over one hundred' => [60, 30, 20],
            'non numeric' => ['invalid', 0, 0]
        ];
    }

    public function testCommissionShareUsesIntegerArithmetic(): void
    {
        $command = new TestableCheckCommission();
        $this->assertSame(33, $command->commissionShare(101, 33));
        $this->assertSame(2147483647, $command->commissionShare(2147483647, 100));
    }

    /**
     * @dataProvider invalidStoredMoneyProvider
     */
    public function testStoredMoneyRejectsValuesThatWouldBeSilentlyCast($value): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new TestableCheckCommission())->storedMoney($value);
    }

    public function invalidStoredMoneyProvider(): array
    {
        return [
            'non numeric' => ['invalid'],
            'fractional float' => [10.5],
            'fractional string' => ['10.5'],
            'scientific notation' => ['1e3'],
            'overflow' => ['2147483648'],
            'negative' => [-1],
            'boolean' => [true]
        ];
    }
}

class TestableCheckCommission extends CheckCommission
{
    public function shareLevels(): array
    {
        return $this->resolveCommissionShareLevels();
    }

    public function commissionShare(int $commissionBase, int $percentage): int
    {
        return $this->calculateCommissionShare($commissionBase, $percentage);
    }

    public function storedMoney($value): int
    {
        return $this->normalizeMoney($value, '测试金额');
    }
}
