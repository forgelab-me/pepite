<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FeedModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Visibility: a public feed needs no credentials at all, a private one
 * demands HTTP Basic with an API key as the password. PLAN.md 9.4.
 *
 * @internal
 */
final class FeedReadTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('feedResolver');
    }

    public function testAPublicFeedNeedsNoCredentials(): void
    {
        $this->call('get', 'feeds/default/v3/index.json')->assertOK();
    }

    public function testAPrivateFeedRefusesAnAnonymousReadWith401AndAChallenge(): void
    {
        $this->makePrivate('default');

        $result = $this->call('get', 'feeds/default/v3/index.json');

        $result->assertStatus(401);
        $this->assertStringContainsString('Basic', $result->response()->getHeaderLine('WWW-Authenticate'));
    }

    public function testAPrivateFeedRefusesAnInvalidKey(): void
    {
        $this->makePrivate('default');

        $result = $this->withHeaders(['Authorization' => 'Basic ' . base64_encode('anyone:not-a-real-key')])
            ->call('get', 'feeds/default/v3/index.json');

        $result->assertStatus(401);
    }

    public function testAPrivateFeedAcceptsAValidKeyRegardlessOfUsername(): void
    {
        $this->makePrivate('default');
        $key = $this->issueReadKey();

        // The username is not checked at all; "anyone" is deliberate.
        $result = $this->withHeaders(['Authorization' => 'Basic ' . base64_encode('anyone:' . $key)])
            ->call('get', 'feeds/default/v3/index.json');

        $result->assertOK();
    }

    public function testAKeyWithoutTheReadScopeIsRefusedWith403(): void
    {
        $this->makePrivate('default');

        $user  = $this->createUser('scopeless@pepite.test');
        $token = $user->generateAccessToken('test', ['packages.push'])->raw_token;

        $result = $this->withHeaders(['Authorization' => 'Basic ' . base64_encode('x:' . $token)])
            ->call('get', 'feeds/default/v3/index.json');

        $result->assertStatus(403);
    }

    public function testAMalformedAuthorizationHeaderIsTreatedAsMissing(): void
    {
        $this->makePrivate('default');

        $result = $this->withHeaders(['Authorization' => 'Bearer something'])
            ->call('get', 'feeds/default/v3/index.json');

        $result->assertStatus(401);
    }

    /**
     * The filter must not swallow an unknown feed into a private-feed
     * challenge: 404 is the controller's call, not this filter's.
     */
    public function testAnUnknownFeedIsUntouchedByTheFilter(): void
    {
        $this->call('get', 'feeds/nope/v3/index.json')->assertStatus(404);
    }

    private function makePrivate(string $slug): void
    {
        model(FeedModel::class)->where('slug', $slug)->set('visibility', 'private')->update();
    }

    private function issueReadKey(): string
    {
        return $this->createUser('reader@pepite.test')
            ->generateAccessToken('test', ['packages.read'])
            ->raw_token;
    }

    private function createUser(string $email): User
    {
        $users = model(UserModel::class);

        $users->save(new User([
            'username' => explode('@', $email)[0],
            'email'    => $email,
            'password' => 'pepite-test-2026',
        ]));

        return $users->findById($users->getInsertID());
    }
}
