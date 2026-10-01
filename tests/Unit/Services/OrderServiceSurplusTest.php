<?php

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderServiceSurplusTest extends TestCase
{
    use RefreshDatabase;

    public function test_onetime_plan_paid_by_surplus_credit_keeps_value_when_switching_plan(): void
    {
        $oneTimePlan = $this->makePlan([
            'name' => 'OneTime Plan',
            'transfer_enable' => 750,
            'prices' => [Plan::PERIOD_ONETIME => 26],
        ]);
        $yearlyPlan = $this->makePlan([
            'name' => 'Yearly Plan',
            'prices' => [Plan::PERIOD_YEARLY => 32],
        ]);

        // 用户当前持有一次性流量套餐（长期有效），750GB 未使用
        $user = $this->makeUser([
            'plan_id' => $oneTimePlan->id,
            'expired_at' => null,
            'transfer_enable' => 75 * 10737418240, // 750 GB
        ]);

        // 上一笔一次性订单全额由折抵额度支付：折抵 30.2 元，套餐实价 26 元，4.2 元退回余额
        $this->makeOrder($user, $oneTimePlan, [
            'type' => Order::TYPE_UPGRADE,
            'period' => Plan::PERIOD_ONETIME,
            'status' => Order::STATUS_COMPLETED,
            'total_amount' => 0,
            'balance_amount' => 0,
            'surplus_amount' => 3020,
            'surplus_credit' => 420,
        ]);

        $order = OrderService::createFromRequest($user, $yearlyPlan, 'year_price');

        // 实际对价 = 0 + 0 + 3020 - 420 = 2600 分，未用流量占比 100%
        $this->assertSame(Order::TYPE_UPGRADE, (int) $order->type);
        $this->assertSame(2600, (int) $order->surplus_amount);
        $this->assertSame(3200 - 2600, (int) $order->total_amount);
        $this->assertNotEmpty($order->surplus_order_ids);
    }

    public function test_onetime_plan_surplus_scales_with_remaining_traffic(): void
    {
        $oneTimePlan = $this->makePlan([
            'name' => 'OneTime Plan',
            'transfer_enable' => 750,
            'prices' => [Plan::PERIOD_ONETIME => 26],
        ]);
        $yearlyPlan = $this->makePlan([
            'name' => 'Yearly Plan',
            'prices' => [Plan::PERIOD_YEARLY => 32],
        ]);

        // 使用 150GB / 750GB，剩余 600GB（80%）
        $user = $this->makeUser([
            'plan_id' => $oneTimePlan->id,
            'expired_at' => null,
            'transfer_enable' => 75 * 10737418240,
            'u' => 15 * 10737418240,
            'd' => 0,
        ]);

        $this->makeOrder($user, $oneTimePlan, [
            'type' => Order::TYPE_UPGRADE,
            'period' => Plan::PERIOD_ONETIME,
            'status' => Order::STATUS_COMPLETED,
            'total_amount' => 0,
            'balance_amount' => 0,
            'surplus_amount' => 3020,
            'surplus_credit' => 420,
        ]);

        $order = OrderService::createFromRequest($user, $yearlyPlan, 'year_price');

        // 2600 分 × 80% = 2080 分
        $this->assertSame(2080, (int) $order->surplus_amount);
        $this->assertSame(3200 - 2080, (int) $order->total_amount);
    }

    public function test_onetime_plan_paid_with_cash_still_computes_surplus(): void
    {
        $oneTimePlan = $this->makePlan([
            'name' => 'OneTime Plan',
            'transfer_enable' => 750,
            'prices' => [Plan::PERIOD_ONETIME => 26],
        ]);
        $yearlyPlan = $this->makePlan([
            'name' => 'Yearly Plan',
            'prices' => [Plan::PERIOD_YEARLY => 32],
        ]);

        $user = $this->makeUser([
            'plan_id' => $oneTimePlan->id,
            'expired_at' => null,
            'transfer_enable' => 75 * 10737418240,
        ]);

        // 现金购买：原价 30 元，余额抵 4 元，无折抵
        $this->makeOrder($user, $oneTimePlan, [
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_ONETIME,
            'status' => Order::STATUS_COMPLETED,
            'total_amount' => 2600,
            'balance_amount' => 400,
        ]);

        $order = OrderService::createFromRequest($user, $yearlyPlan, 'year_price');

        // 实际对价 = 2600 + 400 = 3000 分
        $this->assertSame(3000, (int) $order->surplus_amount);
        $this->assertSame(3200 - 3000, (int) $order->total_amount);
    }

    public function test_onetime_plan_paid_by_mixed_payment_combines_all_considerations(): void
    {
        $oneTimePlan = $this->makePlan([
            'name' => 'OneTime Plan',
            'transfer_enable' => 750,
            'prices' => [Plan::PERIOD_ONETIME => 30],
        ]);
        $yearlyPlan = $this->makePlan([
            'name' => 'Yearly Plan',
            'prices' => [Plan::PERIOD_YEARLY => 32],
        ]);

        // 使用 150GB / 750GB，剩余 600GB（80%）
        $user = $this->makeUser([
            'plan_id' => $oneTimePlan->id,
            'expired_at' => null,
            'transfer_enable' => 75 * 10737418240,
            'u' => 15 * 10737418240,
            'd' => 0,
        ]);

        // 混合支付：现金补差 12 元 + 余额 3 元 + 折抵 18 元，多折 3 元退回
        $this->makeOrder($user, $oneTimePlan, [
            'type' => Order::TYPE_UPGRADE,
            'period' => Plan::PERIOD_ONETIME,
            'status' => Order::STATUS_COMPLETED,
            'total_amount' => 1200,
            'balance_amount' => 300,
            'surplus_amount' => 1800,
            'surplus_credit' => 300,
        ]);

        $order = OrderService::createFromRequest($user, $yearlyPlan, 'year_price');

        // 实际对价 = 1200 + 300 + 1800 - 300 = 3000 分，剩余流量 80%
        $this->assertSame(2400, (int) $order->surplus_amount);
        $this->assertSame(3200 - 2400, (int) $order->total_amount);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => uniqid('surplus_', true) . '@example.com',
            'password' => 'password',
            'uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => null,
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
            'name' => 'Surplus Test Plan',
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
            'trade_no' => uniqid('surplus_', true),
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
