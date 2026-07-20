<?php

namespace Tests\Unit;

use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use PHPUnit\Framework\TestCase;

class BackgroundJobValidationTest extends TestCase
{
    /**
     * @dataProvider invalidRateProvider
     */
    public function testTrafficFetchRejectsInvalidRate($rate): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TrafficFetchJob([], ['rate' => $rate], 'test'))->handle();
    }

    /**
     * @dataProvider invalidRateProvider
     */
    public function testStatUserRejectsInvalidRate($rate): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StatUserJob([], ['rate' => $rate], 'test'))->handle();
    }

    public function invalidRateProvider(): array
    {
        return [
            'negative' => [-0.1],
            'positive infinity' => [INF],
            'not a number' => [NAN],
            'numeric overflow' => ['1e999'],
            'non numeric' => ['invalid']
        ];
    }
}
