<?php

namespace Tests\Unit;

use App\Services\Payroll\LoanService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LoanScheduleTest extends TestCase
{
    private function schedule(float $amount, int $tenor, string $method, float $rate): array
    {
        return app(LoanService::class)->schedule($amount, $tenor, $method, $rate, Carbon::create(2026, 11, 1));
    }

    public function test_no_interest_splits_the_amount_and_the_last_takes_the_cents(): void
    {
        $rows = $this->schedule(1000, 3, 'none', 0);
        $this->assertSame([333.33, 333.33, 333.34], array_column($rows, 'amount'));
        $this->assertSame([2026, 11], [$rows[0]['year'], $rows[0]['month']]);
        $this->assertSame([2027, 1], [$rows[2]['year'], $rows[2]['month']]);
    }

    public function test_flat_interest_is_on_the_full_amount_for_the_term(): void
    {
        // 1,200 at 10% a year for 12 months: 120 interest, 110 a month.
        $rows = $this->schedule(1200, 12, 'flat', 10);
        $this->assertSame(110.0, $rows[0]['amount']);
        $this->assertSame(120.0, round(array_sum(array_column($rows, 'interest')), 2));
    }

    public function test_reducing_balance_has_equal_payments_with_falling_interest(): void
    {
        // 1,000 at 12% (1% a month) over 2 months: 507.51 a month.
        $rows = $this->schedule(1000, 2, 'reducing_balance', 12);
        $this->assertSame([10.0, 5.02], array_column($rows, 'interest'));
        $this->assertSame([507.51, 507.51], array_column($rows, 'amount'));
        $this->assertSame(1000.0, round(array_sum(array_column($rows, 'principal')), 2));
    }
}
