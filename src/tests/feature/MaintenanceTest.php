<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Forgelabme\Ci4Updater\Libraries\MaintenanceWindow;

/**
 * The maintenance window ci4-updater holds open while applying a release —
 * wired globally in Config\Filters, exempting the update panel so an admin
 * stuck mid-update can still reach it. What content a request sees during
 * that window is app/Views/maintenance.php: JSON for a NuGet client, a plain
 * page for everyone else. This is the wiring; whether the window opens and
 * closes around the right span of an actual apply is ci4-updater's own test
 * suite to prove, not this one's.
 *
 * @internal
 */
final class MaintenanceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;

    protected function tearDown(): void
    {
        // A window is a file in writable/ — leaving one open would fail
        // every other feature test that happens to run after this one.
        MaintenanceWindow::close();

        parent::tearDown();
    }

    public function testNuGetProtocolRoutesGetAJsonRefusalWhileOpen(): void
    {
        MaintenanceWindow::open('test', 60);

        $result = $this->call('get', 'feeds/nope/v3/index.json');

        $result->assertStatus(503);
        $result->assertHeader('Retry-After');
        $this->assertStringContainsString('application/json', $result->response()->getHeaderLine('Content-Type'));

        // In dev/testing the debug toolbar filter wraps every view()-rendered
        // body in an HTML comment (View::render(), gated on ENVIRONMENT !==
        // 'production' — never happens in production, where nothing here
        // reaches a NuGet client's json_decode). Strip it before decoding.
        $body = preg_replace('/<!--.*?-->/s', '', (string) $result->response()->getBody());
        $json = json_decode(trim((string) $body), true);
        $this->assertSame('A release is being applied. Try again shortly.', $json['error']);
    }

    public function testWebBrowsingGetsAPlainPageWhileOpen(): void
    {
        MaintenanceWindow::open('test', 60);

        $result = $this->call('get', '/');

        $result->assertStatus(503);
        $this->assertStringContainsString('Updating', (string) $result->response()->getBody());
        $this->assertStringNotContainsString('"error"', (string) $result->response()->getBody());
    }

    public function testTheUpdatePanelItselfIsExemptSoAnAdminCanStillReachIt(): void
    {
        MaintenanceWindow::open('test', 60);

        // No session either, so this redirects to login rather than 200 —
        // the point is only that it isn't the maintenance 503.
        $result = $this->call('get', 'admin/updates');

        $result->assertStatus(302);
    }

    public function testNothingIs503dWhenNoWindowIsOpen(): void
    {
        $this->call('get', 'feeds/nope/v3/index.json')->assertStatus(404);
        $this->call('get', '/')->assertStatus(200);
    }
}
