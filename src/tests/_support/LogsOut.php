<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Attributes\After;

/**
 * For any test that logs a user in with AuthenticationTesting::actingAs().
 *
 * actingAs() signs a real session in through Shield's Session authenticator.
 * DatabaseTestTrait's $refresh wipes the database between tests, not that
 * session, so a dangling login would otherwise leak into whichever test class
 * runs next in the same process — and make a "guest is redirected" test pass
 * or fail depending on the order the files happen to run in.
 */
trait LogsOut
{
    #[After]
    protected function logOutSession(): void
    {
        auth('session')->getAuthenticator()->logout();
    }
}
