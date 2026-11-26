<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppKey;
use App\Entity\User;
use App\Security\AdminUser;
use App\Services\BasicAuth;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * .env.test turns app keys on. These tests turn them off before the kernel boots.
 */
class AppKeysDisabledTest extends WebTestCase
{
    private ?string $previous = null;

    protected function setUp(): void
    {
        $this->previous = $_ENV['APP_KEYS_ENABLED'] ?? null;
        $_ENV['APP_KEYS_ENABLED'] = $_SERVER['APP_KEYS_ENABLED'] = 'false';
    }

    protected function tearDown(): void
    {
        $_ENV['APP_KEYS_ENABLED'] = $_SERVER['APP_KEYS_ENABLED'] = $this->previous;
        parent::tearDown();
    }

    public function testThePagesAreNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser(new AdminUser('admin', 'test'));

        foreach (['/app-keys/', '/app-keys/new'] as $url) {
            $client->request('GET', $url);
            $this->assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testTheMenuHasNoLink(): void
    {
        $client = static::createClient();
        $client->loginUser(new AdminUser('admin', 'test'));
        $client->request('GET', '/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('a[href="/app-keys/"]');
    }

    public function testTheCommandsAreNotAvailable(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        foreach (['davis:app-key:create', 'davis:app-key:list', 'davis:app-key:revoke'] as $name) {
            try {
                $application->find($name);
                $this->fail($name.' must not be available');
            } catch (CommandNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * The API endpoints for the app keys of a user need app keys to be on, even when user
     * management over the API is (.env.test turns that on). The rest of it keeps working.
     */
    public function testTheApiEndpointsAreNotFound(): void
    {
        $client = static::createClient();
        $headers = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_DAVIS_API_TOKEN' => $_ENV['API_KEY']];

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $userId = $em->getRepository(User::class)->findOneByUsername('test_user2')->getId();

        $requests = [
            ['/api/v1/users/'.$userId.'/app_keys/create', '{"name":"Phone"}'],
            ['/api/v1/users/'.$userId.'/app_keys/app_test/revoke', null],
        ];
        foreach ($requests as [$url, $body]) {
            $client->request('POST', $url, [], [], $headers, $body);

            $this->assertResponseStatusCodeSame(404, $url);
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame('App keys are disabled', $data['message']);
        }

        $this->assertCount(2, $em->getRepository(AppKey::class)->findAll(), 'No key was created');

        $client->request('POST', '/api/v1/users/create', [], [], $headers, '{"username":"jdoe","displayname":"John Doe","email":"jdoe@example.org"}');
        $this->assertResponseIsSuccessful();

        $client->request('DELETE', '/api/v1/users/'.$userId, [], [], $headers);
        $this->assertResponseIsSuccessful();
    }

    public function testAKeyDoesNotLogIn(): void
    {
        self::bootKernel();
        $request = new Request('PROPFIND', '/dav/', ['Authorization' => 'Basic '.base64_encode('test_user2*app_test:app-secret')]);

        // The fixtures have an active `app_test` key. With app keys off, the whole username goes
        // to the backend, and there is no user with that name.
        [$ok] = static::getContainer()->get(BasicAuth::class)->check($request, new Response());

        $this->assertFalse($ok);
    }
}
