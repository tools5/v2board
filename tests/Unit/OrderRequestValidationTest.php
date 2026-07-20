<?php

namespace Tests\Unit;

use App\Http\Requests\User\OrderSave;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class OrderRequestValidationTest extends TestCase
{
    /**
     * @dataProvider invalidOrderProvider
     */
    public function testOrderSaveRejectsMalformedAmountsAndPlanIds(array $payload): void
    {
        $validator = Validator::make($payload, (new OrderSave())->rules());

        $this->assertTrue($validator->fails());
    }

    public function invalidOrderProvider(): array
    {
        return [
            'array plan id' => [[
                'plan_id' => [0],
                'period' => 'deposit',
                'deposit_amount' => 100,
            ]],
            'missing deposit amount' => [[
                'plan_id' => 0,
                'period' => 'deposit',
            ]],
            'fractional deposit amount' => [[
                'plan_id' => 0,
                'period' => 'deposit',
                'deposit_amount' => '10.5',
            ]],
            'scientific deposit amount' => [[
                'plan_id' => 0,
                'period' => 'deposit',
                'deposit_amount' => '1e3',
            ]],
            'oversized deposit amount' => [[
                'plan_id' => 0,
                'period' => 'deposit',
                'deposit_amount' => 9999999,
            ]],
        ];
    }

    public function testOrderSaveAcceptsIntegerDepositAmountFromFormInput(): void
    {
        $validator = Validator::make([
            'plan_id' => '0',
            'period' => 'deposit',
            'deposit_amount' => '100',
        ], (new OrderSave())->rules());

        $this->assertFalse($validator->fails());
    }
}
