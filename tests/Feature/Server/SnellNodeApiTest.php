<?php

namespace Tests\Feature\Server;

use App\Jobs\TrafficFetchJob;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeRegistry;
use App\Services\NodeSyncService;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use App\WebSocket\NodeEventHandlers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;
use Workerman\Connection\TcpConnection;

class SnellNodeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['server_token' => 'snell-test-token', 'server_ws_enable' => 0]);
    }

    public function test_v2_config_describes_listener_without_shared_credentials(): void
    {
        $node = $this->makeNode();
        $this->getJson($this->nodeUrl($node, 'config'))->assertOk()->assertJson([
            'protocol' => 'snell', 'listen_ip' => '0.0.0.0',
            'server_port' => 6160, 'version' => 6, 'mode' => 'default',
        ])->assertJsonMissingPath('psk')->assertJsonMissingPath('users');
        $this->postJson('/api/v2/server/handshake', [
            'token' => 'snell-test-token', 'node_id' => $node->id,
        ])->assertOk()->assertExactJson(['websocket' => ['enabled' => false]]);
    }

    public function test_http_snapshot_keeps_uuid_limits_authorization_and_array_shape(): void
    {
        $node = $this->makeNode();
        $first = $this->makeUser();
        $second = $this->makeUser(['speed_limit' => 100, 'device_limit' => 2]);
        $this->makeUser(['banned' => 1]);
        $this->makeUser(['expired_at' => time() - 60]);
        $this->makeUser(['u' => 500, 'd' => 500]);
        $this->makeUser(['group_id' => 2]);
        $snapshot = $this->getJson($this->nodeUrl($node, 'user'))->assertOk();
        $snapshot->assertJsonCount(2, 'users');
        $this->assertSame([$first->id, $second->id], array_column($snapshot->json('users'), 'id'));
        $this->assertSame([$first->uuid, $second->uuid], array_column($snapshot->json('users'), 'uuid'));
        $this->assertSame(100, $snapshot->json('users.1.speed_limit'));
        $this->assertSame(2, $snapshot->json('users.1.device_limit'));

        HookManager::registerFilter('server.users.get', fn ($users) => $users->filter(fn ($user) => $user->id === $second->id));
        $filtered = $this->getJson($this->nodeUrl($node, 'user'))->assertOk();
        $this->assertIsArray(json_decode($filtered->getContent())->users);
        $this->assertSame($second->uuid, $filtered->json('users.0.uuid'));
        $this->assertSame([0], ServerService::getAvailableUsers($node)->keys()->all());

        $second->update(['uuid' => '33333333-3333-4333-8333-333333333333']);
        $this->getJson($this->nodeUrl($node, 'user'))->assertOk()
            ->assertJsonPath('users.0.uuid', $second->uuid);
        HookManager::remove('server.users.get');
    }

    public function test_empty_http_snapshot_is_an_explicit_array_and_etag_is_conditional(): void
    {
        $node = $this->makeNode();
        foreach ([$node, $this->makeNode(['group_ids' => []])] as $emptyNode) {
            $response = $this->getJson($this->nodeUrl($emptyNode, 'user'))
                ->assertOk()->assertExactJson(['users' => []]);
            $this->assertSame('{"users":[]}', $response->getContent());
            $this->getJson($this->nodeUrl($emptyNode, 'user'), ['If-None-Match' => $response->headers->get('ETag')])
                ->assertStatus(304);
            $this->getJson($this->nodeUrl($emptyNode, 'user'))->assertOk();
        }
    }

    public function test_invalid_plugin_result_is_not_treated_as_revoking_all_users(): void
    {
        $node = $this->makeNode();
        $this->makeUser();
        HookManager::registerFilter('server.users.get', fn ($users) => null);
        $this->expectException(\UnexpectedValueException::class);
        try {
            ServerService::getAvailableUsers($node);
        } finally {
            HookManager::remove('server.users.get');
        }
    }

    public function test_websocket_full_sync_matches_http_including_empty_revocations(): void
    {
        $node = $this->makeNode();
        $user = $this->makeUser();
        $connection = Mockery::mock(TcpConnection::class);
        $messages = [];
        $connection->shouldReceive('send')->andReturnUsing(function ($payload) use (&$messages) {
            $messages[] = json_decode($payload, true);
            return true;
        });
        NodeRegistry::add($node->id, $connection);
        try {
            foreach ([false, true] as $banned) {
                $user->update(['banned' => $banned]);
                NodeEventHandlers::pushFullSync($connection, $node);
                $message = end($messages);
                $this->assertSame('sync.users', $message['event']);
                $this->assertArrayHasKey('users', $message['data']);
                $expected = $this->getJson($this->nodeUrl($node, 'user'))->assertOk()->json('users');
                $this->assertSame($expected, $message['data']['users']);
                $this->assertTrue(array_is_list($message['data']['users']));
            }
        } finally {
            NodeRegistry::remove($node->id, $connection);
        }
    }

    public function test_user_updates_and_group_removal_push_full_snell_snapshots(): void
    {
        $node = $this->makeNode();
        $user = $this->makeUser();
        Cache::put("node_ws_alive:{$node->id}", true);
        $messages = [];
        Redis::shouldReceive('publish')->andReturnUsing(function ($channel, $payload) use (&$messages) {
            $this->assertSame('node:push', $channel);
            $messages[] = json_decode($payload, true);
            return 1;
        });
        $oldUuid = $user->uuid;
        $user->update(['uuid' => '44444444-4444-4444-8444-444444444444']);
        NodeSyncService::notifyUserChanged($user);
        $message = end($messages);
        $this->assertSame('sync.users', $message['event']);
        $this->assertSame($user->uuid, $message['data']['users'][0]['uuid']);
        $this->assertStringNotContainsString($oldUuid, json_encode($message));
        foreach ([['banned' => 1], ['banned' => 0, 'group_id' => 2]] as $changes) {
            $user->update($changes);
            if ($user->group_id === 2) {
                NodeSyncService::notifyUserRemovedFromGroup($user->id, 1);
            } else {
                NodeSyncService::notifyUserChanged($user);
            }
            $message = end($messages);
            $this->assertSame('sync.users', $message['event']);
            $this->assertSame([], $message['data']['users']);
        }
    }

    public function test_v2_reports_bill_each_user_with_the_existing_direction_and_rate(): void
    {
        Bus::fake();
        $node = $this->makeNode(['rate' => 2]);
        $first = $this->makeUser();
        $second = $this->makeUser();
        $traffic = [$first->id => [1024, 2048], $second->id => [4096, 8192]];
        $this->postJson('/api/v2/server/report', [
            'token' => 'snell-test-token', 'node_id' => $node->id, 'traffic' => $traffic,
        ])->assertOk();
        Redis::shouldReceive('sadd')->once()->with('traffic:pending_check', $first->id, $second->id);
        Bus::assertDispatched(TrafficFetchJob::class, function ($job) {
            $job->handle();
            return true;
        });
        Bus::assertDispatchedTimes(TrafficFetchJob::class, 1);
        foreach ([$first, $second] as $user) {
            $user->refresh();
            $this->assertSame($traffic[$user->id][0] * 2, $user->u);
            $this->assertSame($traffic[$user->id][1] * 2, $user->d);
        }
    }

    public function test_other_protocols_keep_their_incremental_user_sync(): void
    {
        $node = $this->makeNode(['type' => 'vmess']);
        $user = $this->makeUser(['plan_id' => 1]);
        Cache::put("node_ws_alive:{$node->id}", true);
        Redis::shouldReceive('publish')->once()->with('node:push', Mockery::on(function ($payload) use ($user) {
            $message = json_decode($payload, true);
            return $message['event'] === 'sync.user.delta'
                && $message['data']['action'] === 'add'
                && $message['data']['users'][0]['uuid'] === $user->uuid;
        }));
        NodeSyncService::notifyUserChanged($user);
    }

    public function test_migration_normalizes_only_snell_and_preserves_legacy_data(): void
    {
        $node = $this->makeNode();
        $other = $this->makeNode(['type' => 'hysteria', 'protocol_settings' => ['version' => 2]]);
        $before = $other->getRawOriginal('protocol_settings');
        $migration = require database_path('migrations/2026_10_22_000001_normalize_snell_v2_settings.php');
        foreach (['unshaped', 'unsafe-raw', null] as $mode) {
            DB::table('v2_server')->where('id', $node->id)->update([
                'protocol_settings' => json_encode(['psk' => 'legacy-server-secret', 'version' => 5, 'mode' => $mode, 'extension' => true]),
            ]);
            $migration->up();
            $migration->up();
            $settings = json_decode($node->fresh()->getRawOriginal('protocol_settings'), true);
            $this->assertSame(6, $settings['version']);
            $this->assertSame('default', $settings['mode']);
            $this->assertSame('legacy-server-secret', $settings['psk']);
            $this->assertTrue($settings['extension']);
        }
        $this->assertSame($before, $other->fresh()->getRawOriginal('protocol_settings'));
    }

    private function nodeUrl(Server $node, string $action): string
    {
        return "/api/v2/server/{$action}?token=snell-test-token&node_id={$node->id}";
    }

    private function makeNode(array $attributes = []): Server
    {
        return Server::create(array_merge([
            'name' => 'snell-node', 'type' => 'snell', 'host' => 'node.example.com',
            'port' => 443, 'server_port' => 6160, 'rate' => 1,
            'group_ids' => ['1'], 'show' => true, 'enabled' => true,
            'protocol_settings' => ['psk' => 'legacy-server-secret', 'version' => 6, 'mode' => 'unsafe-raw'],
        ], $attributes));
    }

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'email' => uniqid('snell-', true) . '@example.com', 'password' => 'password',
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'token' => bin2hex(random_bytes(16)),
            'group_id' => 1, 'transfer_enable' => 1000, 'u' => 0, 'd' => 0,
            'banned' => 0, 'expired_at' => time() + 86400,
        ], $attributes));
    }
}
