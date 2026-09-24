<?php

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class OrderBonusTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_creation_snapshots_bonus_months_from_plan(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan([
            'prices' => [Plan::PERIOD_YEARLY => 100],
            'bonuses' => [Plan::PERIOD_YEARLY => 6],
        ]);

        $order = OrderService::createFromRequest($user, $plan, Plan::PERIOD_YEARLY);

        $this->assertSame(6, $order->bonus_months);

        // 活动下线（修改套餐配置）后，已下单订单仍按快照发放
        $plan->update(['bonuses' => [Plan::PERIOD_YEARLY => 1]]);
        $this->assertSame(6, Order::findOrFail($order->id)->bonus_months);

        // 新下单则按新配置快照
        Order::findOrFail($order->id)->update(['status' => Order::STATUS_CANCELLED]);
        $secondOrder = OrderService::createFromRequest($user, $plan, Plan::PERIOD_YEARLY);
        $this->assertSame(1, $secondOrder->bonus_months);
    }

    public function test_open_applies_bonus_months_to_expired_at(): void
    {
        $user = $this->makeUser(['expired_at' => 0]);
        $plan = $this->makePlan([
            'prices' => [Plan::PERIOD_YEARLY => 100],
            'bonuses' => [Plan::PERIOD_YEARLY => 6],
        ]);
        $order = $this->makeOrder($user, $plan, [
            'period' => Plan::PERIOD_YEARLY,
            'bonus_months' => 6,
            'status' => Order::STATUS_PROCESSING,
        ]);

        (new OrderService($order))->open();

        $user->refresh();
        // 买年付送半年：12 + 6 = 18 个月
        $expected = Carbon::createFromTimestamp(time())->addMonths(18)->timestamp;
        $this->assertEqualsWithDelta($expected, $user->expired_at, 5);
        $this->assertSame(Order::STATUS_COMPLETED, Order::findOrFail($order->id)->status);
    }

    public function test_renewal_stacks_bonus_months_on_existing_expiry(): void
    {
        $currentExpiry = Carbon::now()->addMonths(3)->timestamp;
        $user = $this->makeUser(['expired_at' => $currentExpiry]);
        $plan = $this->makePlan([
            'prices' => [Plan::PERIOD_YEARLY => 100],
            'bonuses' => [Plan::PERIOD_YEARLY => 6],
        ]);
        $user->update(['plan_id' => $plan->id, 'group_id' => $plan->group_id]);

        $order = $this->makeOrder($user, $plan, [
            'type' => Order::TYPE_RENEWAL,
            'period' => Plan::PERIOD_YEARLY,
            'bonus_months' => 6,
            'status' => Order::STATUS_PROCESSING,
        ]);

        (new OrderService($order))->open();

        $user->refresh();
        // 在原有到期时间上叠加 12 + 6 = 18 个月
        $expected = Carbon::createFromTimestamp($currentExpiry)->addMonths(18)->timestamp;
        $this->assertEqualsWithDelta($expected, $user->expired_at, 5);
    }

    public function test_order_without_bonus_keeps_original_period_length(): void
    {
        $user = $this->makeUser(['expired_at' => 0]);
        $plan = $this->makePlan([
            'prices' => [Plan::PERIOD_YEARLY => 100],
        ]);
        $order = $this->makeOrder($user, $plan, [
            'period' => Plan::PERIOD_YEARLY,
            'status' => Order::STATUS_PROCESSING,
        ]);

        (new OrderService($order))->open();

        $user->refresh();
        $expected = Carbon::createFromTimestamp(time())->addMonths(12)->timestamp;
        $this->assertEqualsWithDelta($expected, $user->expired_at, 5);
    }

    public function test_onetime_and_reset_traffic_periods_have_no_bonus(): void
    {
        $plan = $this->makePlan([
            'bonuses' => [
                Plan::PERIOD_ONETIME => 6,
                Plan::PERIOD_RESET_TRAFFIC => 6,
                Plan::PERIOD_MONTHLY => 1,
            ],
        ]);

        $this->assertSame(0, $plan->getBonusMonths(Plan::PERIOD_ONETIME));
        $this->assertSame(0, $plan->getBonusMonths(Plan::PERIOD_RESET_TRAFFIC));
        $this->assertSame(1, $plan->getBonusMonths(Plan::PERIOD_MONTHLY));
    }

    public function test_set_bonus_months_rejects_non_bonusable_periods(): void
    {
        $plan = $this->makePlan();

        $this->expectException(InvalidArgumentException::class);
        $plan->setBonusMonths(Plan::PERIOD_ONETIME, 6);
    }

    public function test_upgrade_surplus_value_accounts_for_bonus_months(): void
    {
        $planA = $this->makePlan([
            'prices' => [Plan::PERIOD_YEARLY => 100],
            'bonuses' => [Plan::PERIOD_YEARLY => 6],
        ]);
        $planB = $this->makePlan([
            'name' => 'Plan B',
            'prices' => [Plan::PERIOD_YEARLY => 200],
        ]);

        // 用户 6 个月前买了 A 年付（送 6 个月），到期时间 = 首单时间 + 18 个月
        $firstOrderAt = Carbon::now()->subMonths(6);
        $user = $this->makeUser([
            'plan_id' => $planA->id,
            'expired_at' => $firstOrderAt->copy()->addMonths(18)->timestamp,
            'transfer_enable' => 1111 * 1073741824,
        ]);

        $this->makeOrder($user, $planA, [
            'period' => Plan::PERIOD_YEARLY,
            'bonus_months' => 6,
            'total_amount' => 10000,
            'status' => Order::STATUS_COMPLETED,
            'created_at' => $firstOrderAt->timestamp,
            'updated_at' => $firstOrderAt->timestamp,
        ]);

        // 换购 B 年付，触发折抵
        $upgradeOrder = OrderService::createFromRequest($user, $planB, Plan::PERIOD_YEARLY);

        $this->assertSame(Order::TYPE_UPGRADE, $upgradeOrder->type);
        // 剩余 12 / 18 个月 → 折抵 10000 * 2/3 ≈ 6666
        // 若折抵逻辑未计入赠送月数，则只会按 12 个月周期折算出 5000
        $this->assertEqualsWithDelta(6666, $upgradeOrder->surplus_amount, 60);
        $this->assertSame(20000 - $upgradeOrder->surplus_amount, $upgradeOrder->total_amount);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'bonus-test-' . uniqid() . '@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-' . str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function makePlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'group_id' => null,
            'transfer_enable' => 1111,
            'name' => 'Bonus Test Plan',
            'speed_limit' => null,
            'show' => 1,
            'sort' => 0,
            'renew' => 1,
            'prices' => [
                Plan::PERIOD_MONTHLY => 11,
            ],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'capacity_limit' => null,
            'sell' => 1,
            'device_limit' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function makeOrder(User $user, Plan $plan, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => uniqid('bonus_', true),
            'total_amount' => 0,
            'balance_amount' => 0,
            'status' => Order::STATUS_PENDING,
            'commission_status' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }
}
