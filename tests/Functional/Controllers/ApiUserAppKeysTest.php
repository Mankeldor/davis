<?php

namespace App\Tests\Functional;

use App\Entity\AppKey;
use App\Entity\Principal;
use App\Entity\User;
use App\Services\BasicAuth;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Creating and revoking the app keys of a user over the API. .env.test turns both
 * API_USER_MANAGEMENT_ENABLED and APP_KEYS_ENABLED on.
 */
class ApiUserAppKeysTest extends WebTestCase
{
    /**
     * Sends an API request and returns the decoded JSON answer.
     */
    private function api(KernelBrowser $client, string $method, string $url, ?array $body = null, bool $withToken = true): array
    {
        $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        if ($withToken) {
            $headers['HTTP_X_DAVIS_API_TOKEN'] = $_ENV['API_KEY'];
        }

        $client->request($method, $url, [], [], $headers, null === $body ? null : (string) json_encode($body));

        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    private function em()
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function userId(string $username): int
    {
        return $this->em()->getRepository(User::class)->findOneByUsername($username)->getId();
    }

    /**
     * Reads the key from the database again, or null if it is not there.
     */
    private function findKey(string $appId): ?AppKey
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(AppKey::class)->findOneByAppId($appId);
    }

    private function key(string $appId): AppKey
    {
        $key = $this->findKey($appId);
        $this->assertInstanceOf(AppKey::class, $key, $appId.' is not in the database');

        return $key;
    }

    /**
     * Logs in over DAV the way a client does, and returns the principal, or null when refused.
     */
    private function logIn(string $basicUsername, string $secret): ?string
    {
        $request = new Request('PROPFIND', '/dav/', ['Authorization' => 'Basic '.base64_encode($basicUsername.':'.$secret)]);

        [$ok, $principal] = static::getContainer()->get(BasicAuth::class)->check($request, new Response());

        return $ok ? $principal : null;
    }

    private function createUrl(string $username): string
    {
        return '/api/v1/users/'.$this->userId($username).'/app_keys/create';
    }

    private function revokeUrl(string $username, string $appId): string
    {
        return '/api/v1/users/'.$this->userId($username).'/app_keys/'.$appId.'/revoke';
    }

