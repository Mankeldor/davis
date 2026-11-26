<?php

namespace App\Controller\Api;

use App\Entity\AppKey;
use App\Entity\Principal;
use App\Entity\User;
use App\Repository\AppKeyRepository;
use App\Repository\CalendarInstanceRepository;
use App\Repository\PrincipalRepository;
use App\Services\ApiUserManagementFeature;
use App\Services\AppKeysFeature;
use App\Services\UserRemover;
use App\Services\Utils;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Creates and deletes users over the API, and creates and revokes their app keys.
 *
 * These endpoints are off unless API_USER_MANAGEMENT_ENABLED is true, so that an install which
 * only set API_KEY to manage calendars does not get a more powerful API with an update. When they
 * are off they answer 404 from here rather than through a route condition: routing runs before
 * the firewall, and a caller without the API key must not be able to tell whether they are on.
 *
 * Nothing here can grant more than a regular account: no password, no admin flag, and an
 * administrator cannot be deleted. An app key is always for exactly one user who is not an
 * administrator; a key for all users can neither be created nor revoked. That stays with the
 * dashboard and the console.
 */
#[Route('/api/v1', name: 'api_v1_')]
#[WithMonologChannel('api')]
class UserManagementController extends AbstractController
{
    public function __construct(
        private ApiUserManagementFeature $feature,
        private EntityManagerInterface $entityManager,
        private PrincipalRepository $principals,
        private LoggerInterface $logger,
    ) {
    }

    private function error(string $message, int $status): JsonResponse
    {
        return $this->json(['status' => 'error', 'message' => $message, 'timestamp' => date('c')], $status);
    }

    private function disabled(): JsonResponse
    {
        return $this->error('User management over the API is disabled', 404);
    }

    private function appKeysDisabled(): JsonResponse
    {
        return $this->error('App keys are disabled', 404);
    }

    /**
     * Reads an ISO 8601 date and time with its offset, e.g. `2027-01-23T00:00:00+01:00`.
     * Returns null for anything else, including a date that does not exist.
     */
    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        foreach ([\DateTimeInterface::ATOM, 'Y-m-d\TH:i:s.uP'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();

            if ($date && (!$errors || 0 === $errors['warning_count'] + $errors['error_count'])) {
                // The column has no timezone: it is read back in the server's, so it is stored in it
                return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            }
        }

        return null;
    }

    /**
     * Tells if a user or a principal already uses this name, in any letter case.
     *
     * MySQL and MariaDB compare strings without case, so `Anna` next to `anna` would only fail at
     * the unique index there, while PostgreSQL and SQLite would accept it and leave two accounts
     * that differ by case only. The principals are checked too (with both proxies), because a
     * principal can be left behind without its user.
     */
    private function usernameIsTaken(string $username): bool
    {
        $users = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('LOWER(u.username) = :username')
            ->setParameter('username', strtolower($username))
            ->getQuery()
            ->getSingleScalarResult();

        if ($users > 0) {
            return true;
        }

        $principalUri = strtolower(Principal::PREFIX.$username);

        return (int) $this->principals->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('LOWER(p.uri) IN (:uris)')
            ->setParameter('uris', [
                $principalUri,
                $principalUri.Principal::READ_PROXY_SUFFIX,
                $principalUri.Principal::WRITE_PROXY_SUFFIX,
            ])
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * A display name is kept trimmed. Returns null when nothing is left of it, or too much.
     */
    private function readDisplayName(mixed $value): ?string
    {
        $displayName = is_string($value) ? trim($value) : '';

        return '' === $displayName || mb_strlen($displayName) > 255 ? null : $displayName;
    }

    /**
     * Same rules as the dashboard: without an address, sabre never sends an invitation.
     *
     * @psalm-assert-if-true string $value
     */
    private function isValidEmail(mixed $value, ValidatorInterface $validator): bool
    {
        return is_string($value) && 0 === count($validator->validate($value, [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 255)]));
    }

