<?php

namespace App\Tests\Functional;

use App\Entity\AddressBook;
use App\Entity\AppKey;
use App\Entity\CalendarInstance;
use App\Entity\Principal;
use App\Entity\User;
use App\Services\BasicAuth;
use Sabre\DAV\Sharing\Plugin as SharingPlugin;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Creating and deleting users over the API. .env.test turns API_USER_MANAGEMENT_ENABLED on.
 */
class ApiUserManagementTest extends WebTestCase
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

    private function passwordLogsIn(string $username, string $password): bool
    {
        $request = new Request('PROPFIND', '/dav/', ['Authorization' => 'Basic '.base64_encode($username.':'.$password)]);

        [$ok] = static::getContainer()->get(BasicAuth::class)->check($request, new Response());

        return $ok;
    }

    private const NEW_USER = ['username' => 'jdoe', 'displayname' => 'John Doe', 'email' => 'jdoe@example.org'];

    public function testCreateUser(): void
    {
        $client = static::createClient();

        $data = $this->api($client, 'POST', '/api/v1/users/create', self::NEW_USER);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('success', $data['status']);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertSame('principals/jdoe', $data['data']['uri']);
        $this->assertSame('jdoe', $data['data']['username']);
        $this->assertSame('default', $data['data']['default_calendar']['uri']);

        $userId = $data['data']['user_id'];
        $this->assertSame($this->userId('jdoe'), $userId);

        $details = $this->api($client, 'GET', '/api/v1/users/'.$userId);
        $this->assertResponseIsSuccessful();
        $this->assertSame($data['data']['principal_id'], $details['data']['principal_id']);
        $this->assertSame('John Doe', $details['data']['displayname']);
        $this->assertSame('jdoe@example.org', $details['data']['email']);

        // The id of the default calendar is the one the calendar endpoints work with
        $calendars = $this->api($client, 'GET', '/api/v1/calendars/'.$userId);
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $calendars['data']['user_calendars']);
        $this->assertSame($data['data']['default_calendar']['id'], $calendars['data']['user_calendars'][0]['id']);
        $this->assertSame('default', $calendars['data']['user_calendars'][0]['uri']);
        $this->assertSame([], $calendars['data']['shared_calendars']);
    }

    public function testACreatedUserIsARegularAccountWithoutAUsablePassword(): void
    {
        $client = static::createClient();

        // Neither an admin flag nor a password can be set over the API
        $this->api($client, 'POST', '/api/v1/users/create', self::NEW_USER + ['is_admin' => true, 'isAdmin' => true, 'password' => 'chosen-password']);
        $this->assertResponseStatusCodeSame(200);

        $em = $this->em();
        $principal = $em->getRepository(Principal::class)->findOneByUri('principals/jdoe');
        $this->assertNotNull($principal);
        $this->assertFalse($principal->getIsAdmin());
        $this->assertTrue($principal->getIsMain());

        $this->assertNotNull($em->getRepository(Principal::class)->findOneByUri('principals/jdoe'.Principal::READ_PROXY_SUFFIX));
        $this->assertNotNull($em->getRepository(Principal::class)->findOneByUri('principals/jdoe'.Principal::WRITE_PROXY_SUFFIX));
        $this->assertCount(1, $em->getRepository(AddressBook::class)->findBy(['principalUri' => 'principals/jdoe']));

        $this->assertFalse($this->passwordLogsIn('jdoe', 'chosen-password'));
        $this->assertFalse($this->passwordLogsIn('jdoe', 'jdoe'));
    }

    public function testCreateUserRejectsAnInvalidUsername(): void
    {
        $client = static::createClient();

        $usernames = ['', ' ', 'bad/user', 'bad\\user', 'two words', 'jdoe*app_test', '*', str_repeat('a', 256), 42, null, ['jdoe']];
        foreach ($usernames as $username) {
            $data = $this->api($client, 'POST', '/api/v1/users/create', ['username' => $username] + self::NEW_USER);

            $this->assertResponseStatusCodeSame(400, var_export($username, true));
            $this->assertSame('Invalid Username', $data['message']);
        }

        // A missing field is the same as an empty one
        $data = $this->api($client, 'POST', '/api/v1/users/create', ['displayname' => 'John Doe', 'email' => 'jdoe@example.org']);
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame('Invalid Username', $data['message']);

        $this->assertCount(2, $this->em()->getRepository(User::class)->findAll(), 'Nothing was created');
    }

    public function testCreateUserRejectsAnInvalidDisplayNameOrEmail(): void
    {
        $client = static::createClient();

        foreach (['', '   ', str_repeat('a', 256), 42, null] as $displayName) {
            $data = $this->api($client, 'POST', '/api/v1/users/create', ['displayname' => $displayName] + self::NEW_USER);

            $this->assertResponseStatusCodeSame(400, var_export($displayName, true));
            $this->assertSame('Invalid Display Name', $data['message']);
        }

        foreach (['', 'not-an-address', 'jdoe@', str_repeat('a', 250).'@example.org', 42, null] as $email) {
            $data = $this->api($client, 'POST', '/api/v1/users/create', ['email' => $email] + self::NEW_USER);

            $this->assertResponseStatusCodeSame(400, var_export($email, true));
            $this->assertSame('Invalid Email', $data['message']);
        }

        $this->assertCount(2, $this->em()->getRepository(User::class)->findAll(), 'Nothing was created');
    }

    public function testCreateUserRejectsInvalidJson(): void
    {
        $client = static::createClient();

        foreach (['{not json', '"a string"', ''] as $body) {
            $client->request('POST', '/api/v1/users/create', [], [], ['HTTP_X_DAVIS_API_TOKEN' => $_ENV['API_KEY']], $body);

            $this->assertResponseStatusCodeSame(400, $body);
            $this->assertSame('Invalid JSON', json_decode((string) $client->getResponse()->getContent(), true)['message']);
        }
    }

    public function testCreateUserRefusesANameThatIsTaken(): void
    {
        $client = static::createClient();

        // An exact match, and one that only differs by case: MySQL would refuse the second at the
        // unique index, PostgreSQL and SQLite would silently accept it
        foreach (['test_user2', 'Test_User2', 'TEST_USER2'] as $username) {
            $data = $this->api($client, 'POST', '/api/v1/users/create', ['username' => $username] + self::NEW_USER);

            $this->assertResponseStatusCodeSame(409, $username);
            $this->assertSame('error', $data['status']);
            $this->assertSame('User Already Exists', $data['message']);
        }

        $this->assertCount(2, $this->em()->getRepository(User::class)->findAll(), 'Nothing was created');
    }

    /**
     * An invitation finds its user by the address, so the API gives an address to one user only.
     * The message differs from the one for a username: a caller can pick another username, but
     * not another address.
     */
    public function testCreateUserRefusesAnEmailThatAnotherUserHas(): void
    {
        $client = static::createClient();

        // test2@test.com is the address of test_user2, test@test.com the one of the administrator
        foreach (['test2@test.com', 'TEST2@Test.Com', 'test@test.com'] as $email) {
            $data = $this->api($client, 'POST', '/api/v1/users/create', ['email' => $email] + self::NEW_USER);

            $this->assertResponseStatusCodeSame(409, $email);
            $this->assertSame('error', $data['status']);
            $this->assertSame('Email Already In Use', $data['message']);
        }

        $em = $this->em();
        $this->assertCount(2, $em->getRepository(User::class)->findAll(), 'Nothing was created');
        $this->assertNull($em->getRepository(Principal::class)->findOneByUri('principals/jdoe'));
    }

    public function testCreateUserNamesTheUsernameWhenUsernameAndEmailAreBothTaken(): void
    {
        $client = static::createClient();

        $data = $this->api($client, 'POST', '/api/v1/users/create', ['username' => 'test_user2', 'email' => 'test2@test.com'] + self::NEW_USER);

        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('User Already Exists', $data['message']);
    }

    /**
     * The proxy principals of a user have no address, and an address is free again once its
     * user is gone.
     */
    public function testAnEmailIsFreeAgainAfterItsUserWasDeleted(): void
    {
        $client = static::createClient();

        $this->api($client, 'DELETE', '/api/v1/users/'.$this->userId('test_user2'));
        $this->assertResponseStatusCodeSame(200);

        $data = $this->api($client, 'POST', '/api/v1/users/create', ['email' => 'test2@test.com'] + self::NEW_USER);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('jdoe', $data['data']['username']);
    }

    /**
     * A principal can be left behind without its user. A new account with that name would pick up
     * whatever still hangs off that principal.
     */
    public function testCreateUserRefusesTheNameOfAnOrphanedPrincipal(): void
    {
        $client = static::createClient();

        $em = $this->em();
        $em->persist((new Principal())->setUri(Principal::PREFIX.'ghost')->setIsAdmin(false));
        $em->persist((new Principal())->setUri(Principal::PREFIX.'phantom'.Principal::READ_PROXY_SUFFIX)->setIsMain(false)->setIsAdmin(false));
        $em->flush();

        foreach (['ghost', 'Ghost', 'phantom'] as $username) {
            $data = $this->api($client, 'POST', '/api/v1/users/create', ['username' => $username] + self::NEW_USER);

            $this->assertResponseStatusCodeSame(409, $username);
            $this->assertSame('User Already Exists', $data['message']);
        }
    }

    public function testUpdateUser(): void
    {
        $client = static::createClient();
        $userId = $this->userId('test_user2');

        $data = $this->api($client, 'PATCH', '/api/v1/users/'.$userId, ['displayname' => '  Jane Doe  ', 'email' => 'jane.doe@example.org']);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('success', $data['status']);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertSame($userId, $data['data']['user_id']);
        $this->assertSame('principals/test_user2', $data['data']['uri']);
        $this->assertSame('test_user2', $data['data']['username']);
        $this->assertSame('Jane Doe', $data['data']['displayname'], 'The display name is trimmed');
        $this->assertSame('jane.doe@example.org', $data['data']['email']);

        // What the details endpoint says afterwards is what was answered
        $details = $this->api($client, 'GET', '/api/v1/users/'.$userId);
        $this->assertResponseIsSuccessful();
        $this->assertSame($data['data'], $details['data']);

        $principal = $this->em()->getRepository(Principal::class)->findOneByUri('principals/test_user2');
        $this->assertSame('Jane Doe', $principal->getDisplayName());
        $this->assertSame('jane.doe@example.org', $principal->getEmail());
    }

    public function testUpdateUserChangesOnlyWhatIsSent(): void
    {
        $client = static::createClient();
        $userId = $this->userId('test_user2');

        $data = $this->api($client, 'PATCH', '/api/v1/users/'.$userId, ['displayname' => 'Jane Doe']);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('Jane Doe', $data['data']['displayname']);
        $this->assertSame('test2@test.com', $data['data']['email'], 'The address is as the fixtures set it');

        $data = $this->api($client, 'PATCH', '/api/v1/users/'.$userId, ['email' => 'jane.doe@example.org']);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('Jane Doe', $data['data']['displayname'], 'The name from the first request is kept');
        $this->assertSame('jane.doe@example.org', $data['data']['email']);

        // Sending what is already there is not an error
        $data = $this->api($client, 'PATCH', '/api/v1/users/'.$userId, ['displayname' => 'Jane Doe', 'email' => 'jane.doe@example.org']);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('Jane Doe', $data['data']['displayname']);
    }

    /**
     * The username is in the principal URI and in every calendar URL, and the rest would be a
     * way around the dashboard.
     */
    public function testUpdateUserChangesNothingButTheNameAndTheAddress(): void
    {
        $client = static::createClient();
        $userId = $this->userId('test_user2');
        $this->assertTrue($this->passwordLogsIn('test_user2', 'password2'));

        $data = $this->api($client, 'PATCH', '/api/v1/users/'.$userId, [
            'displayname' => 'Jane Doe',
            'username' => 'jane',
            'uri' => 'principals/jane',
            'is_admin' => true,
            'isAdmin' => true,
            'password' => 'chosen-password',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('test_user2', $data['data']['username']);
        $this->assertSame('principals/test_user2', $data['data']['uri']);

        $em = $this->em();
        $this->assertSame($userId, $this->userId('test_user2'));
        $this->assertNull($em->getRepository(User::class)->findOneByUsername('jane'));
        $this->assertNull($em->getRepository(Principal::class)->findOneByUri('principals/jane'));
        $this->assertFalse($em->getRepository(Principal::class)->findOneByUri('principals/test_user2')->getIsAdmin());
        $this->assertCount(1, $em->getRepository(CalendarInstance::class)->findBy(['principalUri' => 'principals/test_user2']));

        $this->assertTrue($this->passwordLogsIn('test_user2', 'password2'), 'The password is untouched');
        $this->assertFalse($this->passwordLogsIn('test_user2', 'chosen-password'));
    }

    public function testUpdateUserRejectsInvalidValues(): void
    {
        $client = static::createClient();
        $url = '/api/v1/users/'.$this->userId('test_user2');

        foreach (['', '   ', str_repeat('a', 256), 42, null, ['Jane']] as $displayName) {
            // A valid address next to it must not be applied on its own
            $data = $this->api($client, 'PATCH', $url, ['displayname' => $displayName, 'email' => 'jane.doe@example.org']);

            $this->assertResponseStatusCodeSame(400, var_export($displayName, true));
            $this->assertSame('Invalid Display Name', $data['message']);
        }

        foreach (['', 'not-an-address', 'jdoe@', str_repeat('a', 250).'@example.org', 42, null] as $email) {
            $data = $this->api($client, 'PATCH', $url, ['displayname' => 'Jane Doe', 'email' => $email]);

            $this->assertResponseStatusCodeSame(400, var_export($email, true));
            $this->assertSame('Invalid Email', $data['message']);
        }

        foreach ([[], ['username' => 'jane'], ['display_name' => 'Jane Doe']] as $body) {
            $client->request('PATCH', $url, [], [], ['HTTP_X_DAVIS_API_TOKEN' => $_ENV['API_KEY']], (string) json_encode($body, JSON_FORCE_OBJECT));

            $this->assertResponseStatusCodeSame(400, (string) json_encode($body));
            $this->assertSame('Nothing to Update', json_decode((string) $client->getResponse()->getContent(), true)['message']);
        }

        foreach (['{not json', '"a string"', ''] as $body) {
            $client->request('PATCH', $url, [], [], ['HTTP_X_DAVIS_API_TOKEN' => $_ENV['API_KEY']], $body);

            $this->assertResponseStatusCodeSame(400, $body);
            $this->assertSame('Invalid JSON', json_decode((string) $client->getResponse()->getContent(), true)['message']);
        }

        $principal = $this->em()->getRepository(Principal::class)->findOneByUri('principals/test_user2');
        $this->assertSame('Test User 2', $principal->getDisplayName(), 'Nothing was changed');
        $this->assertSame('test2@test.com', $principal->getEmail(), 'Nothing was changed');
    }

    public function testUpdateUserRefusesAnEmailThatAnotherUserHas(): void
    {
        $client = static::createClient();
        $url = '/api/v1/users/'.$this->userId('test_user2');

        // test@test.com is the address of the other fixture user
        foreach (['test@test.com', 'Test@TEST.com'] as $email) {
            $data = $this->api($client, 'PATCH', $url, ['displayname' => 'Jane Doe', 'email' => $email]);

            $this->assertResponseStatusCodeSame(409, $email);
            $this->assertSame('error', $data['status']);
            $this->assertSame('Email Already In Use', $data['message']);
        }

        $principal = $this->em()->getRepository(Principal::class)->findOneByUri('principals/test_user2');
        $this->assertSame('test2@test.com', $principal->getEmail(), 'Nothing was changed');
        $this->assertSame('Test User 2', $principal->getDisplayName(), 'Not the display name either');
    }

    /**
     * The user who is changed does not count as "another user".
     */
    public function testUpdateUserAcceptsTheAddressTheUserAlreadyHas(): void
    {
        $client = static::createClient();
        $url = '/api/v1/users/'.$this->userId('test_user2');

        $data = $this->api($client, 'PATCH', $url, ['email' => 'test2@test.com']);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('test2@test.com', $data['data']['email']);

        // Changing nothing but the case of the own address is a change the user may make
        $data = $this->api($client, 'PATCH', $url, ['email' => 'Test2@Test.com']);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('Test2@Test.com', $data['data']['email']);
    }

    /**
     * Users can share an address from before this rule, or because the dashboard does not check
     * it. They are left alone: such a user can still be changed, and sending its address again
     * is not an error.
     */
    public function testUsersWhoAlreadyShareAnAddressCanStillBeChanged(): void
    {
        $client = static::createClient();
        $url = '/api/v1/users/'.$this->userId('test_user2');

        $em = $this->em();
        $em->getRepository(Principal::class)->findOneByUri('principals/test_user2')->setEmail('test@test.com');
        $em->flush();

        $data = $this->api($client, 'PATCH', $url, ['displayname' => 'Jane Doe', 'email' => 'test@test.com']);
        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('Jane Doe', $data['data']['displayname']);
        $this->assertSame('test@test.com', $data['data']['email']);

        // Leaving the shared address for one of its own works too, and it cannot come back
        $this->api($client, 'PATCH', $url, ['email' => 'jane.doe@example.org']);
        $this->assertResponseStatusCodeSame(200);

        $data = $this->api($client, 'PATCH', $url, ['email' => 'test@test.com']);
        $this->assertResponseStatusCodeSame(409);
        $this->assertSame('Email Already In Use', $data['message']);
    }

    public function testUpdateUserOfAnUnknownIdIsNotFound(): void
    {
        $client = static::createClient();

        $data = $this->api($client, 'PATCH', '/api/v1/users/999999', ['displayname' => 'Jane Doe']);

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('User Not Found', $data['message']);
    }

    public function testUpdateUserWithoutAPrincipalIsNotFound(): void
    {
        $client = static::createClient();
        $userId = $this->userId('test_user2');

        $em = $this->em();
        $em->remove($em->getRepository(Principal::class)->findOneByUri(Principal::PREFIX.'test_user2'));
        $em->flush();

        $data = $this->api($client, 'PATCH', '/api/v1/users/'.$userId, ['displayname' => 'Jane Doe']);

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('Principal Not Found', $data['message']);
    }

    public function testAnAdministratorCannotBeChangedOverTheApi(): void
    {
        $client = static::createClient();
        $userId = $this->userId('test_user');

        $data = $this->api($client, 'PATCH', '/api/v1/users/'.$userId, ['displayname' => 'Jane Doe', 'email' => 'jane.doe@example.org']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame('error', $data['status']);
        $this->assertSame('Admin Users Cannot Be Changed Over the API', $data['message']);

        $principal = $this->em()->getRepository(Principal::class)->findOneByUri('principals/test_user');
        $this->assertSame('Test User', $principal->getDisplayName());
        $this->assertSame('test@test.com', $principal->getEmail());
    }

    public function testUpdateUserNeedsTheApiKey(): void
    {
        $client = static::createClient();
        $userId = $this->userId('test_user2');

        $data = $this->api($client, 'PATCH', '/api/v1/users/'.$userId, ['displayname' => 'Jane Doe'], false);
        $this->assertResponseStatusCodeSame(401);
        $this->assertSame('Missing X-Davis-API-Token header', $data['message']);

        $client->request('PATCH', '/api/v1/users/'.$userId, [], [], ['HTTP_X_DAVIS_API_TOKEN' => 'not-the-key'], '{"displayname":"Jane Doe"}');
        $this->assertResponseStatusCodeSame(401);

        $this->assertSame('Test User 2', $this->em()->getRepository(Principal::class)->findOneByUri('principals/test_user2')->getDisplayName());
    }

    public function testDeleteUser(): void
    {
        $client = static::createClient();
        $userId = $this->userId('test_user2');

        $data = $this->api($client, 'DELETE', '/api/v1/users/'.$userId);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('success', $data['status']);
        $this->assertArrayHasKey('timestamp', $data);

        $this->api($client, 'GET', '/api/v1/users/'.$userId);
        $this->assertResponseStatusCodeSame(404);

        // A second call finds nothing to delete
        $data = $this->api($client, 'DELETE', '/api/v1/users/'.$userId);
        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('User Not Found', $data['message']);

        $em = $this->em();
        $this->assertNull($em->getRepository(User::class)->findOneByUsername('test_user2'));
        foreach (['', Principal::READ_PROXY_SUFFIX, Principal::WRITE_PROXY_SUFFIX] as $suffix) {
            $this->assertNull($em->getRepository(Principal::class)->findOneByUri(Principal::PREFIX.'test_user2'.$suffix));
        }
        $this->assertSame([], $em->getRepository(CalendarInstance::class)->findBy(['principalUri' => Principal::PREFIX.'test_user2']));
        $this->assertSame([], $em->getRepository(AddressBook::class)->findBy(['principalUri' => Principal::PREFIX.'test_user2']));

        $this->assertNotNull($em->getRepository(User::class)->findOneByUsername('test_user'), 'The other account is untouched');
    }

    public function testDeleteUserOfAnUnknownIdIsNotFound(): void
    {
        $client = static::createClient();

        $data = $this->api($client, 'DELETE', '/api/v1/users/999999');

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame('User Not Found', $data['message']);
    }

    public function testAnAdministratorCannotBeDeletedOverTheApi(): void
    {
        $client = static::createClient();
        $userId = $this->userId('test_user');

        $data = $this->api($client, 'DELETE', '/api/v1/users/'.$userId);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame('error', $data['status']);
        $this->assertSame('Admin Users Cannot Be Deleted Over the API', $data['message']);

        $em = $this->em();
        $this->assertNotNull($em->getRepository(User::class)->findOneByUsername('test_user'));
        $this->assertNotNull($em->getRepository(Principal::class)->findOneByUri(Principal::PREFIX.'test_user'));
        $this->assertCount(1, $em->getRepository(CalendarInstance::class)->findBy(['principalUri' => Principal::PREFIX.'test_user']));
    }

    public function testAUserWhosePrincipalIsMissingCanStillBeDeleted(): void
    {
        $client = static::createClient();
        $userId = $this->userId('test_user2');

        $em = $this->em();
        $em->remove($em->getRepository(Principal::class)->findOneByUri(Principal::PREFIX.'test_user2'));
        $em->flush();

        $this->api($client, 'DELETE', '/api/v1/users/'.$userId);
        $this->assertResponseStatusCodeSame(200);

        $em = $this->em();
        $this->assertNull($em->getRepository(User::class)->findOneByUsername('test_user2'));
        $this->assertSame([], $em->getRepository(CalendarInstance::class)->findBy(['principalUri' => Principal::PREFIX.'test_user2']));
    }

    /**
     * The same clean-up as the dashboard (see DeletionOrphansTest), reached over the API.
     */
    public function testDeleteUserLeavesNothingBehind(): void
    {
        $client = static::createClient();
        $userId = $this->userId('test_user2');

        $em = $this->em();
        $connection = $em->getConnection();

        foreach (['principals/test_user2', 'calendars/test_user2/default', 'calendars/test_user2/default/event.ics', 'addressbooks/test_user2/default', 'calendars/test_user/default', 'calendars/testXuser2/default'] as $path) {
            $connection->executeStatement('INSERT INTO propertystorage (path, name, valuetype, value) VALUES (?, ?, 1, ?)', [$path, '{DAV:}displayname', 'stored by a client']);
        }

        // test_user delegates to test_user2
        $ownerProxy = $em->getRepository(Principal::class)->findOneByUri(Principal::PREFIX.'test_user'.Principal::WRITE_PROXY_SUFFIX);
        $delegate = $em->getRepository(Principal::class)->findOneByUri(Principal::PREFIX.'test_user2');
        $ownerProxy->addDelegee($delegate);
        $em->flush();
        $delegateId = $delegate->getId();

        $this->api($client, 'DELETE', '/api/v1/users/'.$userId);
        $this->assertResponseStatusCodeSame(200);

        $connection = $this->em()->getConnection();
        $paths = $connection->executeQuery('SELECT path FROM propertystorage')->fetchFirstColumn();
        sort($paths);
        // `_` in the username must not act as a wildcard, and another account keeps its properties
        $this->assertSame(['calendars/testXuser2/default', 'calendars/test_user/default'], $paths);

        $this->assertSame(0, (int) $connection->executeQuery('SELECT COUNT(*) FROM groupmembers WHERE member_id = ?', [$delegateId])->fetchOne());
    }

    public function testDeleteUserRemovesItsCalendarsFromOthersAndKeepsTheCalendarsOfOthers(): void
    {
        $client = static::createClient();
        $userId = $this->userId('test_user2');

        $em = $this->em();
        $instances = $em->getRepository(CalendarInstance::class);
        $ofAdmin = $instances->findOneBy(['principalUri' => Principal::PREFIX.'test_user', 'uri' => 'default']);
        $ofUser2 = $instances->findOneBy(['principalUri' => Principal::PREFIX.'test_user2', 'uri' => 'default']);

        // test_user2 shares its calendar with test_user, and the other way around
        $sharedWithAdmin = (new CalendarInstance())
            ->setCalendar($ofUser2->getCalendar())
            ->setPrincipalUri(Principal::PREFIX.'test_user')
            ->setUri('shared-by-user2')
            ->setDisplayName('Shared by user 2')
            ->setAccess(SharingPlugin::ACCESS_READ);
        $sharedWithUser2 = (new CalendarInstance())
            ->setCalendar($ofAdmin->getCalendar())
            ->setPrincipalUri(Principal::PREFIX.'test_user2')
            ->setUri('shared-by-admin')
            ->setDisplayName('Shared by admin')
            ->setAccess(SharingPlugin::ACCESS_READWRITE);
        $em->persist($sharedWithAdmin);
        $em->persist($sharedWithUser2);
        $em->flush();

        $adminCalendarId = $ofAdmin->getCalendar()->getId();
        $user2CalendarId = $ofUser2->getCalendar()->getId();

        $this->api($client, 'DELETE', '/api/v1/users/'.$userId);
        $this->assertResponseStatusCodeSame(200);

        $connection = $this->em()->getConnection();

        // The calendar of the deleted user is gone, with the instance the other user had of it
        $this->assertSame(0, (int) $connection->executeQuery('SELECT COUNT(*) FROM calendars WHERE id = ?', [$user2CalendarId])->fetchOne());
        $this->assertSame(0, (int) $connection->executeQuery('SELECT COUNT(*) FROM calendarinstances WHERE calendarid = ?', [$user2CalendarId])->fetchOne());

        // A calendar that was only shared with the deleted user stays with its owner
        $this->assertSame(1, (int) $connection->executeQuery('SELECT COUNT(*) FROM calendars WHERE id = ?', [$adminCalendarId])->fetchOne());
        $remaining = $connection->executeQuery('SELECT principaluri FROM calendarinstances WHERE calendarid = ?', [$adminCalendarId])->fetchFirstColumn();
        $this->assertSame([Principal::PREFIX.'test_user'], $remaining);
    }

    public function testDeleteUserUpdatesTheAppKeys(): void
    {
        $client = static::createClient();
        $userId = $this->userId('test_user2');

        $em = $this->em();
        $onlyUser2 = AppKey::create('only user 2', ['test_user2'])->setSecretHash('x');
        $user2AndBob = AppKey::create('user 2 and bob', ['test_user2', 'bob'])->setSecretHash('x');
        $em->persist($onlyUser2);
        $em->persist($user2AndBob);
        $em->flush();

        $this->api($client, 'DELETE', '/api/v1/users/'.$userId);
        $this->assertResponseStatusCodeSame(200);

        $keys = $this->em()->getRepository(AppKey::class);

        $this->assertNotNull($keys->findOneByAppId($onlyUser2->getAppId())->getRevokedAt(), 'A key only for the deleted user is revoked');

        $user2AndBob = $keys->findOneByAppId($user2AndBob->getAppId());
        $this->assertNull($user2AndBob->getRevokedAt());
        $this->assertSame(['bob'], $user2AndBob->getScope());

        $this->assertNull($keys->findOneByAppId('app_test')->getRevokedAt(), 'A * key is not changed');
    }

    public function testTheEndpointsNeedTheApiKey(): void
    {
        $client = static::createClient();
        $userId = $this->userId('test_user2');

        $data = $this->api($client, 'POST', '/api/v1/users/create', self::NEW_USER, false);
        $this->assertResponseStatusCodeSame(401);
        $this->assertSame('Missing X-Davis-API-Token header', $data['message']);

        $this->api($client, 'DELETE', '/api/v1/users/'.$userId, null, false);
        $this->assertResponseStatusCodeSame(401);

        $client->request('DELETE', '/api/v1/users/'.$userId, [], [], ['HTTP_X_DAVIS_API_TOKEN' => 'not-the-key']);
        $this->assertResponseStatusCodeSame(401);

        $em = $this->em();
        $this->assertNull($em->getRepository(User::class)->findOneByUsername('jdoe'));
        $this->assertNotNull($em->getRepository(User::class)->findOneByUsername('test_user2'));
    }
}
