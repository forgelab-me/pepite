<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FeedModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;

/**
 * `spark pepite:feed` — the only way to get a second feed before the admin
 * console exists (lot 6).
 *
 * @internal
 */
final class ManageFeedCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;

    public function testCreatesAPublicFeedByDefault(): void
    {
        command('pepite:feed create acme');

        $feed = model(FeedModel::class)->findBySlug('acme');

        $this->assertNotNull($feed);
        $this->assertSame('public', $feed['visibility']);
        $this->assertSame(1, (int) $feed['allow_new_packages']);
    }

    public function testCreatesAPrivateFeedWithRestrictions(): void
    {
        command('pepite:feed create internal -n "Internal libs" --private --no-new-packages --package-types Dependency,ConsoleApp');

        $feed = model(FeedModel::class)->findBySlug('internal');

        $this->assertSame('Internal libs', $feed['name']);
        $this->assertSame('private', $feed['visibility']);
        $this->assertSame(0, (int) $feed['allow_new_packages']);
        $this->assertSame(['Dependency', 'ConsoleApp'], json_decode($feed['allowed_package_types'], true));
    }

    public function testRefusesADuplicateSlug(): void
    {
        command('pepite:feed create dup');
        command('pepite:feed create dup');

        $this->assertSame(2, model(FeedModel::class)->countAllResults());
    }

    public function testListShowsEveryFeed(): void
    {
        command('pepite:feed create alpha');
        $this->resetStreamFilterBuffer();

        command('pepite:feed list');
        $output = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('default', $output);
        $this->assertStringContainsString('alpha', $output);
    }
}
