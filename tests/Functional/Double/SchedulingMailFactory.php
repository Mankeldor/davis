<?php

declare(strict_types=1);

namespace App\Tests\Functional\Double;

use App\Services\SchedulingMail;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the service that words the scheduling emails, with the settings a test needs.
 */
final class SchedulingMailFactory
{
    /**
     * @param array<string, string> $settings constructor arguments by name (locale, brandName, …)
     */
    public static function create(ContainerInterface $container, array $settings = [], ?LoggerInterface $logger = null, bool $withRequest = true): SchedulingMail
    {
        // Scheduling happens while a DAV request is handled; the footer names its host
        $requestStack = new RequestStack();
        if ($withRequest) {
            $requestStack->push(Request::create('https://dav.example.org/dav/calendars/'));
        }

        $projectDir = \dirname(__DIR__, 3);
        $translator = $container->get('translator');
        \assert($translator instanceof TranslatorInterface);

        return new SchedulingMail(
            $translator,
            $requestStack,
            $logger ?? new NullLogger(),
            $projectDir.'/templates/mails',
            $projectDir.'/translations',
            ...$settings
        );
    }
}
