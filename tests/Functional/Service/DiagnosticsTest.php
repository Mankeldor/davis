<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Services\Diagnostics;
use App\Tests\Functional\Double\SchedulingMailFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The page is only worth having if a check turns red when its condition holds, so each one is
 * driven here into the state it is meant to report.
 */
class DiagnosticsTest extends KernelTestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function diagnosticsWith(array $overrides = []): Diagnostics
    {
        self::bootKernel();
        $container = static::getContainer();

        $arguments = array_merge([
            'connection' => $container->get('doctrine')->getConnection(),
            'migrations' => $container->get('doctrine.migrations.dependency_factory'),
            'router' => $container->get(UrlGeneratorInterface::class),
            'environment' => 'prod',
            'debug' => false,
            'logFilePath' => sys_get_temp_dir().'/davis-diagnostics/prod.log',
            'timezoneParameter' => 'Europe/Paris',
            'authMethod' => 'Basic',
            'authRealm' => 'SabreDAV',
            'calDAVEnabled' => true,
            'cardDAVEnabled' => true,
            'webDAVEnabled' => false,
            'webdavPublicDirWritable' => false,
            'inviteAddress' => 'no-reply@example.org',
            'mailerDsn' => 'smtp://smtp.example.org:587',
            'appKeysEnabled' => true,
            'adminAuthBypass' => 'false',
        ], $overrides);

        // Only for the default, writable path: some tests deliberately point somewhere unwritable
        $directory = \dirname($arguments['logFilePath']);
        if (str_starts_with($directory, sys_get_temp_dir()) && !is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        return new Diagnostics(...$arguments);
    }

    /**
     * @return array<string, array<string, string|null>> keyed by check title
     */
    private function checksOf(Diagnostics $diagnostics): array
    {
        $checks = [];
        foreach ($diagnostics->buckets() as $bucket) {
            foreach ($bucket['checks'] as $check) {
                $checks[$check['title']] = $check;
            }
        }

        return $checks;
    }

    private function severityOf(Diagnostics $diagnostics, string $title): string
    {
        $checks = $this->checksOf($diagnostics);
        $this->assertArrayHasKey($title, $checks);

        return $checks[$title]['severity'];
    }

    public function testAHealthyInstallReportsNothingToLookAt(): void
    {
        $diagnostics = $this->diagnosticsWith();

        // The test database is built by the migrations, so none of them are pending
        $this->assertSame(Diagnostics::OK, $this->severityOf($diagnostics, 'diagnostics.migrations'));
        $this->assertSame(Diagnostics::OK, $this->severityOf($diagnostics, 'diagnostics.log_file'));
        $this->assertSame(Diagnostics::OK, $this->severityOf($diagnostics, 'diagnostics.environment'));
        $this->assertSame(Diagnostics::OK, $this->severityOf($diagnostics, 'diagnostics.timezone'));
        $this->assertSame(Diagnostics::OK, $this->severityOf($diagnostics, 'diagnostics.accounts_without_email'));
        $this->assertSame(Diagnostics::OK, $this->severityOf($diagnostics, 'diagnostics.broken_subscriptions'));
    }

    public function testAnUnwritableLogDirectoryIsReported(): void
    {
        $diagnostics = $this->diagnosticsWith(['logFilePath' => '/this/path/does/not/exist/prod.log']);

        $this->assertSame(Diagnostics::DANGER, $this->severityOf($diagnostics, 'diagnostics.log_file'));
    }

    public function testRunningOutsideProductionIsReported(): void
    {
        $this->assertSame(
            Diagnostics::WARNING,
            $this->severityOf($this->diagnosticsWith(['environment' => 'dev', 'debug' => true]), 'diagnostics.environment')
        );
    }

    public function testATimezoneThatIsNotOneIsReported(): void
    {
        $this->assertSame(
            Diagnostics::DANGER,
            $this->severityOf($this->diagnosticsWith(['timezoneParameter' => 'Mars/Olympus_Mons']), 'diagnostics.timezone')
        );

        $this->assertSame(
            Diagnostics::WARNING,
            $this->severityOf($this->diagnosticsWith(['timezoneParameter' => '']), 'diagnostics.timezone')
        );
    }

    public function testLdapWithoutItsExtensionIsReported(): void
    {
        if (\extension_loaded('ldap')) {
            $this->markTestSkipped('ext-ldap is loaded here, so it cannot be reported as missing');
        }

        $this->assertSame(
            Diagnostics::DANGER,
            $this->severityOf($this->diagnosticsWith(['authMethod' => 'LDAP']), 'diagnostics.auth_extension')
        );
    }

    public function testBasicAuthDoesNotAskForAnExtension(): void
    {
        $this->assertSame(
            Diagnostics::OK,
            $this->severityOf($this->diagnosticsWith(['authMethod' => 'Basic']), 'diagnostics.auth_extension')
        );
    }

    public function testSchedulingWithoutASenderAddressIsReported(): void
    {
        $this->assertSame(
            Diagnostics::WARNING,
            $this->severityOf($this->diagnosticsWith(['inviteAddress' => null]), 'diagnostics.invite_address')
        );
    }

    public function testAMissingMailTransportIsReported(): void
    {
        $this->assertSame(
            Diagnostics::WARNING,
            $this->severityOf($this->diagnosticsWith(['mailerDsn' => null]), 'diagnostics.mailer')
        );
    }

    public function testNoProtocolEnabledIsReported(): void
    {
        $this->assertSame(
            Diagnostics::DANGER,
            $this->severityOf($this->diagnosticsWith([
                'calDAVEnabled' => false,
                'cardDAVEnabled' => false,
                'webDAVEnabled' => false,
            ]), 'diagnostics.protocols')
        );
    }

    /**
     * The count on the dashboard is what tells an administrator to look at all.
     */
    public function testTheAttentionCountFollowsTheChecks(): void
    {
        // Relative to the baseline: on SQLite the engine check warns on its own, by design.
        $baseline = $this->diagnosticsWith()->attentionCount();

        $this->assertSame($baseline + 1, $this->diagnosticsWith([
            'environment' => 'dev',
            'debug' => true,
        ])->attentionCount());

        $this->assertSame($baseline + 2, $this->diagnosticsWith([
            'environment' => 'dev',
            'debug' => true,
            'inviteAddress' => null,
        ])->attentionCount());
    }

    /**
     * Whatever the state, the page must not put a credential on screen.
     */
    public function testTheMailTransportNeverShowsItsCredentials(): void
    {
        $checks = $this->checksOf($this->diagnosticsWith([
            'mailerDsn' => 'smtp://someone:hunter2@smtp.example.org:587',
        ]));

        $this->assertStringNotContainsString('hunter2', $checks['diagnostics.mailer']['value']);
        $this->assertStringNotContainsString('someone', $checks['diagnostics.mailer']['value']);
        $this->assertSame('smtp://smtp.example.org', $checks['diagnostics.mailer']['value']);
    }

    public function testActiveAppKeysAreCounted(): void
    {
        // The fixtures hold one active key and one revoked key
        $check = $this->checksOf($this->diagnosticsWith())['diagnostics.app_keys'];

        $this->assertSame('1', $check['value']);
        $this->assertSame(Diagnostics::INFO, $check['severity']);
        $this->assertSame('/app-keys/', $check['link']);
        $this->assertStringNotContainsString('app-secret', json_encode($check));
        $this->assertStringNotContainsString('$2y$', json_encode($check));
    }

    public function testAppKeysTurnedOff(): void
    {
        $check = $this->checksOf($this->diagnosticsWith(['appKeysEnabled' => false]))['diagnostics.app_keys'];

        $this->assertSame('diagnostics.value.disabled', $check['value']);
        $this->assertSame('APP_KEYS_ENABLED=true', $check['command']);
        $this->assertNull($check['link'], 'There is no page to link to');
    }

    public function testUserManagementOverTheApiIsOffByDefaultAndSaysHowToTurnItOn(): void
    {
        $check = $this->checksOf($this->diagnosticsWith())['diagnostics.api_user_management'];

        $this->assertSame(Diagnostics::INFO, $check['severity']);
        $this->assertSame('diagnostics.value.disabled', $check['value']);
        $this->assertSame('API_USER_MANAGEMENT_ENABLED=true', $check['command']);
    }

    public function testUserManagementOverTheApiIsShownWhenOn(): void
    {
        $check = $this->checksOf($this->diagnosticsWith(['apiUserManagementEnabled' => true]))['diagnostics.api_user_management'];

        $this->assertSame(Diagnostics::INFO, $check['severity'], 'Turning it on is a choice, not a problem');
        $this->assertSame('diagnostics.value.enabled', $check['value']);
        $this->assertSame('diagnostics.api_user_management.help', $check['fix']);
        $this->assertNull($check['command']);
    }

    public function testTheAuthBypassIsShownWhenOn(): void
    {
        $diagnostics = $this->diagnosticsWith(['adminAuthBypass' => 'true']);
        $check = $this->checksOf($diagnostics)['diagnostics.admin_auth_bypass'];

        $this->assertSame('diagnostics.value.enabled', $check['value']);
        $this->assertSame(Diagnostics::INFO, $check['severity'], 'A deliberate setup behind a proxy is nothing to fix');
        // Relative to the baseline: on SQLite the engine check warns on its own, by design.
        $this->assertSame($this->diagnosticsWith()->attentionCount(), $diagnostics->attentionCount());
    }

    public function testAnAuthBypassValueThatIsIgnoredIsReported(): void
    {
        // The access rule compares to the exact string "true"
        foreach (['True', '1', 'yes'] as $value) {
            $check = $this->checksOf($this->diagnosticsWith(['adminAuthBypass' => $value]))['diagnostics.admin_auth_bypass'];

            $this->assertSame(Diagnostics::WARNING, $check['severity'], $value);
            $this->assertSame($value, $check['value']);
        }
    }

    public function testTheSchedulingEmailsAreLeftOutWithoutAService(): void
    {
        $this->assertArrayNotHasKey('diagnostics.invite_language', $this->checksOf($this->diagnosticsWith()));
    }

    public function testDefaultSchedulingEmailsReportNothingToLookAt(): void
    {
        $diagnostics = $this->diagnosticsWith(['schedulingMail' => SchedulingMailFactory::create(static::getContainer())]);

        $this->assertSame(Diagnostics::OK, $this->severityOf($diagnostics, 'diagnostics.invite_language'));
        $this->assertSame(Diagnostics::OK, $this->severityOf($diagnostics, 'diagnostics.invite_look'));
        $this->assertSame(Diagnostics::OK, $this->severityOf($diagnostics, 'diagnostics.invite_templates'));
        $this->assertSame(Diagnostics::INFO, $this->severityOf($diagnostics, 'diagnostics.invite_local_users'));
    }

    /**
     * Each of these falls back to the default without a word, which is why the page says so.
     *
     * @dataProvider unusableMailSettings
     */
    public function testUnusableSchedulingEmailSettingsAreReported(array $settings, string $title): void
    {
        $diagnostics = $this->diagnosticsWith(['schedulingMail' => SchedulingMailFactory::create(static::getContainer(), $settings)]);

        $this->assertSame(Diagnostics::WARNING, $this->severityOf($diagnostics, $title));
    }

    public static function unusableMailSettings(): iterable
    {
        yield 'a language without texts' => [['locale' => 'xx'], 'diagnostics.invite_language'];
        yield 'a logo that is not a web address' => [['logoUrl' => 'logo.png'], 'diagnostics.invite_look'];
        yield 'a color that is not one' => [['accentColor' => 'black'], 'diagnostics.invite_look'];
        yield 'a templates directory that is not there' => [['templatesDir' => '/nonexistent/davis-templates'], 'diagnostics.invite_templates'];
    }

    public function testABrokenTemplateOfOneOwnIsReportedWithItsError(): void
    {
        $directory = sys_get_temp_dir().'/davis-diagnostics-templates-'.uniqid();
        mkdir($directory);
        file_put_contents($directory.'/layout.txt.twig', '{% if %}');

        try {
            $diagnostics = $this->diagnosticsWith(['schedulingMail' => SchedulingMailFactory::create(static::getContainer(), ['templatesDir' => $directory])]);
            $check = $this->checksOf($diagnostics)['diagnostics.invite_templates'];

            $this->assertSame(Diagnostics::WARNING, $check['severity']);
            $this->assertStringStartsWith('layout.txt.twig: ', (string) $check['command']);
        } finally {
            unlink($directory.'/layout.txt.twig');
            rmdir($directory);
        }
    }
}
