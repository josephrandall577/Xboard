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
                'mode' => 'default',
            ],
        ]);

        $response->assertStatus(200);
        $server = Server::where('name', 'snell-node')->firstOrFail();
        $this->assertSame('snell', $server->type);
        $this->assertSame(6, $server->protocol_settings['version']);
        $this->assertSame('default', $server->protocol_settings['mode']);
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

        foreach (['turbo', 'unshaped', 'unsafe-raw'] as $mode) {
            $this->postJson($this->adminUrl('server/manage/save'), $payload + [
                'protocol_settings' => ['mode' => $mode],
            ])->assertStatus(422);
        }

        $this->postJson($this->adminUrl('server/manage/save'), $payload + [
            'protocol_settings' => ['psk' => 'short'],
        ])->assertStatus(422);
    }

    public function test_surge_subscription_renders_snell_line_with_user_uuid(): void
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
        $this->assertStringNotContainsString('a-server-wide-psk-value', $body);
        $this->assertStringNotContainsString('userkey', $body);
        $this->assertStringNotContainsString('client_id', $body);
        $this->assertStringContainsString(
            "snell-node = snell,node.example.com,443,psk={$user->uuid},version=6,mode=default,tfo=true,udp-relay=true",
            $body
        );
    }

    public function test_surge_subscription_normalizes_legacy_snell_mode(): void
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

        // Legacy settings must not reach v2 subscriptions.
        $this->assertStringContainsString("version=6,mode=default", $body);
    }

    public function test_admin_save_defaults_missing_and_null_snell_settings(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        foreach ([[], ['version' => null, 'mode' => null]] as $index => $settings) {
            $this->postJson($this->adminUrl('server/manage/save'), [
                'name' => 'default-snell-' . $index,
                'type' => 'snell',
                'host' => 'node.example.com',
                'port' => 443,
                'server_port' => 6160,
                'rate' => 1,
                'protocol_settings' => $settings,
            ])->assertOk();
            $server = Server::where('name', 'default-snell-' . $index)->firstOrFail();
            $this->assertSame(6, $server->protocol_settings['version']);
            $this->assertSame('default', $server->protocol_settings['mode']);
            $this->assertNull($server->protocol_settings['psk']);
        }
    }

    public function test_cached_templates_keep_users_and_rotated_credentials_isolated(): void
    {
        SubscribeTemplate::setContent('surge', "proxies:\n\$proxies\n");
        $first = $this->makeUser();
        $second = $this->makeUser();
        Server::create([
            'name' => 'shared-endpoint', 'type' => 'snell',
            'host' => 'node.example.com', 'port' => 443, 'server_port' => 6160,
            'rate' => 1, 'group_ids' => ['1'], 'show' => true,
            'protocol_settings' => ['psk' => 'legacy-server-secret'],
        ]);
        foreach ([$first, $second, $first, $second] as $user) {
            $other = $user->id === $first->id ? $second : $first;
            $body = $this->get('/s/' . $user->token . '?flag=surge')->assertOk()->getContent();
            $this->assertStringContainsString("shared-endpoint = snell,node.example.com,443,psk={$user->uuid},version=6,mode=default", $body);
            $this->assertStringNotContainsString($other->uuid, $body);
            $this->assertStringNotContainsString('legacy-server-secret', $body);
        }
        $oldUuid = $first->uuid;
        $first->update(['uuid' => '33333333-3333-4333-8333-333333333333']);
        $body = $this->get('/s/' . $first->token . '?flag=surge')->assertOk()->getContent();
        $this->assertStringContainsString("psk={$first->uuid}", $body);
        $this->assertStringNotContainsString($oldUuid, $body);
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