    /**
     * Creates a user with its principal, default calendar and default address book.
     *
     * @param Request $request The HTTP POST request
     *
     * @return JsonResponse A JSON response describing the new user, or the failure
     */
    #[Route('/users/create', name: 'user_create', methods: ['POST'])]
    public function createUser(Request $request, Utils $utils, ValidatorInterface $validator, CalendarInstanceRepository $calendarInstances): JsonResponse
    {
        if (!$this->feature->isEnabled()) {
            return $this->disabled();
        }

        $data = json_decode($request->getContent(), true);
        if (JSON_ERROR_NONE !== json_last_error() || !is_array($data)) {
            return $this->error('Invalid JSON', 400);
        }

        $username = $data['username'] ?? null;
        if (!is_string($username) || !Utils::isValidUsername($username) || strlen($username) > 255) {
            return $this->error('Invalid Username', 400);
        }

        $displayName = $this->readDisplayName($data['displayname'] ?? null);
        if (null === $displayName) {
            return $this->error('Invalid Display Name', 400);
        }

        $email = $data['email'] ?? null;
        if (!$this->isValidEmail($email, $validator)) {
            return $this->error('Invalid Email', 400);
        }

        if ($this->usernameIsTaken($username)) {
            return $this->error('User Already Exists', 409);
        }

        try {
            $utils->createPasswordlessUserWithDefaultObjects($username, $displayName, $email);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Another request created the same user in the meantime
            return $this->error('User Already Exists', 409);
        } catch (\Exception) {
            return $this->error('Failed to Create User', 500);
        }

        $principalUri = Principal::PREFIX.$username;
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        $principal = $this->principals->findOneByUri($principalUri);
        $calendar = $calendarInstances->findOneBy(['principalUri' => $principalUri, 'uri' => 'default']);

        if (!$user || !$principal || !$calendar) {
            return $this->error('Failed to Create User', 500);
        }

        $this->logger->notice('API: user created', ['username' => $username, 'user_id' => $user->getId()]);

        return $this->json([
            'status' => 'success',
            'data' => [
                'user_id' => $user->getId(),
                'principal_id' => $principal->getId(),
                'uri' => $principal->getUri(),
                'username' => $user->getUsername(),
                'default_calendar' => [
                    'id' => $calendar->getId(),
                    'uri' => $calendar->getUri(),
                ],
            ],
            'timestamp' => date('c'),
        ], 200);
    }

    /**
     * Changes the display name and the email address of a user. Only the fields that are sent
     * change. The username is not one of them: the principal and every calendar and address
     * book URL are built from it.
     *
     * @param Request $request The HTTP PATCH request
     * @param int     $userId  The ID of the user to change
     *
     * @return JsonResponse A JSON response describing the user after the change, or the failure
     */
    #[Route('/users/{userId}', name: 'user_update', methods: ['PATCH'], requirements: ['userId' => '\d+'])]
    public function updateUser(Request $request, int $userId, ValidatorInterface $validator): JsonResponse
    {
        if (!$this->feature->isEnabled()) {
            return $this->disabled();
        }

        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$user) {
            return $this->error('User Not Found', 404);
        }

        // The display name and the email address live on the principal
        $principal = $this->principals->findOneByUri($user->getPrincipalUri());
        if (!$principal) {
            return $this->error('Principal Not Found', 404);
        }

        if ($principal->getIsAdmin()) {
            return $this->error('Admin Users Cannot Be Changed Over the API', 403);
        }

        $data = json_decode($request->getContent(), true);
        if (JSON_ERROR_NONE !== json_last_error() || !is_array($data)) {
            return $this->error('Invalid JSON', 400);
        }

        $hasDisplayName = array_key_exists('displayname', $data);
        $hasEmail = array_key_exists('email', $data);
        if (!$hasDisplayName && !$hasEmail) {
            return $this->error('Nothing to Update', 400);
        }

