<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Libraries\Markdown;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 *
 * These tests exist to lock in the security overrides in Markdown::converter()
 * — league/commonmark's own defaults allow raw HTML and unsafe link/image
 * schemes through untouched, which is wrong for a README nobody here wrote —
 * plus a light smoke test that GFM (tables, task lists, strikethrough) is
 * actually wired up. Everything else is league/commonmark's own behavior,
 * already covered by its test suite.
 */
final class MarkdownTest extends CIUnitTestCase
{
    public function testRawScriptTagIsEscapedNotExecuted(): void
    {
        $html = Markdown::toHtml("before\n\n<script>alert(document.cookie)</script>\n\nafter");

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRawHtmlEventHandlerIsEscapedNotExecuted(): void
    {
        $html = Markdown::toHtml('<img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function testJavascriptSchemeLinkIsRefused(): void
    {
        $html = Markdown::toHtml('[click me](javascript:alert(document.cookie))');

        $this->assertStringNotContainsString('href="javascript:', $html);
    }

    public function testJavascriptSchemeImageSrcIsRefused(): void
    {
        $html = Markdown::toHtml('![x](javascript:alert(1))');

        $this->assertStringNotContainsString('src="javascript:', $html);
    }

    public function testHttpLinkStillRenders(): void
    {
        $html = Markdown::toHtml('[docs](https://example.com/readme)');

        $this->assertStringContainsString('<a href="https://example.com/readme">docs</a>', $html);
    }

    public function testRelativeAndAnchorLinksStillRender(): void
    {
        $html = Markdown::toHtml('[changelog](CHANGELOG.md) and [settings](#settings)');

        $this->assertStringContainsString('href="CHANGELOG.md"', $html);
        $this->assertStringContainsString('href="#settings"', $html);
    }

    public function testGfmTableRenders(): void
    {
        $source = <<<'MD'
            | Name | Required |
            |---|---|
            | Username | yes |
            MD;

        $html = Markdown::toHtml($source);

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th>Name</th>', $html);
        $this->assertStringContainsString('<td>Username</td>', $html);
    }

    public function testTaskListRendersCheckboxes(): void
    {
        $html = Markdown::toHtml("- [ ] todo\n- [x] done");

        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('checked', $html);
    }

    public function testStrikethroughRenders(): void
    {
        $html = Markdown::toHtml('~~deprecated~~');

        $this->assertStringContainsString('<del>deprecated</del>', $html);
    }

    public function testAutolinkRenders(): void
    {
        $html = Markdown::toHtml('See <https://example.com/x> for details.');

        $this->assertStringContainsString('<a href="https://example.com/x">https://example.com/x</a>', $html);
    }

    public function testFencedCodeBlockContentIsEscapedNotReinterpreted(): void
    {
        $html = Markdown::toHtml("```\n[not a link](javascript:alert(1))\n```");

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('<code>', $html);
    }

    public function testMixedRealisticReadmeRendersEachSectionAsItsOwnBlock(): void
    {
        $source = <<<'MD'
            # OrbitMesh.TPLinkSmartHome

            Discover and control TP-Link Kasa/Tapo devices.

            ## Settings

            | Name | Required | Description |
            |---|---|---|
            | Username | yes | TP-Link account email. |
            | Password | yes | TP-Link account password. |

            > Not currently CI-publishable.

            - one
            - two

            See [CHANGELOG.md](CHANGELOG.md) for version history.
            MD;

        $html = Markdown::toHtml($source);

        $this->assertStringContainsString('<h1>OrbitMesh.TPLinkSmartHome</h1>', $html);
        $this->assertStringContainsString('<h2>Settings</h2>', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringContainsString('href="CHANGELOG.md"', $html);
    }
}
