<?php

namespace Tests\Unit\Accounting;

use App\Modules\Accounting\Approval\ApprovalRuleEvaluator;
use PHPUnit\Framework\TestCase;

class ApprovalRuleEvaluatorTest extends TestCase
{
    public function test_empty_condition_always_matches(): void
    {
        $e = new ApprovalRuleEvaluator();
        $this->assertTrue($e->matches(null, 0, null));
        $this->assertTrue($e->matches([], 0, 'Kasir'));
    }

    public function test_amount_threshold_is_inclusive(): void
    {
        $e = new ApprovalRuleEvaluator();
        $this->assertFalse($e->matches(['amount_gte' => 5000000], 4999999.99, null));
        $this->assertTrue($e->matches(['amount_gte' => 5000000], 5000000, null));
    }

    public function test_creator_role_not_in(): void
    {
        $e = new ApprovalRuleEvaluator();
        $k = ['creator_role_not_in' => ['Akuntan', 'Owner']];
        $this->assertTrue($e->matches($k, 0, 'Kasir'));
        $this->assertFalse($e->matches($k, 0, 'Akuntan'));
        $this->assertTrue($e->matches($k, 0, null)); // pembuat tak diketahui: fail-closed
    }

    public function test_unknown_key_fails_closed_and_all_keys_must_hold(): void
    {
        $e = new ApprovalRuleEvaluator();
        $this->assertTrue($e->matches(['kunci_aneh' => 1], 0, null));
        $this->assertFalse($e->matches(['kunci_aneh' => 1, 'amount_gte' => 10], 5, null));
    }
}
