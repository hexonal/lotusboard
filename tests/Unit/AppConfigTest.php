<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Client\AppController;
use App\Models\User;
use App\Services\ServerService;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class AppConfigTest extends TestCase
{
    private $app;
    private $root;
    private $servers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/lotus-app-config-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/resources/rules', 0700, true);
        $this->app = new Application($this->root);
        $this->app->instance('config', new Repository(['v2board' => ['app_name' => 'Panel: "App"']]));
        $this->app->instance('files', new Filesystem());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        $this->servers = Mockery::mock(ServerService::class);
        $this->app->instance(ServerService::class, $this->servers);
        $response = Mockery::mock(ResponseFactory::class);
        $response->shouldReceive('make')->andReturnUsing(function ($body, $status, $headers) {
            return new Response($body, $status, $headers);
        });
        $this->app->instance(ResponseFactory::class, $response);
        $this->writeTemplate('custom.app.clash.yaml');
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            (new Filesystem())->deleteDirectory($this->root);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            Application::setInstance(null);
            parent::tearDown();
        }
    }

    private function writeTemplate($name)
    {
        file_put_contents($this->root . '/resources/rules/' . $name, Yaml::dump([
            'proxies' => null,
            'proxy-groups' => [
                ['name' => '$app_name', 'type' => 'select', 'proxies' => ['Nodes']],
                ['name' => 'Nodes', 'type' => 'select', 'proxies' => null],
            ],
            'rule-providers' => ['Local' => ['type' => 'inline', 'behavior' => 'ipcidr', 'payload' => ['10.0.0.0/8']]],
            'rules' => ['RULE-SET,Local,DIRECT', 'MATCH,Nodes'],
        ], 8));
    }

    private function user(array $overrides = [])
    {
        return (new User())->setRawAttributes(array_merge([
            'uuid' => '00000000-0000-4000-8000-000000000001',
            'banned' => 0, 'u' => 0, 'd' => 0, 'transfer_enable' => 1000,
            'expired_at' => time() + 3600,
        ], $overrides));
    }

    private function server($type, array $overrides = [])
    {
        return array_merge([
            'type' => $type, 'name' => $type . '-node', 'host' => '192.0.2.1',
            'port' => 443, 'tls' => 2, 'flow' => 'xtls-rprx-vision',
            'network' => 'tcp', 'network_settings' => [],
            'tls_settings' => [
                'server_name' => 'www.apple.com', 'public_key' => 'test-public-key',
                'short_id' => '1234567812345678', 'fingerprint' => 'chrome',
                'private_key' => 'must-not-be-emitted',
            ],
        ], $overrides);
    }

    private function getConfig(User $user)
    {
        $request = Request::create('/api/v1/client/app/getConfig');
        $request->merge(['user' => $user]);
        $response = (new AppController())->getConfig($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/yaml', $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('must-not-be-emitted', $response->getContent());
        return Yaml::parse($response->getContent());
    }

    /** @dataProvider vlessTypes */
    public function testVlessRealityNodesAreIncludedInConfigAndGroups($type, $protocol)
    {
        $user = $this->user();
        $this->servers->shouldReceive('getAvailableServers')->once()->with($user)
            ->andReturn([$this->server($type, ['protocol' => $protocol])]);

        $config = $this->getConfig($user);
        $this->assertCount(1, $config['proxies']);
        $node = $config['proxies'][0];
        $this->assertSame('vless', $node['type']);
        $this->assertSame($user->uuid, $node['uuid']);
        $this->assertTrue($node['tls']);
        $this->assertSame('xtls-rprx-vision', $node['flow']);
        $this->assertSame('www.apple.com', $node['servername']);
        $this->assertSame('chrome', $node['client-fingerprint']);
        $this->assertSame(['public-key' => 'test-public-key', 'short-id' => '1234567812345678'], $node['reality-opts']);
        $this->assertContains($node['name'], $config['proxy-groups'][0]['proxies']);
        $this->assertSame([$node['name']], $config['proxy-groups'][1]['proxies']);
        $this->assertSame('Panel: "App"', $config['proxy-groups'][0]['name']);
        $this->assertSame(['RULE-SET,Local,DIRECT', 'MATCH,Nodes'], $config['rules']);
        $this->assertArrayHasKey('Local', $config['rule-providers']);
    }

    public function vlessTypes(): array
    {
        return [['vless', null], ['v2node', 'vless']];
    }

    public function testExistingProtocolsAndUnsupportedCipherFilteringArePreserved()
    {
        $user = $this->user();
        $this->servers->shouldReceive('getAvailableServers')->once()->with($user)->andReturn([
            $this->server('shadowsocks', ['cipher' => 'aes-128-gcm']),
            $this->server('vmess', ['tls' => 0]),
            $this->server('trojan'),
            $this->server('v2node', ['protocol' => 'vmess', 'tls' => 0]),
            $this->server('shadowsocks', ['name' => 'unsupported-ss', 'cipher' => 'unsupported']),
        ]);
        $config = $this->getConfig($user);
        $this->assertSame(['ss', 'vmess', 'trojan', 'vmess'], array_column($config['proxies'], 'type'));
        $this->assertNotContains('unsupported-ss', $config['proxy-groups'][1]['proxies']);
    }

    /** @dataProvider unavailableUsers */
    public function testIneligibleUsersCannotReceiveNodes(array $overrides)
    {
        $this->servers->shouldNotReceive('getAvailableServers');
        $config = $this->getConfig($this->user($overrides));
        $this->assertSame([], $config['proxies']);
        $this->assertSame([], $config['proxy-groups'][1]['proxies']);
    }

    public function unavailableUsers(): array
    {
        return [
            [['banned' => 1]],
            [['expired_at' => 1]],
            [['u' => 600, 'd' => 400]],
        ];
    }

    public function testDefaultTemplateFallbackSupportsVless()
    {
        unlink($this->root . '/resources/rules/custom.app.clash.yaml');
        $this->writeTemplate('app.clash.yaml');
        $user = $this->user();
        $this->servers->shouldReceive('getAvailableServers')->once()->with($user)
            ->andReturn([$this->server('v2node', ['protocol' => 'vless'])]);
        $config = $this->getConfig($user);
        $this->assertSame('vless', $config['proxies'][0]['type']);
    }
}