        // Both are checked before either is set, so a request is applied as a whole or not at all
        $displayName = $principal->getDisplayName();
        if ($hasDisplayName) {
            $displayName = $this->readDisplayName($data['displayname']);
            if (null === $displayName) {
                return $this->error('Invalid Display Name', 400);
            }
        }

        $email = $principal->getEmail();
        if ($hasEmail) {
            $email = $data['email'];
            if (!$this->isValidEmail($email, $validator)) {
                return $this->error('Invalid Email', 400);
            }
        }

        $changed = [];
        if ($displayName !== $principal->getDisplayName()) {
            $changed[] = 'displayname';
        }
        if ($email !== $principal->getEmail()) {
            $changed[] = 'email';
        }

        try {
            $principal->setDisplayName($displayName)->setEmail($email);
            $this->entityManager->flush();
        } catch (\Exception) {
            return $this->error('Failed to Update User', 500);
        }

        if ($changed) {
            // Which fields, not their values: the log is not the place for names and addresses
            $this->logger->notice('API: user updated', ['username' => $user->getUsername(), 'user_id' => $userId, 'changed' => $changed]);
        }

        return $this->json([
            'status' => 'success',
            'data' => [
                'user_id' => $user->getId(),
                'principal_id' => $principal->getId(),
                'uri' => $principal->getUri(),
                'username' => $user->getUsername(),
                'displayname' => $principal->getDisplayName(),
                'email' => $principal->getEmail(),
            ],
            'timestamp' => date('c'),
        ], 200);
    }

    /**
     * Deletes a user and everything that belongs to them.
     *
     * @param int $userId The ID of the user to delete
     *
     * @return JsonResponse A JSON response indicating the success or failure of the operation
     */
    #[Route('/users/{userId}', name: 'user_delete', methods: ['DELETE'], requirements: ['userId' => '\d+'])]
    public function deleteUser(int $userId, UserRemover $userRemover): JsonResponse
    {
        if (!$this->feature->isEnabled()) {
            return $this->disabled();
        }

        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$user) {
            return $this->error('User Not Found', 404);
        }

        // An account without a principal is not an administrator, and can still be cleaned up
        $principal = $this->principals->findOneByUri($user->getPrincipalUri());
        if ($principal && $principal->getIsAdmin()) {
            return $this->error('Admin Users Cannot Be Deleted Over the API', 403);
        }

        $username = $user->getUsername();

        try {
            $userRemover->remove($user);
        } catch (\Exception) {
            return $this->error('Failed to Delete User', 500);
        }

        $this->logger->notice('API: user deleted', ['username' => $username, 'user_id' => $userId]);

        return $this->json(['status' => 'success', 'timestamp' => date('c')], 200);
    }

    /**
     * Creates an app key that can log in over DAV as this user, and returns its secret once.
     *
     * @param Request $request The HTTP POST request
     * @param int     $userId  The ID of the user the key is for
     *
     * @return JsonResponse A JSON response with the key and its secret, or the failure
     */
    #[Route('/users/{userId}/app_keys/create', name: 'user_app_key_create', methods: ['POST'], requirements: ['userId' => '\d+'])]
    public function createUserAppKey(Request $request, int $userId, AppKeysFeature $appKeysFeature): JsonResponse
    {
        if (!$this->feature->isEnabled()) {
            return $this->disabled();
        }

        if (!$appKeysFeature->isEnabled()) {
            return $this->appKeysDisabled();
        }

        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$user) {
            return $this->error('User Not Found', 404);
        }

        // A key only logs in as an account that has both, see AbstractAuth
        $principal = $this->principals->findOneByUri($user->getPrincipalUri());
        if (!$principal) {
            return $this->error('Principal Not Found', 404);
        }

        // An administrator can read every calendar and address book
        if ($principal->getIsAdmin()) {
            return $this->error('App Keys for Admin Users Cannot Be Created Over the API', 403);
        }

        // The scope is this user and nothing else. A username that the scope would read
        // differently (`*` is "all users") cannot have a key from here.
        $username = (string) $user->getUsername();
        if (AppKey::ALL_USERS === $username || AppKey::normaliseScope([$username]) !== [$username]) {
            return $this->error('App Keys for This Username Cannot Be Created Over the API', 403);
        }

        $data = json_decode($request->getContent(), true);
        if (JSON_ERROR_NONE !== json_last_error() || !is_array($data)) {
            return $this->error('Invalid JSON', 400);
        }

        $name = $data['name'] ?? null;
        $name = is_string($name) ? trim($name) : '';
        if ('' === $name || mb_strlen($name) > 255) {
            return $this->error('Invalid Name', 400);
        }

        $expiresAt = null;
        if (null !== ($data['expires_at'] ?? null)) {
            $expiresAt = $this->parseDateTime($data['expires_at']);
            if (!$expiresAt || $expiresAt <= new \DateTimeImmutable()) {
                return $this->error('Invalid Expiry Date', 400);
            }
        }

        try {
            $key = AppKey::create($name, [$username], $expiresAt);
            $secret = $key->issueSecret();

            $this->entityManager->persist($key);
            $this->entityManager->flush();
        } catch (\Exception) {
            return $this->error('Failed to Create App Key', 500);
        }

        // The secret is in the response and nowhere else: not in the log, not in the database
        $this->logger->notice('API: app key created', ['username' => $username, 'user_id' => $userId, 'app_id' => $key->getAppId()]);

        $response = $this->json([
            'status' => 'success',
            'data' => [
                'app_id' => $key->getAppId(),
                'secret' => $secret,
                'name' => $key->getName(),
                'username' => $username,
                'basic_username' => $username.'*'.$key->getAppId(),
                'expires_at' => $expiresAt?->format('c'),
            ],
            'timestamp' => date('c'),
        ], 200);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Revokes an app key whose scope is exactly this user.
     *
     * @param int    $userId The ID of the user the key is for
     * @param string $appId  The app id of the key
     *
     * @return JsonResponse A JSON response with the revocation date, or the failure
     */
    #[Route('/users/{userId}/app_keys/{appId}/revoke', name: 'user_app_key_revoke', methods: ['POST'], requirements: ['userId' => '\d+', 'appId' => '[A-Za-z0-9_]+'])]
    public function revokeUserAppKey(int $userId, string $appId, AppKeysFeature $appKeysFeature, AppKeyRepository $appKeys): JsonResponse
    {
        if (!$this->feature->isEnabled()) {
            return $this->disabled();
        }

        if (!$appKeysFeature->isEnabled()) {
            return $this->appKeysDisabled();
        }

        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if (!$user) {
            return $this->error('User Not Found', 404);
        }

        // Only a key that is for this user and nobody else. A key for all users or for several
        // users gets the same answer as a key that does not exist, so nothing is learnt about it.
        // The app id is compared again here because MySQL ignores case when it looks it up.
        $username = (string) $user->getUsername();
        $key = $appKeys->findOneByAppId($appId);
        if (!$key || $key->getAppId() !== $appId || $key->getScope() !== [$username]) {
            return $this->error('App Key Not Found', 404);
        }

        try {
            // A key that is already revoked keeps its date
            $key->revoke(new \DateTimeImmutable());
            $this->entityManager->flush();
        } catch (\Exception) {
            return $this->error('Failed to Revoke App Key', 500);
        }

        $this->logger->notice('API: app key revoked', ['username' => $username, 'user_id' => $userId, 'app_id' => $key->getAppId()]);

        return $this->json([
            'status' => 'success',
            'data' => [
                'app_id' => $key->getAppId(),
                'revoked_at' => $key->getRevokedAt()?->format('c'),
            ],
            'timestamp' => date('c'),
        ], 200);
    }
}
