<?php

declare(strict_types=1);

namespace App\Tests\Functional\Plugins;

use App\Plugins\DavisIMipPlugin;
use App\Tests\Functional\Double\RecordingLogger;
use App\Tests\Functional\Double\RecordingMailer;
use App\Tests\Functional\Double\SchedulingMailFactory;
use Doctrine\ORM\EntityManagerInterface;
use Sabre\CalDAV;
use Sabre\CalDAV\Backend\PDO as CalendarBackend;
use Sabre\DAV;
use Sabre\DAVACL\PrincipalBackend\PDO as PrincipalBackend;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/**
 * An update reaches the attendees as a REQUEST, like the first invitation, and an attendee who
 * is removed gets a CANCEL, like everybody when the event is called off. These run sabre's own
 * scheduling plugin on a stored event and check which email each attendee gets.
 */
class ImipMessageKindsTest extends KernelTestCase
{
    private const CALENDAR_PATH = 'calendars/test_user/default';
    private const ORGANIZER = 'mailto:test@test.com';

    private DAV\Server $server;
    private CalendarBackend $calendarBackend;
    private RecordingMailer $mailer;
    private string $uri;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var \PDO $pdo */
        $pdo = $container->get(EntityManagerInterface::class)->getConnection()->getNativeConnection();

        $principalBackend = new PrincipalBackend($pdo);
        $this->calendarBackend = new CalendarBackend($pdo);
        $this->mailer = new RecordingMailer();

        $this->server = new DAV\Server([
            new CalDAV\Principal\Collection($principalBackend),
            new CalDAV\CalendarRoot($principalBackend, $this->calendarBackend),
        ]);
        $this->server->setBaseUri('/dav/');
        $this->server->addPlugin(new CalDAV\Plugin());
        $this->server->addPlugin(new CalDAV\Schedule\Plugin());
        $this->server->addPlugin(new DavisIMipPlugin($this->mailer, 'davis@test.com', '', new RecordingLogger(), SchedulingMailFactory::create($container)));

        $this->uri = 'kinds-'.uniqid().'.ics';
        $this->server->httpRequest = $this->request('PUT');
    }

    private function request(string $method): Request
    {
        $request = new Request($method, '/dav/'.self::CALENDAR_PATH.'/'.$this->uri);
        // What DAV\Server::exec() does, so that getPath() is relative to the DAV root
        $request->setBaseUrl('/dav/');

        return $request;
    }

    private function event(array $attendees, string $extra = '', string $start = '20261201T100000Z'): string
    {
        $lines = '';
        foreach ($attendees as $attendee) {
            $lines .= 'ATTENDEE;CN='.ucfirst(explode('@', $attendee)[0]).';PARTSTAT=NEEDS-ACTION:mailto:'.$attendee."\r\n";
        }

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Davis//Test//EN\r\nBEGIN:VEVENT\r\nUID:kinds-".$this->uri."\r\n"
            ."DTSTAMP:20261101T100000Z\r\nDTSTART:".$start."\r\nDTEND:20261201T110000Z\r\nSUMMARY:Planning\r\n"
            .'ORGANIZER;CN=Organiser:'.self::ORGANIZER."\r\n".$lines.$extra
            ."END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function calendarId()
    {
        foreach ($this->calendarBackend->getCalendarsForUser('principals/test_user') as $calendar) {
            if ('default' === $calendar['uri']) {
                return $calendar['id'];
            }
        }

        $this->fail('The fixtures should provide a default calendar for test_user');
    }

    /**
     * What a PUT does: sabre compares the stored version with the new one, then it is written.
     */
    private function put(string $ics, bool $isNew): void
    {
        $vCal = Reader::read($ics);
        $this->assertInstanceOf(VCalendar::class, $vCal);
        $modified = false;
        $this->server->emit('calendarObjectChange', [$this->server->httpRequest, new Response(), $vCal, self::CALENDAR_PATH, &$modified, $isNew]);

        if ($isNew) {
            $this->calendarBackend->createCalendarObject($this->calendarId(), $this->uri, $vCal->serialize());
        } else {
            $this->calendarBackend->updateCalendarObject($this->calendarId(), $this->uri, $vCal->serialize());
        }
    }

    /**
     * @return array<string, string> recipient => subject
     */
    private function sentSubjects(): array
    {
        $subjects = [];
        foreach ($this->mailer->sent as $email) {
            $this->assertInstanceOf(Email::class, $email);
            $subjects[$email->getTo()[0]->getAddress()] = (string) $email->getSubject();
        }
        $this->mailer->sent = [];

        return $subjects;
    }

    public function testANewEventIsAnInvitation(): void
    {
        $this->put($this->event(['anna@elsewhere.test']), true);

        $this->assertSame(['anna@elsewhere.test' => 'Organiser invited you to "Planning"'], $this->sentSubjects());
    }

    public function testAChangeIsAnUpdateForThoseInvitedAndAnInvitationForNewcomers(): void
    {
        $this->put($this->event(['anna@elsewhere.test']), true);
        $this->sentSubjects();

        $this->put($this->event(['anna@elsewhere.test', 'ben@elsewhere.test'], '', '20261201T093000Z'), false);

        $this->assertSame([
            'anna@elsewhere.test' => 'Organiser updated "Planning"',
            'ben@elsewhere.test' => 'Organiser invited you to "Planning"',
        ], $this->sentSubjects());
    }

    public function testARemovedAttendeeIsToldTheyAreNoLongerInvited(): void
    {
        $this->put($this->event(['anna@elsewhere.test', 'ben@elsewhere.test']), true);
        $this->sentSubjects();

        $this->put($this->event(['ben@elsewhere.test']), false);

        $this->assertSame('You are no longer invited to "Planning"', $this->sentSubjects()['anna@elsewhere.test'] ?? null);
    }

    public function testCallingTheEventOffIsACancellation(): void
    {
        $this->put($this->event(['anna@elsewhere.test']), true);
        $this->sentSubjects();

        $this->put($this->event(['anna@elsewhere.test'], "STATUS:CANCELLED\r\n"), false);

        $this->assertSame(['anna@elsewhere.test' => '"Planning" has been canceled.'], $this->sentSubjects());
    }

    public function testDeletingTheEventIsACancellation(): void
    {
        $this->put($this->event(['anna@elsewhere.test']), true);
        $this->sentSubjects();

        $this->server->httpRequest = $this->request('DELETE');
        $this->server->emit('beforeUnbind', [self::CALENDAR_PATH.'/'.$this->uri]);

        $this->assertSame(['anna@elsewhere.test' => '"Planning" has been canceled.'], $this->sentSubjects());
    }
}
