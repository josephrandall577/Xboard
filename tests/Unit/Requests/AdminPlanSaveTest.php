<?php

namespace Tests\Unit\Requests;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPlanSaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_without_bonuses_key_preserves_existing_bonuses(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $plan = $this->makePlan(['bonuses' => [Plan::PERIOD_YEARLY => 6]]);

        // 模拟尚未适配赠送配置的旧版管理面板：保存时不携带 bonuses 键
        $response = $this->postJson($this->adminUrl('plan/save'), [
            'id' => $plan->id,
            'name' => $plan->name,
            'transfer_enable' => $plan->transfer_enable,
            'prices' => ['yearly' => 200],
        ]);

        $response->assertStatus(200);
        // 赠送配置必须原样保留，不能被静默清空
        $this->assertSame([Plan::PERIOD_YEARLY => 6], $plan->refresh()->bonuses);
    }

    public function test_save_with_null_bonuses_clears_bonuses(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $plan = $this->makePlan(['bonuses' => [Plan::PERIOD_YEARLY => 6]]);

        // 显式传 null = 活动下线
        $response = $this->postJson($this->adminUrl('plan/save'), [
            'id' => $plan->id,
            'name' => $plan->name,
            'transfer_enable' => $plan->transfer_enable,
            'prices' => ['yearly' => 200],
            'bonuses' => null,
        ]);

        $response->assertStatus(200);
        $this->assertNull($plan->refresh()->bonuses);
    }

    public function test_save_normalizes_bonuses_and_rejects_invalid_config(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        // 正常保存：0 值被剔除，合法周期保留
        $response = $this->postJson($this->adminUrl('plan/save'), [
            'name' => 'Bonus Plan',
            'transfer_enable' => 100,
            'prices' => ['monthly' => 20, 'yearly' => 200],
            'bonuses' => ['yearly' => 6, 'monthly' => 0],
        ]);

        $response->assertStatus(200);
        $this->assertSame(
            [Plan::PERIOD_YEARLY => 6],
            Plan::where('name', 'Bonus Plan')->firstOrFail()->bonuses
        );

        // 非法周期（一次性套餐）被拒绝
        $response = $this->postJson($this->adminUrl('plan/save'), [
            'name' => 'Invalid Bonus Plan',
            'transfer_enable' => 100,
            'prices' => ['onetime' => 100],
            'bonuses' => ['onetime' => 6],
        ]);

        $response->assertStatus(422);

        // 超过上限被拒绝
        $response = $this->postJson($this->adminUrl('plan/save'), [
            'name' => 'Too Huge Bonus Plan',
            'transfer_enable' => 100,
            'prices' => ['yearly' => 200],
            'bonuses' => ['yearly' => Plan::MAX_BONUS_MONTHS + 1],
        ]);

        $response->assertStatus(422);
    }

    private function adminUrl(string $path): string
    {
        $prefix = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        return "/api/v2/{$prefix}/{$path}";
    }

    private function makeAdmin(): User
    {
        return User::create([
            'email' => 'admin-' . uniqid() . '@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-' . str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 1,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function makePlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'group_id' => null,
            'transfer_enable' => 1111,
            'name' => 'Admin Save Test Plan',
            'speed_limit' => null,
            'show' => 1,
            'sort' => 0,
            'renew' => 1,
            'prices' => [
                Plan::PERIOD_YEARLY => 200,
            ],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'capacity_limit' => null,
            'sell' => 1,
            'device_limit' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }
}
