<?php

namespace App\Controller\Api;

use App\Entity\Principal;
use App\Entity\User;
use App\Repository\CalendarInstanceRepository;
use App\Repository\PrincipalRepository;
use App\Services\ApiUserManagementFeature;
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
 * Creates and deletes users over the API.
 *
 * These endpoints are off unless API_USER_MANAGEMENT_ENABLED is true, so that an install which
 * only set API_KEY to manage calendars does not get a more powerful API with an update. When they
 * are off they answer 404 from here rather than through a route condition: routing runs before
 * the firewall, and a caller without the API key must not be able to tell whether they are on.
 *
 * Nothing here can grant more than a regular account: no password, no admin flag, and an
 * administrator cannot be deleted. That stays with the dashboard.
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

        $displayName = $data['displayname'] ?? null;
        $displayName = is_string($displayName) ? trim($displayName) : '';
        if ('' === $displayName || mb_strlen($displayName) > 255) {
            return $this->error('Invalid Display Name', 400);
        }

        // Same rules as the dashboard: without an address, sabre never sends an invitation
        $email = $data['email'] ?? null;
        if (!is_string($email) || count($validator->validate($email, [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 255)])) > 0) {
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
}
