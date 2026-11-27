<?php

declare(strict_types=1);

namespace App\Tests\Functional\Plugins;

use App\Plugins\DavisIMipPlugin;
use App\Tests\Functional\Double\RecordingMailer;
use App\Tests\Functional\Double\SchedulingMailFactory;
use Psr\Log\AbstractLogger;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ITip;
use Sabre\VObject\Reader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Scheduling runs inside the PUT that stores the event. Anything thrown here reaches sabre and
 * comes back to the client as a 500, with the event never written — so the plugin has to handle
 * its own failures and record why.
 */
class ImipPluginTest extends KernelTestCase
{
    private const EVENT = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Davis//Test//EN\r\nBEGIN:VEVENT\r\nUID:imip-1\r\nDTSTAMP:20260101T100000Z\r\nDTSTART:20261201T100000Z\r\nDTEND:20261201T110000Z\r\nSUMMARY:Test meeting\r\nORGANIZER;CN=Organiser:mailto:organiser@example.org\r\nATTENDEE;CN=Attendee;PARTSTAT=NEEDS-ACTION:mailto:attendee@example.org\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    private function message(string $recipient = 'mailto:attendee@example.org'): ITip\Message
    {
        $message = new ITip\Message();
        $message->method = 'REQUEST';
        $message->sequence = 1;
        $message->sender = 'mailto:organiser@example.org';
        $message->senderName = 'Organiser';
        $message->recipient = $recipient;
        $message->recipientName = 'Attendee';
        $message->significantChange = true;
        $document = Reader::read(self::EVENT);
        self::assertInstanceOf(VCalendar::class, $document);
        $message->message = $document;

        return $message;
    }

    private function plugin(MailerInterface $mailer, array &$logs, bool $emailLocalUsers = true): DavisIMipPlugin
    {
        self::bootKernel();

        $logger = new class($logs) extends AbstractLogger {
            public function __construct(private array &$logs)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->logs[] = $level.': '.$message;
            }
        };

