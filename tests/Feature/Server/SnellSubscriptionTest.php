<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\SubscribeTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SnellSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The panel reads templates/fragments through the redis store; in tests
        // the array driver keeps everything in-process.
        config(['cache.stores.redis' => ['driver' => 'array']]);
        \Cache::forgetDriver('redis');
    }

    public function test_snell_server_is_a_valid_type(): void
    {
        $this->assertTrue(Server::isValidType('snell'));
        $this->assertContains('snell', Server::VALID_TYPES);
    }

    public function test_admin_can_save_snell_server(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $response = $this->postJson($this->adminUrl('server/manage/save'), [
            'name' => 'snell-node',
            'type' => 'snell',
            'host' => 'node.example.com',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'group_ids' => [1],
            'protocol_settings' => [
                'psk' => 'a-server-wide-psk-value',
                'version' => 6,
                'mode' => 'unshaped',
            ],
        ]);

        $response->assertStatus(200);
        $server = Server::where('name', 'snell-node')->firstOrFail();
        $this->assertSame('snell', $server->type);
        $this->assertSame(6, $server->protocol_settings['version']);
        $this->assertSame('unshaped', $server->protocol_settings['mode']);
    }

    public function test_admin_save_rejects_invalid_snell_settings(): void
    {
        Sanctum::actingAs($this->makeAdmin());

        $payload = [
            'name' => 'bad-snell-node',
            'type' => 'snell',
            'host' => 'node.example.com',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'group_ids' => [1],
        ];

        $this->postJson($this->adminUrl('server/manage/save'), $payload + [
            'protocol_settings' => ['version' => 5],
        ])->assertStatus(422);

        $this->postJson($this->adminUrl('server/manage/save'), $payload + [
            'protocol_settings' => ['mode' => 'turbo'],
        ])->assertStatus(422);

        $this->postJson($this->adminUrl('server/manage/save'), $payload + [
            'protocol_settings' => ['psk' => 'short'],
        ])->assertStatus(422);
    }

    public function test_surge_subscription_renders_snell_line_with_user_key(): void
    {
        SubscribeTemplate::setContent('surge', "proxies:\n\$proxies\n");

        $user = $this->makeUser();
        $server = Server::create([
            'name' => 'snell-node',
            'type' => Server::TYPE_SNELL,
            'host' => 'node.example.com',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [(string) $user->group_id],
            'show' => true,
            'enabled' => true,
            'protocol_settings' => [
                'psk' => 'a-server-wide-psk-value',
                'version' => 6,
                'mode' => 'default',
            ],
        ]);

        $response = $this->get('/s/' . $user->token . '?flag=surge');

        $response->assertOk();
        $body = $response->getContent();
        // Per-user Snell credential: the panel hands out the user uuid as the
        // psk, which the node maps back to the user.
        $this->assertStringContainsString(
            "snell-node = snell,node.example.com,443,psk={$user->uuid},version=6,mode=default,tfo=true,udp-relay=true",
            $body
        );
    }

    public function test_surge_subscription_honours_snell_version_and_mode(): void
    {
        SubscribeTemplate::setContent('surge', "proxies:\n\$proxies\n");

        $user = $this->makeUser();
        Server::create([
            'name' => 'snell-node',
            'type' => Server::TYPE_SNELL,
            'host' => 'node.example.com',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [(string) $user->group_id],
            'show' => true,
            'enabled' => true,
            'protocol_settings' => [
                'psk' => 'a-server-wide-psk-value',
                'mode' => 'unsafe-raw',
            ],
        ]);

        $body = $this->get('/s/' . $user->token . '?flag=surge')->assertOk()->getContent();

        // version defaults to 6 when the panel did not store one.
        $this->assertStringContainsString("version=6,mode=unsafe-raw", $body);
    }

    private function adminUrl(string $path): string
    {
        $prefix = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        return "/api/v2/{$prefix}/{$path}";
    }

    private function makeAdmin(): User
    {
        return User::create(array_merge($this->userAttributes(), ['is_admin' => 1, 'is_staff' => 0]));
    }

    private function makeUser(): User
    {
        return User::create(array_merge($this->userAttributes(), [
            'email' => 'snell-' . uniqid() . '@example.com',
            'token' => bin2hex(random_bytes(16)),
            'uuid' => '11111111-2222-3333-4444-' . str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'group_id' => 1,
            'transfer_enable' => 100 * 1024 * 1024 * 1024,
            'expired_at' => time() + 86400,
        ]));
    }

    private function userAttributes(): array
    {
        return [
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
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ];
    }
}