    public function testCreateAppKey(): void
    {
        $client = static::createClient();

        $data = $this->api($client, 'POST', $this->createUrl('test_user2'), ['name' => 'Phone']);

        $this->assertResponseStatusCodeSame(200);
        $this->assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $this->assertSame('success', $data['status']);
        $this->assertArrayHasKey('timestamp', $data);

        $key = $data['data'];
        $this->assertMatchesRegularExpression('/^app_[0-9a-f]{24}$/', $key['app_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key['secret']);
        $this->assertSame('Phone', $key['name']);
        $this->assertSame('test_user2', $key['username']);
        $this->assertSame('test_user2*'.$key['app_id'], $key['basic_username']);
        $this->assertNull($key['expires_at']);

        // Only a hash of the secret is kept
        $stored = $this->key($key['app_id']);
        $this->assertSame(['test_user2'], $stored->getScope());
        $this->assertNull($stored->getRevokedAt());
        $this->assertNotSame($key['secret'], $stored->getSecretHash());
        $this->assertTrue(password_verify($key['secret'], (string) $stored->getSecretHash()));
    }

    public function testTheKeyLogsInAsThisUserAndNobodyElse(): void
    {
        $client = static::createClient();

        $this->api($client, 'POST', '/api/v1/users/create', ['username' => 'jdoe', 'displayname' => 'John Doe', 'email' => 'jdoe@example.org']);
        $this->assertResponseStatusCodeSame(200);

        $key = $this->api($client, 'POST', $this->createUrl('test_user2'), ['name' => 'Phone'])['data'];

        $this->assertSame('principals/test_user2', $this->logIn($key['basic_username'], $key['secret']));
        $this->assertNull($this->logIn($key['basic_username'], 'not-the-secret'));
        $this->assertNull($this->logIn('jdoe*'.$key['app_id'], $key['secret']), 'Another user');
        $this->assertNull($this->logIn('test_user*'.$key['app_id'], $key['secret']), 'An administrator');
    }

    /**
     * The scope is the user in the URL. Nothing in the body can widen it.
     */
    public function testTheScopeCannotBeChosen(): void
    {
        $client = static::createClient();

        foreach ([['*'], '*', ['test_user2', 'test_user'], 'test_user'] as $scope) {
            $key = $this->api($client, 'POST', $this->createUrl('test_user2'), ['name' => 'Phone', 'scope' => $scope, 'username' => 'test_user', 'all_users' => true])['data'];

            $this->assertResponseStatusCodeSame(200);
            $this->assertSame('test_user2', $key['username']);
            $this->assertSame(['test_user2'], $this->key($key['app_id'])->getScope());
        }
    }

    public function testAKeyCanExpire(): void
    {
        $client = static::createClient();

        // An offset that is unlikely to be the server's: the instant must survive the storage
        $expiresAt = (new \DateTimeImmutable('+2 days'))->setTimezone(new \DateTimeZone('+05:00'))->setTime(12, 0);

        foreach ([$expiresAt->format(\DateTimeInterface::ATOM), $expiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z')] as $value) {
            $key = $this->api($client, 'POST', $this->createUrl('test_user2'), ['name' => 'Temporary', 'expires_at' => $value])['data'];

            $this->assertResponseStatusCodeSame(200, $value);
            $this->assertSame($expiresAt->getTimestamp(), (new \DateTimeImmutable($key['expires_at']))->getTimestamp());
            $this->assertSame($expiresAt->getTimestamp(), $this->key($key['app_id'])->getRevokedAt()?->getTimestamp());

            // An expiry date in the future does not stop the key from working now
            $this->assertSame('principals/test_user2', $this->logIn($key['basic_username'], $key['secret']));
        }
    }

    public function testCreateAppKeyRejectsAnInvalidExpiryDate(): void
    {
        $client = static::createClient();
        $before = count($this->em()->getRepository(AppKey::class)->findAll());

        $values = [
            (new \DateTimeImmutable('-1 minute'))->format(\DateTimeInterface::ATOM),
            'tomorrow',
            '2099-01-01',
            '2099-01-01 00:00:00',
            '2099-02-31T00:00:00+00:00',
            '',
            42,
            ['2099-01-01T00:00:00+00:00'],
        ];
        foreach ($values as $value) {
            $data = $this->api($client, 'POST', $this->createUrl('test_user2'), ['name' => 'Phone', 'expires_at' => $value]);

            $this->assertResponseStatusCodeSame(400, var_export($value, true));
            $this->assertSame('Invalid Expiry Date', $data['message']);
        }

        $this->assertCount($before, $this->em()->getRepository(AppKey::class)->findAll(), 'Nothing was created');
    }

    public function testCreateAppKeyRejectsAnInvalidNameOrBody(): void
    {
        $client = static::createClient();
        $url = $this->createUrl('test_user2');
        $before = count($this->em()->getRepository(AppKey::class)->findAll());

        foreach (['', '   ', str_repeat('a', 256), 42, null] as $name) {
            $data = $this->api($client, 'POST', $url, ['name' => $name]);

            $this->assertResponseStatusCodeSame(400, var_export($name, true));
            $this->assertSame('Invalid Name', $data['message']);
        }

        foreach (['{not json', '"a string"', ''] as $body) {
            $client->request('POST', $url, [], [], ['HTTP_X_DAVIS_API_TOKEN' => $_ENV['API_KEY']], $body);

            $this->assertResponseStatusCodeSame(400, $body);
            $this->assertSame('Invalid JSON', json_decode((string) $client->getResponse()->getContent(), true)['message']);
        }

        $this->assertCount($before, $this->em()->getRepository(AppKey::class)->findAll(), 'Nothing was created');
    }

    public function testCreateAppKeyForAnUnknownUserIsNotFound(): void
    {
        $client = static::createClient();

        $data = $this->api($client, 'POST', '/api/v1/users/999999/app_keys/create', ['name' => 'Phone']);

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('User Not Found', $data['message']);
    }

    public function testCreateAppKeyForAUserWithoutAPrincipalIsNotFound(): void
    {
        $client = static::createClient();
        $url = $this->createUrl('test_user2');

        $em = $this->em();
        $em->remove($em->getRepository(Principal::class)->findOneByUri(Principal::PREFIX.'test_user2'));
        $em->flush();

        $data = $this->api($client, 'POST', $url, ['name' => 'Phone']);

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('Principal Not Found', $data['message']);
    }

    /**
     * An administrator can read every calendar and address book, so a key for one is only made
     * in the dashboard or the console.
     */
    public function testAnAppKeyForAnAdministratorCannotBeCreatedOverTheApi(): void
    {
        $client = static::createClient();
        $before = count($this->em()->getRepository(AppKey::class)->findAll());

        $data = $this->api($client, 'POST', $this->createUrl('test_user'), ['name' => 'Phone']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame('App Keys for Admin Users Cannot Be Created Over the API', $data['message']);
        $this->assertArrayNotHasKey('data', $data);
        $this->assertCount($before, $this->em()->getRepository(AppKey::class)->findAll(), 'Nothing was created');
    }

    /**
     * `*` in a scope means all users. An old account that is literally named `*` must not turn
     * this endpoint into a way to make such a key.
     */
    public function testAUserNamedLikeTheWildcardDoesNotGetAKeyForAllUsers(): void
    {
        $client = static::createClient();
        $before = count($this->em()->getRepository(AppKey::class)->findAll());

        $em = $this->em();
        $em->persist((new User())->setUsername('*')->setPassword('x'));
        $em->persist((new Principal())->setUri(Principal::PREFIX.'*')->setIsAdmin(false));
        $em->flush();

        $data = $this->api($client, 'POST', $this->createUrl('*'), ['name' => 'Phone']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame('App Keys for This Username Cannot Be Created Over the API', $data['message']);
        $this->assertCount($before, $this->em()->getRepository(AppKey::class)->findAll(), 'Nothing was created');
    }

    public function testRevokeAppKey(): void
    {
        $client = static::createClient();

        $key = $this->api($client, 'POST', $this->createUrl('test_user2'), ['name' => 'Phone'])['data'];
        $this->assertSame('principals/test_user2', $this->logIn($key['basic_username'], $key['secret']));

        $data = $this->api($client, 'POST', $this->revokeUrl('test_user2', $key['app_id']));

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('success', $data['status']);
        $this->assertSame($key['app_id'], $data['data']['app_id']);
        $this->assertNotNull($data['data']['revoked_at']);
        $this->assertLessThanOrEqual(time(), (new \DateTimeImmutable($data['data']['revoked_at']))->getTimestamp());

        $this->assertNull($this->logIn($key['basic_username'], $key['secret']), 'A revoked key no longer logs in');
        $this->assertNotNull($this->findKey($key['app_id']), 'Revoking does not delete the key');
    }

    public function testRevokingTwiceKeepsTheFirstDate(): void
    {
        $client = static::createClient();

        // A key of test_user2 that was revoked a day ago
        $em = $this->em();
        $key = AppKey::create('Old phone', ['test_user2'], new \DateTimeImmutable('-1 day'))->setSecretHash('x');
        $em->persist($key);
        $em->flush();
        $appId = $key->getAppId();
        $revokedAt = $this->key($appId)->getRevokedAt();
        $this->assertNotNull($revokedAt);

        foreach ([1, 2] as $attempt) {
            $data = $this->api($client, 'POST', $this->revokeUrl('test_user2', $appId));

            $this->assertResponseStatusCodeSame(200, 'Attempt '.$attempt);
            $this->assertSame($revokedAt->getTimestamp(), (new \DateTimeImmutable($data['data']['revoked_at']))->getTimestamp());
        }

        $this->assertSame($revokedAt->getTimestamp(), $this->key($appId)->getRevokedAt()?->getTimestamp());
    }

    /**
     * The API does not touch an administrator. A key that is for an administrator and nobody
     * else was made in the dashboard or on the console, and is revoked there. The answer does
     * not depend on the key, so it does not tell whether there is one.
     */
    public function testAnAppKeyOfAnAdministratorCannotBeRevokedOverTheApi(): void
    {
        $client = static::createClient();

        $em = $this->em();
        $key = AppKey::create('Admin tool', ['test_user']);
        $secret = $key->issueSecret();
        $em->persist($key);
        $em->flush();
        $appId = $key->getAppId();
        $this->assertSame('principals/test_user', $this->logIn('test_user*'.$appId, $secret));

        $cases = [
            'an active key of the administrator' => $appId,
            'a revoked key of the administrator' => 'app_revoked',
            'a key that does not exist' => 'app_000000000000000000000000',
        ];
        foreach ($cases as $case => $id) {
            $data = $this->api($client, 'POST', $this->revokeUrl('test_user', $id));

            $this->assertResponseStatusCodeSame(403, $case);
            $this->assertSame('error', $data['status'], $case);
            $this->assertSame('App Keys of Admin Users Cannot Be Revoked Over the API', $data['message'], $case);
            $this->assertArrayNotHasKey('data', $data, $case);
        }

        $this->assertNull($this->key($appId)->getRevokedAt(), 'The key is still active');
        $this->assertSame('principals/test_user', $this->logIn('test_user*'.$appId, $secret), 'and still logs in');
    }

    /**
     * A key with an expiry date ahead is active. Revoking it stops it now.
     */
    public function testRevokingAKeyThatWouldExpireLaterStopsItNow(): void
    {
        $client = static::createClient();

        $key = $this->api($client, 'POST', $this->createUrl('test_user2'), ['name' => 'Temporary', 'expires_at' => (new \DateTimeImmutable('+2 days'))->format(\DateTimeInterface::ATOM)])['data'];

        $this->api($client, 'POST', $this->revokeUrl('test_user2', $key['app_id']));
        $this->assertResponseStatusCodeSame(200);

        $this->assertLessThanOrEqual(time(), $this->key($key['app_id'])->getRevokedAt()?->getTimestamp());
        $this->assertNull($this->logIn($key['basic_username'], $key['secret']));
    }

    /**
     * Keys for all users, for several users or for somebody else are out of reach, and the
     * answer is the same as for a key that does not exist.
     */
    public function testOnlyAKeyThatIsForExactlyThisUserCanBeRevoked(): void
    {
        $client = static::createClient();

        $em = $this->em();
        $shared = AppKey::create('user 2 and bob', ['test_user2', 'bob'])->setSecretHash('x');
        $listedWithAll = AppKey::create('everyone, and user 2 by name', ['*', 'test_user2'])->setSecretHash('x');
        $em->persist($shared);
        $em->persist($listedWithAll);
        $em->flush();

        $cases = [
            'a key for all users' => 'app_test',
            'a key of another user' => 'app_revoked',
            'a key for several users' => $shared->getAppId(),
            'a key for all users that also names this one' => $listedWithAll->getAppId(),
            'a key that does not exist' => 'app_000000000000000000000000',
            'the app id in another case' => 'APP_TEST',
        ];
        foreach ($cases as $case => $appId) {
            $data = $this->api($client, 'POST', $this->revokeUrl('test_user2', $appId));

            $this->assertResponseStatusCodeSame(404, $case);
            $this->assertSame('App Key Not Found', $data['message'], $case);
        }

        $this->assertNull($this->key('app_test')->getRevokedAt(), 'The key for all users is still active');
        $this->assertNull($this->key($shared->getAppId())->getRevokedAt());
        $this->assertNull($this->key($listedWithAll->getAppId())->getRevokedAt());
        $this->assertSame('principals/test_user2', $this->logIn('test_user2*app_test', 'app-secret'));
    }

    public function testRevokeAppKeyOfAnUnknownUserIsNotFound(): void
    {
        $client = static::createClient();

        $data = $this->api($client, 'POST', '/api/v1/users/999999/app_keys/app_test/revoke');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('User Not Found', $data['message']);
    }

    public function testTheEndpointsNeedTheApiKey(): void
    {
        $client = static::createClient();
        $before = count($this->em()->getRepository(AppKey::class)->findAll());

        $data = $this->api($client, 'POST', $this->createUrl('test_user2'), ['name' => 'Phone'], false);
        $this->assertResponseStatusCodeSame(401);
        $this->assertSame('Missing X-Davis-API-Token header', $data['message']);
        $this->assertArrayNotHasKey('data', $data);

        $this->api($client, 'POST', $this->revokeUrl('test_user2', 'app_test'), null, false);
        $this->assertResponseStatusCodeSame(401);

        $this->assertCount($before, $this->em()->getRepository(AppKey::class)->findAll());
        $this->assertNull($this->key('app_test')->getRevokedAt());
    }
}
