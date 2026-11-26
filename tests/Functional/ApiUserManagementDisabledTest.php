<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * .env.test turns user management over the API on. These tests turn it off before the kernel
 * boots, which is what an install gets unless it sets API_USER_MANAGEMENT_ENABLED=true.
 */
class ApiUserManagementDisabledTest extends WebTestCase
{
    private ?string $previous = null;

    protected function setUp(): void
    {
        $this->previous = $_ENV['API_USER_MANAGEMENT_ENABLED'] ?? null;
        $_ENV['API_USER_MANAGEMENT_ENABLED'] = $_SERVER['API_USER_MANAGEMENT_ENABLED'] = 'false';
    }

    protected function tearDown(): void
    {
        $_ENV['API_USER_MANAGEMENT_ENABLED'] = $_SERVER['API_USER_MANAGEMENT_ENABLED'] = $this->previous;
        parent::tearDown();
    }

    private function headers(): array
    {
        return ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_DAVIS_API_TOKEN' => $_ENV['API_KEY']];
    }

    private function userId(string $username): int
    {
        return static::getContainer()->get('doctrine.orm.entity_manager')
            ->getRepository(User::class)->findOneByUsername($username)->getId();
    }

    public function testTheEndpointsAnswerNotFound(): void
    {
        $client = static::createClient();
        $userId = $this->userId('test_user2');

        $requests = [
            ['POST', '/api/v1/users/create', '{"username":"jdoe","displayname":"John Doe","email":"jdoe@example.org"}'],
            ['DELETE', '/api/v1/users/'.$userId, null],
        ];

        foreach ($requests as [$method, $url, $body]) {
            $client->request($method, $url, [], [], $this->headers(), $body);

            $this->assertResponseStatusCodeSame(404, $method.' '.$url);
            $this->assertResponseHeaderSame('Content-Type', 'application/json');

            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame('error', $data['status']);
            $this->assertSame('User management over the API is disabled', $data['message']);
        }

        $users = static::getContainer()->get('doctrine.orm.entity_manager')->getRepository(User::class);
        $this->assertNull($users->findOneByUsername('jdoe'));
        $this->assertNotNull($users->findOneByUsername('test_user2'));
    }

    /**
     * Without the API key the answer is the same whether the endpoints are on or off, so the
     * setting cannot be probed from outside.
     */
    public function testWithoutTheApiKeyTheAnswerDoesNotTellIfTheyAreOn(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/users/create', [], [], ['HTTP_ACCEPT' => 'application/json'], '{}');
        $this->assertResponseStatusCodeSame(401);

        $client->request('DELETE', '/api/v1/users/1', [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testTheOtherEndpointsKeepWorking(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/users', [], [], $this->headers());
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/api/v1/calendars/'.$this->userId('test_user2'), [], [], $this->headers());
        $this->assertResponseIsSuccessful();
    }
}
