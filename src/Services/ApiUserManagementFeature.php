<?php

namespace App\Services;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tells if users can be created and deleted over the API (API_USER_MANAGEMENT_ENABLED, off by default).
 * When it is off, those endpoints answer 404 and the rest of the API is unchanged.
 */
final class ApiUserManagementFeature
{
    public function __construct(
        #[Autowire('%api_user_management_enabled%')]
        private bool $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
