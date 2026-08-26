<?php

declare(strict_types=1);

namespace Tests\Unit\Filters;

use App\Filters\RateLimit;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RateLimitTest extends CIUnitTestCase
{
    // RFC 5737 TEST-NET-3 — reserved for documentation, never a real caller,
    // so a leftover bucket from a previous run can't collide with anything.
    private const IP = '203.0.113.77';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['push', 'token', ''] as $preset) {
            service('cache')->delete('throttler_ratelimit_' . $preset . '_' . md5(self::IP));
        }
    }

    public function testRequestsUnderCapacityPassThrough(): void
    {
        $filter  = new RateLimit();
        $request = $this->fakeRequest();

        for ($i = 0; $i < 60; $i++) {
            $this->assertNull($filter->before($request, ['push']));
        }
    }

    public function testTheRequestAfterCapacityIsRefused(): void
    {
        $filter  = new RateLimit();
        $request = $this->fakeRequest();

        for ($i = 0; $i < 60; $i++) {
            $filter->before($request, ['push']);
        }

        $result = $filter->before($request, ['push']);

        $this->assertInstanceOf(ResponseInterface::class, $result);
        $this->assertSame(429, $result->getStatusCode());
    }

    public function testDifferentPresetsHaveIndependentBuckets(): void
    {
        $filter  = new RateLimit();
        $request = $this->fakeRequest();

        for ($i = 0; $i < 60; $i++) {
            $filter->before($request, ['push']);
        }

        // 'push' is exhausted, but 'token' (a different bucket, same IP)
        // hasn't been touched yet.
        $this->assertNull($filter->before($request, ['token']));
    }

    public function testUnknownPresetFallsBackToADefaultCapacityInsteadOfBeingUnthrottled(): void
    {
        $filter  = new RateLimit();
        $request = $this->fakeRequest();

        for ($i = 0; $i < 30; $i++) {
            $this->assertNull($filter->before($request, ['does-not-exist']));
        }

        $this->assertInstanceOf(ResponseInterface::class, $filter->before($request, ['does-not-exist']));
    }

    private function fakeRequest(): RequestInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getIPAddress')->willReturn(self::IP);

        return $request;
    }
}
