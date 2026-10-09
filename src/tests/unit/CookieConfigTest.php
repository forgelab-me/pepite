<?php

declare(strict_types=1);

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Cookie;

/**
 * @internal
 */
final class CookieConfigTest extends CIUnitTestCase
{
    public function testAnHttpsInstanceMarksItsCookiesSecure(): void
    {
        $this->assertTrue($this->secureFor('https://nuget.example.test/'));
        $this->assertTrue($this->secureFor('HTTPS://nuget.example.test/'));
    }

    /**
     * Secure on a plain-HTTP base URL would make the browser discard the
     * session cookie: nobody could log in to a local development server.
     */
    public function testAPlainHttpInstanceIsLeftAlone(): void
    {
        $this->assertFalse($this->secureFor('http://localhost:8080/'));
    }

    private function secureFor(string $baseUrl): bool
    {
        $app      = config('App');
        $original = $app->baseURL;

        try {
            $app->baseURL = $baseUrl;

            return (new Cookie())->secure;
        } finally {
            $app->baseURL = $original;
        }
    }
}
