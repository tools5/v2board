<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\OauthController;
use ReflectionClass;
use Tests\TestCase;

class AdminCsvSecurityTest extends TestCase
{
    /**
     * @dataProvider spreadsheetFormulaProvider
     */
    public function testOauthCsvCellsNeutralizeSpreadsheetFormulas(string $value): void
    {
        $controller = new OauthController();
        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('csvCell');
        $method->setAccessible(true);

        $escaped = $method->invoke($controller, $value);

        $this->assertStringStartsWith('"\'', $escaped);
    }

    public function spreadsheetFormulaProvider(): array
    {
        return [
            'equals' => ['=HYPERLINK("https://example.invalid")'],
            'plus' => ['+1+1'],
            'minus' => ['-1+1'],
            'at' => ['@SUM(1,1)'],
            'leading whitespace' => [" \t=1+1"],
        ];
    }

    public function testOauthCsvCellsStillEscapeQuotesAndLineBreaks(): void
    {
        $controller = new OauthController();
        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('csvCell');
        $method->setAccessible(true);

        $this->assertSame('"normal ""value"" next"', $method->invoke($controller, "normal \"value\"\r\nnext"));
    }
}
