<?php

namespace App\Services;

use App\Entity\CalendarSubscription;
use App\Entity\Principal;
use App\Entity\SchedulingObject;
use App\Entity\User;
use App\Repository\AddressBookRepository;
use App\Repository\AppKeyRepository;
use App\Repository\CalendarInstanceRepository;
use App\Repository\PrincipalRepository;
use App\Repository\PropertyStorageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes a user and everything that belongs to them.
 *
 * Rows are deleted directly in the database instead of over DAV, so this has to remove everything
 * sabre would have removed on `afterUnbind`. Nothing in the schema catches a mistake here, which
 * is why there is only one copy of these steps.
 */
final class UserRemover
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PrincipalRepository $principals,
        private CalendarInstanceRepository $calendarInstances,
        private AddressBookRepository $addressBooks,
        private AppKeyRepository $appKeys,
        private PropertyStorageRepository $properties,
    ) {
    }

    /**
     * Removes the user, their principals, the calendars they own (with their objects and the
     * instances shared with others), their access to calendars shared with them, their
     * subscriptions, scheduling objects, address books and stored properties, and takes them out
     * of the app keys that list them by name.
     *
     * The principals are looked up from the username, so an account whose principal is missing
     * can still be cleaned up.
     */
    public function remove(User $user): void
    {
        $username = (string) $user->getUsername();
        $principalUri = $user->getPrincipalUri();

        $this->entityManager->remove($user);

        foreach ([$principalUri, $principalUri.Principal::READ_PROXY_SUFFIX, $principalUri.Principal::WRITE_PROXY_SUFFIX] as $uri) {
            $principal = $this->principals->findOneByUri($uri);
            if ($principal) {
                $this->entityManager->remove($principal);
            }
        }

        // Remove calendars and addressbooks
        foreach ($this->calendarInstances->findByPrincipalUriWithCalendars($principalUri) as $instance) {
            $calendar = $instance->getCalendar();

            // We're only removing the calendar objects / changes / and calendar if the deleted user is an owner,
            // which means that the underlying calendar instance should not have another principal as owner.
            if ($calendar && !$this->calendarInstances->hasDifferentOwner((int) $calendar->getId(), $principalUri)) {
                foreach ($calendar->getObjects() as $object) {
                    $this->entityManager->remove($object);
                }
                foreach ($calendar->getChanges() as $change) {
                    $this->entityManager->remove($change);
                }
                // We need to remove the shared versions of this calendar, too
                foreach ($calendar->getInstances() as $sharedInstance) {
                    $this->entityManager->remove($sharedInstance);
                }
                $this->entityManager->remove($calendar);
            }
            $this->entityManager->remove($instance);
        }

        foreach ($this->entityManager->getRepository(CalendarSubscription::class)->findBy(['principalUri' => $principalUri]) as $subscription) {
            $this->entityManager->remove($subscription);
        }

        foreach ($this->entityManager->getRepository(SchedulingObject::class)->findBy(['principalUri' => $principalUri]) as $object) {
            $this->entityManager->remove($object);
        }

        foreach ($this->addressBooks->findBy(['principalUri' => $principalUri]) as $addressBook) {
            foreach ($addressBook->getCards() as $card) {
                $this->entityManager->remove($card);
            }
            foreach ($addressBook->getChanges() as $change) {
                $this->entityManager->remove($change);
            }
            $this->entityManager->remove($addressBook);
        }

        // Remove the user from the app keys that list them by name.
        $this->appKeys->forgetUser($username, new \DateTimeImmutable());

        $this->entityManager->flush();

        // After the flush, so the properties are still there if the flush fails
        $this->properties->deletePathsAndTheirChildren([
            $principalUri,
            'calendars/'.$username,
            'addressbooks/'.$username,
        ]);
    }
}
