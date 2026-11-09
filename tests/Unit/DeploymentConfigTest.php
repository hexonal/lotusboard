<?php

namespace Tests\Unit;

use App\Utils\ConfigCache;
use App\Services\ThemeService;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;

class DeploymentConfigTest extends TestCase
{
    private $root;
    private $previous;

    protected function setUp(): void
    {
        $this->previous = getenv('APP_DISABLE_CONFIG_CACHE');
        $this->root = sys_get_temp_dir() . '/lotus-config-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/bootstrap/cache', 0700, true);
        mkdir($this->root . '/config', 0700, true);
        file_put_contents($this->root . '/config/app.php', '<?php return ["env" => "testing"];');
        file_put_contents($this->root . '/bootstrap/cache/config.php', '<?php return ["v2board" => ["app_name" => "stale"]];');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        putenv($this->previous === false ? 'APP_DISABLE_CONFIG_CACHE' : 'APP_DISABLE_CONFIG_CACHE=' . $this->previous);
        (new Filesystem())->deleteDirectory($this->root);
        Application::setInstance(null);
        parent::tearDown();
    }

    public function testBlueGreenBootReadsUpdatedSettingsDespiteAnExistingConfigCache(): void
    {
        putenv('APP_DISABLE_CONFIG_CACHE=true');
        Artisan::shouldReceive('call')->twice()->with('config:clear')->andReturnUsing(function () {
            unlink($this->root . '/bootstrap/cache/config.php');
            return 0;
        });
        foreach (['first', 'updated'] as $value) {
            file_put_contents($this->root . '/bootstrap/cache/config.php', '<?php return ["v2board" => ["app_name" => "stale"]];');
            file_put_contents($this->root . '/config/v2board.php', '<?php return ["app_name" => "' . $value . '"];');
            ConfigCache::refresh();
            $app = new Application($this->root);
            (new LoadConfiguration())->bootstrap($app);
            $this->assertFalse($app->configurationIsCached());
            $this->assertSame($value, $app['config']->get('v2board.app_name'));
        }
    }

    public function testStandardDeploymentStillUsesItsConfigCache(): void
    {
        putenv('APP_DISABLE_CONFIG_CACHE=false');
        Artisan::shouldReceive('call')->once()->with('config:cache')->andReturn(0);
        ConfigCache::refresh();
        $app = new Application($this->root);
        (new LoadConfiguration())->bootstrap($app);
        $this->assertTrue($app->configurationIsCached());
        $this->assertSame('stale', $app['config']->get('v2board.app_name'));
    }

    public function testNewThemeInitializationUpdatesTheCurrentRequestWithoutWaitingForACache(): void
    {
        putenv('APP_DISABLE_CONFIG_CACHE=true');
        mkdir($this->root . '/config/theme');
        mkdir($this->root . '/public/theme/new-theme', 0700, true);
        file_put_contents($this->root . '/public/theme/new-theme/config.json', json_encode([
            'configs' => [['field_name' => 'title', 'default_value' => 'New theme']],
        ]));
        $app = new Application($this->root);
        $app->instance('config', new Repository());
        $app->instance('files', new Filesystem());
        Facade::setFacadeApplication($app);
        Artisan::swap(Mockery::mock());
        Artisan::shouldReceive('call')->once()->with('config:clear')->andReturn(0);

        (new ThemeService('new-theme'))->init();

        $this->assertSame('New theme', $app['config']->get('theme.new-theme.title'));
        $saved = require $this->root . '/config/theme/new-theme.php';
        $this->assertSame(['title' => 'New theme'], $saved);
    }
}
