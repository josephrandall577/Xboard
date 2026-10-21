<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\SubscribeTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SingBoxSnellSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The panel reads templates/fragments through the redis store; in tests
        // the array driver keeps everything in-process.
        config(['cache.stores.redis' => ['driver' => 'array']]);
        \Cache::forgetDriver('redis');

        SubscribeTemplate::setContent('singbox', json_encode([
            'outbounds' => [
                ['type' => 'selector', 'tag' => 'proxy', 'outbounds' => ['direct']],
                ['type' => 'direct', 'tag' => 'direct'],
            ],
            'route' => ['rules' => []],
        ]));
    }

    public function test_singbox_1_14_subscription_renders_snell_v6_outbound(): void
    {
        $user = $this->makeUser();
        $this->makeSnellServer($user);

        $response = $this->get('/s/' . $user->token . '?flag=sing-box/1.14.0');

        $response->assertOk();
        $outbounds = $response->json('outbounds');
        $snell = $this->findOutbound($outbounds, 'snell-node');

        $this->assertNotNull($snell, 'snell outbound should be present');
        $this->assertSame('snell', $snell['type']);
        $this->assertSame('node.example.com', $snell['server']);
        $this->assertSame(443, $snell['server_port']);
        $this->assertSame(6, $snell['version']);
        // Per-user credential: the user uuid travels in the psk slot, same as
        // the Surge subscription line.
        $this->assertSame($user->uuid, $snell['psk']);
        $this->assertSame('default', $snell['mode']);

        $selector = $this->findOutbound($outbounds, 'proxy');
        $this->assertContains('snell-node', $selector['outbounds']);
    }

    public function test_singbox_subscription_honours_snell_mode(): void
    {
        $user = $this->makeUser();
        $server = $this->makeSnellServer($user);
        $server->protocol_settings = array_merge($server->protocol_settings, ['mode' => 'unsafe-raw']);
        $server->save();

        $body = $this->get('/s/' . $user->token . '?flag=sing-box/1.14.0')->assertOk()->json();
        $snell = $this->findOutbound($body['outbounds'], 'snell-node');

        $this->assertSame('unsafe-raw', $snell['mode']);
    }

    public function test_singbox_older_than_1_14_does_not_receive_snell_node(): void
    {
        $user = $this->makeUser();
        $this->makeSnellServer($user);

        $outbounds = $this->get('/s/' . $user->token . '?flag=sing-box/1.13.0')
            ->assertOk()
            ->json('outbounds');

        $this->assertNull($this->findOutbound($outbounds, 'snell-node'));
    }

    public function test_hiddify_does_not_receive_snell_as_its_core_is_assumed_pre_1_14(): void
    {
        $user = $this->makeUser();
        $this->makeSnellServer($user);

        $outbounds = $this->get('/s/' . $user->token . '?flag=hiddify/2.5.0')
            ->assertOk()
            ->json('outbounds');

        $this->assertNull($this->findOutbound($outbounds, 'snell-node'));
    }

    private function findOutbound(array $outbounds, string $tag): ?array
    {
        foreach ($outbounds as $outbound) {
            if (($outbound['tag'] ?? null) === $tag) {
                return $outbound;
            }
        }
        return null;
    }

    private function makeSnellServer(User $user, array $settings = []): Server
    {
        return Server::create([
            'name' => 'snell-node',
            'type' => Server::TYPE_SNELL,
            'host' => 'node.example.com',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [(string) $user->group_id],
            'show' => true,
            'enabled' => true,
            'protocol_settings' => array_merge([
                'psk' => 'a-server-wide-psk-value',
                'version' => 6,
                'mode' => 'default',
            ], $settings),
        ]);
    }

    private function makeUser(): User
    {
        return User::create([
            'email' => 'singbox-snell-' . uniqid() . '@example.com',
            'password' => 'password',
            'uuid' => '11111111-2222-3333-4444-' . str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 100 * 1024 * 1024 * 1024,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'group_id' => 1,
            'expired_at' => time() + 86400,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
