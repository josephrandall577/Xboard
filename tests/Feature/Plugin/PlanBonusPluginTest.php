<?php

namespace Tests\Feature\Plugin;

use App\Models\Plan;
use App\Models\Plugin;
use App\Models\User;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanBonusPluginTest extends TestCase
{
    use RefreshDatabase;

    private const API_BASE = '/api/plugin/plan-bonus';

    protected function setUp(): void
    {
        parent::setUp();

        // 启用插件：插件路由由全局中间件 InitializePlugins 在请求期注册
        Plugin::create([
            'name' => '套餐赠送时长管理',
            'code' => 'plan_bonus',
            'version' => '1.0.0',
            'type' => Plugin::TYPE_FEATURE,
            'is_enabled' => true,
            'config' => '{}',
            'installed_at' => now(),
        ]);

        $this->reloadPluginRoutes();
    }

    /**
     * RefreshDatabase 的 migrate:fresh 会经过控制台内核（Console\Kernel::commands），
     * 彼时插件表为空，pluginsInitialized 已被置真，HTTP 中间件会跳过初始化。
     * 因此插入启用记录后手动重置并重新注册插件路由。
     */
    private function reloadPluginRoutes(): void
    {
        $manager = app(PluginManager::class);

        foreach (['pluginsInitialized' => false, 'loadedPlugins' => []] as $property => $value) {
            $ref = new \ReflectionProperty($manager, $property);
            $ref->setAccessible(true);
            $ref->setValue($manager, $value);
        }

        $manager->initializeEnabledPlugins();
    }

    public function test_admin_can_list_plans_with_bonus_config(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $this->makePlan(['bonuses' => [Plan::PERIOD_YEARLY => 6]]);

        $response = $this->getJson(self::API_BASE . '/plans');

        $response->assertStatus(200)
            ->assertJsonPath('data.plans.0.id', fn($id) => $id > 0)
            ->assertJsonPath('data.plans.0.bonuses.yearly', 6)
            ->assertJsonStructure([
                'data' => [
                    'plans' => [
                        0 => ['id', 'name', 'prices', 'bonuses', 'show', 'sell', 'renew'],
                    ],
                    'periods',
                    'max_bonus_months',
                ],
            ]);
    }

    public function test_admin_can_save_and_clear_bonuses(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $plan = $this->makePlan();

        // 设置赠送
        $response = $this->postJson(self::API_BASE . '/save', [
            'plan_id' => $plan->id,
            'bonuses' => ['yearly' => 6, 'monthly' => 0],
        ]);
        $response->assertStatus(200);
        $this->assertSame(['yearly' => 6], $plan->refresh()->bonuses);

        // 修改赠送
        $response = $this->postJson(self::API_BASE . '/save', [
            'plan_id' => $plan->id,
            'bonuses' => ['yearly' => 3, 'half_yearly' => 1],
        ]);
        $response->assertStatus(200);
        $this->assertSame(['yearly' => 3, 'half_yearly' => 1], $plan->refresh()->bonuses);

        // 清空赠送（活动下线）
        $response = $this->postJson(self::API_BASE . '/save', [
            'plan_id' => $plan->id,
            'bonuses' => null,
        ]);
        $response->assertStatus(200);
        $this->assertNull($plan->refresh()->bonuses);
    }

    public function test_save_rejects_invalid_period_and_excessive_months(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $plan = $this->makePlan();

        // 一次性套餐不支持赠送
        $response = $this->postJson(self::API_BASE . '/save', [
            'plan_id' => $plan->id,
            'bonuses' => ['onetime' => 6],
        ]);
        $response->assertStatus(400);

        // 超过上限
        $response = $this->postJson(self::API_BASE . '/save', [
            'plan_id' => $plan->id,
            'bonuses' => ['yearly' => Plan::MAX_BONUS_MONTHS + 1],
        ]);
        $response->assertStatus(422);

        $this->assertNull($plan->refresh()->bonuses);
    }

    public function test_non_admin_cannot_access_plugin_api(): void
    {
        // 未登录
        $this->getJson(self::API_BASE . '/plans')->assertStatus(403);

        // 普通用户
        Sanctum::actingAs($this->makeAdmin(['is_admin' => 0]));
        $this->getJson(self::API_BASE . '/plans')->assertStatus(403);
    }

    public function test_manage_page_is_served(): void
    {
        $response = $this->get('/plugins/plan-bonus');
        $response->assertStatus(200);
        $this->assertStringContainsString('套餐赠送时长管理', $response->getContent());
    }

    private function makeAdmin(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'plugin-admin-' . uniqid() . '@example.com',
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
        ], $overrides));
    }

    private function makePlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'group_id' => null,
            'transfer_enable' => 1111,
            'name' => 'Plugin Bonus Plan',
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