        return new DavisIMipPlugin(
            $mailer,
            'no-reply@example.org',
            \dirname(__DIR__, 3).'/public',
            $logger,
            SchedulingMailFactory::create(static::getContainer()),
            $emailLocalUsers
        );
    }

    private function mailer(?\Throwable $failure): RecordingMailer
    {
        return new RecordingMailer($failure);
    }

    public function testAnUnreachableTransportDoesNotAbortTheSchedulingEvent(): void
    {
        $logs = [];
        $plugin = $this->plugin($this->mailer(new TransportException('Connection refused')), $logs);

        $message = $this->message();
        $plugin->schedule($message);

        $this->assertStringStartsWith('5.1', (string) $message->scheduleStatus);
        $this->assertContains('error: iMIP: the invitation could not be sent', $logs);
    }

    public function testAnAddressThatIsNotAnEmailAddressDoesNotAbortTheSchedulingEvent(): void
    {
        $logs = [];
        $plugin = $this->plugin($this->mailer(null), $logs);

        $message = $this->message('mailto:attendee(at)example.org');
        $plugin->schedule($message);

        $this->assertStringStartsWith('5.3', (string) $message->scheduleStatus);
        $this->assertContains('error: iMIP: an address in the invitation is not a valid email address, no email sent', $logs);
    }

    public function testAnUnsupportedItipMethodIsReportedInsteadOfRenderingWithoutAnAction(): void
    {
        $logs = [];
        $plugin = $this->plugin($mailer = $this->mailer(null), $logs);

        $message = $this->message();
        $message->method = 'COUNTER';
        $plugin->schedule($message);

        $this->assertStringStartsWith('5.0', (string) $message->scheduleStatus);
        $this->assertContains('warning: iMIP: unsupported iTIP method, no email sent', $logs);
        $this->assertSame([], $mailer->sent);
    }

    public function testANonMailtoRecipientIsLogged(): void
    {
        $logs = [];
        $plugin = $this->plugin($mailer = $this->mailer(null), $logs);

        $message = $this->message('https://example.org/attendee');
        $plugin->schedule($message);

        $this->assertContains('warning: iMIP: not an email exchange, no invitation sent', $logs);
        $this->assertSame([], $mailer->sent);
    }

    /**
     * `VEVENT.URL` comes from whoever created the event and the invitation makes it clickable in
     * the recipient's mail client, so only web and mail addresses get through.
     *
     * @dataProvider urls
     */
    public function testOnlyLinkableUrlsReachTheInvitation(string $url, bool $expected): void
    {
        $logs = [];
        $plugin = $this->plugin($mailer = $this->mailer(null), $logs);

        $message = $this->message();
        $message->message->VEVENT->add('URL', $url);
        $plugin->schedule($message);

        $this->assertCount(1, $mailer->sent);
        $this->assertInstanceOf(Email::class, $mailer->sent[0]);

        // Both parts show the address only when it is offered as a link
        $html = $mailer->sent[0]->getHtmlBody();
        $text = $mailer->sent[0]->getTextBody();
        $this->assertIsString($html);
        $this->assertIsString($text);
        if ($expected) {
            $this->assertStringContainsString('href="'.htmlspecialchars($url).'"', $html, $url.' should be offered');
            $this->assertStringContainsString($url, $text);
        } else {
            $this->assertStringNotContainsString(htmlspecialchars($url), $html, $url.' should not be offered');
            $this->assertStringNotContainsString($url, $text);
        }
    }

    public static function urls(): iterable
    {
        yield 'https' => ['https://example.org/meeting', true];
        yield 'http' => ['http://example.org/meeting', true];
        yield 'mailto' => ['mailto:someone@example.org', true];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'data' => ['data:text/html,<script>alert(1)</script>', false];
        yield 'file' => ['file:///etc/passwd', false];
        yield 'no scheme' => ['example.org/meeting', false];
    }

    public function testASuccessfulSendIsLoggedAndReported(): void
    {
        $logs = [];
        $plugin = $this->plugin($mailer = $this->mailer(null), $logs);

        $message = $this->message();
        $plugin->schedule($message);

        $this->assertStringStartsWith('1.1', (string) $message->scheduleStatus);
        $this->assertContains('info: iMIP: invitation sent', $logs);
        $this->assertCount(1, $mailer->sent);
    }

    /**
     * sabre has already put the message into the calendar of a user of this server (1.2).
     */
    public function testUsersOfThisServerGetNoEmailWhenSwitchedOff(): void
    {
        $logs = [];
        $plugin = $this->plugin($mailer = $this->mailer(null), $logs, false);

        $message = $this->message();
        $message->scheduleStatus = '1.2;The message was delivered locally';
        $plugin->schedule($message);

        $this->assertSame([], $mailer->sent);
        $this->assertStringStartsWith('1.2', $message->scheduleStatus);
    }

    public function testUsersOfThisServerGetAnEmailWithoutAttachmentByDefault(): void
    {
        $logs = [];
        $plugin = $this->plugin($mailer = $this->mailer(null), $logs);

        $message = $this->message();
        $message->scheduleStatus = '1.2;The message was delivered locally';
        $plugin->schedule($message);

        $this->assertCount(1, $mailer->sent);
        $this->assertInstanceOf(Email::class, $mailer->sent[0]);
        $this->assertSame([], $mailer->sent[0]->getAttachments());
        $this->assertStringStartsWith('1.2', $message->scheduleStatus);
    }

    public function testWithoutSettingsTheEmailReadsAsBefore(): void
    {
        $logs = [];
        $plugin = $this->plugin($mailer = $this->mailer(null), $logs);

        $plugin->schedule($this->message());

        $email = $mailer->sent[0];
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame('Organiser invited you to "Test meeting"', $email->getSubject());
        $this->assertSame('Organiser (via Davis)', $email->getFrom()[0]->getName());
        $this->assertSame('organiser@example.org', $email->getReplyTo()[0]->getAddress());
        $this->assertStringContainsString('Mail sent by https://dav.example.org', (string) $email->getTextBody());
        $this->assertCount(1, $email->getAttachments());
    }
}
