<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Services\SchedulingMail;
use App\Tests\Functional\Double\RecordingLogger;
use App\Tests\Functional\Double\SchedulingMailFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Language, look and templates of the scheduling emails.
 */
class SchedulingMailTest extends KernelTestCase
{
    private ?string $templatesDir = null;

    /** @var non-empty-string */
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        if ($this->templatesDir) {
            array_map('unlink', glob($this->templatesDir.'/*') ?: []);
            rmdir($this->templatesDir);
        }
        parent::tearDown();
    }

    private function mail(array $settings = [], ?RecordingLogger $logger = null): SchedulingMail
    {
        self::bootKernel();

        return SchedulingMailFactory::create(static::getContainer(), $settings, $logger);
    }

    private function germanMail(array $settings = []): SchedulingMail
    {
        $mail = $this->mail(['locale' => 'de'] + $settings);
        if (!$mail->canFormatDates()) {
            $this->markTestSkipped('This ICU has no German data (on Alpine Linux: apk add icu-data-full)');
        }

        return $mail;
    }

    private function event(array $overrides = []): array
    {
        return array_merge([
            'senderName' => 'Jane Doe',
            'recipientName' => 'Max',
            'summary' => 'Planning',
            'start' => new \DateTimeImmutable('2026-12-01T09:00:00Z'),
            'end' => new \DateTimeImmutable('2026-12-01T10:30:00Z'),
            'allDay' => false,
            'recurring' => false,
            'attendees' => [['cn' => 'Max', 'email' => 'max@example.org', 'role' => null]],
            'location' => false,
            'locationImageDataAsBase64' => false,
            'locationLink' => false,
            'url' => false,
            'description' => false,
            'attached' => true,
        ], $overrides);
    }

    private function templatesDir(array $files): string
    {
        $this->templatesDir = sys_get_temp_dir().'/davis-mail-templates-'.uniqid();
        mkdir($this->templatesDir);
        foreach ($files as $name => $content) {
            file_put_contents($this->templatesDir.'/'.$name, $content);
        }

        return $this->templatesDir;
    }

    /**
     * @dataProvider germanSubjects
     */
    public function testEveryKindHasAGermanSubject(string $action, string $subject): void
    {
        $mail = $this->germanMail();

        $this->assertSame($subject, $mail->subject($mail->context($action, $this->event())));
    }

    public static function germanSubjects(): iterable
    {
        yield 'invitation' => ['REQUEST', 'Einladung: Planning · Di., 1. Dez. 2026, 10:00 Uhr'];
        yield 'update' => ['UPDATE', 'Geändert: Planning · Di., 1. Dez. 2026, 10:00 Uhr'];
        yield 'cancellation' => ['CANCEL', 'Abgesagt: Planning · Di., 1. Dez. 2026, 10:00 Uhr'];
        yield 'removed' => ['UNINVITED', 'Abgesagt für Sie: Planning · Di., 1. Dez. 2026, 10:00 Uhr'];
        yield 'accepted' => ['ACCEPTED', 'Jane Doe nimmt teil: Planning'];
        yield 'tentative' => ['TENTATIVE', 'Jane Doe nimmt vielleicht teil: Planning'];
        yield 'declined' => ['DECLINED', 'Jane Doe nimmt nicht teil: Planning'];
    }

    public function testGermanDatesAndTimesInTheServersTimezone(): void
    {
        $mail = $this->germanMail();

        $when = $mail->context('REQUEST', $this->event())['when'];

        $this->assertSame('Dienstag, 1. Dezember 2026', $when['date']);
        $this->assertSame('10:00–11:30 Uhr (MEZ)', $when['time']);
    }

    public function testAnAllDayEventHasNoTimeAndEndsTheDayBeforeItsEnd(): void
    {
        $mail = $this->germanMail();

        $when = $mail->context('REQUEST', $this->event([
            'start' => new \DateTimeImmutable('2026-12-01'),
            'end' => new \DateTimeImmutable('2026-12-03'),
            'allDay' => true,
        ]))['when'];

        $this->assertSame('Dienstag, 1. Dezember 2026 bis Mittwoch, 2. Dezember 2026', $when['date']);
        $this->assertNull($when['time']);
    }

    public function testTheGermanEmailIsGermanThroughout(): void
    {
        $mail = $this->mail(['locale' => 'de']);

        $body = $mail->render($mail->context('REQUEST', $this->event(['recurring' => true])));

        foreach (['Jane Doe lädt Sie ein:', 'Wann', 'Teilnehmer', 'Der Termin wiederholt sich.', 'Die Einladung hängt als Datei an.', 'Antworten gehen an Jane Doe.'] as $phrase) {
            $this->assertStringContainsString($phrase, $body['text']);
            $this->assertStringContainsString(htmlspecialchars($phrase), $body['html']);
        }
        $this->assertStringContainsString('<html lang="de">', $body['html']);
    }

    public function testWithoutAnAttachmentThereIsNoWordAboutIt(): void
    {
        $mail = $this->mail(['locale' => 'de']);

        $body = $mail->render($mail->context('REQUEST', $this->event(['attached' => false])));

        $this->assertStringNotContainsString('hängt als Datei an', $body['text']);
    }

    public function testALanguageWithoutTextsFallsBackToEnglish(): void
    {
        $mail = $this->mail(['locale' => 'xx']);

        $this->assertFalse($mail->hasTranslation());
        $this->assertSame('Jane Doe invited you to "Planning"', $mail->subject($mail->context('REQUEST', $this->event())));
    }

    public function testTheBrandNameGoesIntoTheSenderAndTheFooter(): void
    {
        $mail = $this->mail(['locale' => 'de', 'brandName' => 'Example Calendar']);

        $this->assertSame('Jane Doe über Example Calendar', $mail->senderDisplayName('Jane Doe', 'jane@example.org', '(via Davis)'));
        $this->assertSame('jane@example.org über Example Calendar', $mail->senderDisplayName(null, 'jane@example.org', '(via Davis)'));

        $body = $mail->render($mail->context('REQUEST', $this->event()));
        $this->assertStringContainsString('Example Calendar hat diese Nachricht im Auftrag von Jane Doe verschickt.', $body['text']);
    }

    public function testWithoutABrandNameTheSenderIsAsBefore(): void
    {
        $mail = $this->mail();

        $this->assertSame('Jane Doe (via Davis)', $mail->senderDisplayName('Jane Doe', 'jane@example.org', '(via Davis)'));
        $this->assertSame('jane@example.org (via Davis)', $mail->senderDisplayName(null, 'jane@example.org', '(via Davis)'));
        $this->assertSame('', $mail->senderDisplayName(null, 'jane@example.org', ''));
    }

    public function testLogoColorAndFooterReachTheEmail(): void
    {
        $mail = $this->mail(['logoUrl' => 'https://example.org/logo.png', 'accentColor' => '000000', 'footer' => 'Example Ltd · 1 Example Street']);

        $body = $mail->render($mail->context('REQUEST', $this->event()));

        $this->assertStringContainsString('<img src="https://example.org/logo.png"', $body['html']);
        $this->assertStringContainsString('color: #000000', $body['html']);
        $this->assertStringNotContainsString(SchedulingMail::DEFAULT_ACCENT_COLOR, $body['html']);
        $this->assertStringContainsString('Example Ltd · 1 Example Street', $body['text']);
        $this->assertStringContainsString('Example Ltd · 1 Example Street', $body['html']);
    }

    /**
     * @dataProvider unusableLooks
     */
    public function testAnUnusableLogoOrColorIsLeftOut(array $settings, string $flag): void
    {
        $mail = $this->mail($settings);

        $this->assertTrue($mail->{$flag}());
        $this->assertNull($mail->logoUrl());
        $this->assertSame(SchedulingMail::DEFAULT_ACCENT_COLOR, $mail->accentColor());
        $html = $mail->render($mail->context('REQUEST', $this->event()))['html'];
        $this->assertStringNotContainsString('<img src="javascript', $html);
        $this->assertStringNotContainsString('red;', $html);
    }

    public static function unusableLooks(): iterable
    {
        yield 'script logo' => [['logoUrl' => 'javascript:alert(1)'], 'logoIsInvalid'];
        yield 'relative logo' => [['logoUrl' => '/logo.png'], 'logoIsInvalid'];
        yield 'css in the color' => [['accentColor' => 'red; background: url(x)'], 'accentColorIsInvalid'];
    }

    public function testWhatComesFromTheEventIsTextInTheHtml(): void
    {
        $mail = $this->mail(['brandName' => '<b>Brand</b>', 'footer' => '<a href="https://evil.example">x</a>']);

        $html = $mail->render($mail->context('REQUEST', $this->event([
            'senderName' => '<img src=x onerror=alert(1)>',
            'summary' => '<script>alert(1)</script>',
            'location' => '<a href="https://evil.example">here</a>',
            'description' => "line one\n<b>line two</b>",
        ])))['html'];

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<a href="https://evil.example"', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('line one<br />', $html);
    }

    public function testASubjectIsOneLine(): void
    {
        $mail = $this->mail();

        $this->assertSame('Jane Doe invited you to "Plan ning"', $mail->subject($mail->context('REQUEST', $this->event(['summary' => "Plan\r\nning"]))));
    }

    public function testATemplateOfOneOwnReplacesABlockOfTheShippedOne(): void
    {
        $directory = $this->templatesDir([
            'layout.html.twig' => "{% extends '@davis/layout.html.twig' %}{% block footer %}Own footer for {{ senderName }}{% endblock %}",
        ]);
        $mail = $this->mail(['templatesDir' => $directory]);

        $body = $mail->render($mail->context('REQUEST', $this->event(['senderName' => '<i>Jane</i>'])));

        $this->assertStringContainsString('Own footer for &lt;i&gt;Jane&lt;/i&gt;', $body['html']);
        $this->assertStringContainsString('Mail sent by', $body['text']);
        $this->assertSame([], $mail->brokenCustomTemplates());
    }

    public function testABrokenTemplateOfOneOwnFallsBackToTheShippedOne(): void
    {
        $directory = $this->templatesDir(['scheduling.html.twig' => '{% if %}']);
        $logger = new RecordingLogger();
        $mail = $this->mail(['templatesDir' => $directory], $logger);

        $body = $mail->render($mail->context('REQUEST', $this->event()));

        $this->assertStringContainsString('Jane Doe invited you to', $body['html']);
        $this->assertSame('warning', $logger->records[0]['level'] ?? null);
        $this->assertArrayHasKey('scheduling.html.twig', $mail->brokenCustomTemplates());
    }

    public function testADirectoryThatIsNotThereLeavesTheShippedTemplates(): void
    {
        $mail = $this->mail(['templatesDir' => '/nonexistent/davis-templates']);

        $body = $mail->render($mail->context('CANCEL', $this->event()));

        $this->assertStringContainsString('canceled', $body['text']);
        $this->assertSame([], $mail->brokenCustomTemplates());
    }

    public function testWhetherDatesCanBeGermanIsKnown(): void
    {
        $this->assertTrue($this->mail()->canFormatDates());
        $this->assertSame(
            \extension_loaded('intl') && \in_array('de', \ResourceBundle::getLocales('') ?: [], true),
            $this->mail(['locale' => 'de'])->canFormatDates()
        );
    }

    public function testADateWithAnOffsetInsteadOfAZoneIsStillFormatted(): void
    {
        $mail = $this->mail();

        $when = $mail->when(new \DateTimeImmutable('2026-12-01 00:00:00+01:00'), null, true);

        $this->assertStringContainsString('2026', (string) $when['date']);
    }

    public function testWithoutARequestTheFooterOnlySaysWhereRepliesGo(): void
    {
        self::bootKernel();
        $mail = SchedulingMailFactory::create(static::getContainer(), [], null, false);

        $text = $mail->render($mail->context('REQUEST', $this->event()))['text'];

        $this->assertStringContainsString('Replies go to Jane Doe.', $text);
        $this->assertStringNotContainsString('Mail sent by', $text);
    }
}
