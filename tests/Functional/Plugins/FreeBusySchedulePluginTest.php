<?php

declare(strict_types=1);

namespace App\Tests\Functional\Plugins;

use App\Plugins\FreeBusySchedulePlugin;
use App\Plugins\PublicAwareDAVACLPlugin;
use Doctrine\ORM\EntityManagerInterface;
use Sabre\CalDAV;
use Sabre\CalDAV\Backend\PDO as CalendarBackend;
use Sabre\DAV;
use Sabre\DAV\PropertyStorage\Backend\PDO as PropertyStorageBackend;
use Sabre\DAV\Xml\Element\Sharee;
use Sabre\DAVACL\PrincipalBackend\PDO as PrincipalBackend;
use Sabre\HTTP\Request;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\Response;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Reader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A free-busy request (RFC 6638) for another user of the server, made by a user who is not an
 * admin, through the same plugins as DAVController: sabre's own answer is 3.7 because the
 * principal is not readable; with the lookup enabled the busy periods come back, and nothing else.
 */
class FreeBusySchedulePluginTest extends KernelTestCase
{
    // From the fixtures: test_user is an admin, test_user2 is not
    private const ASKING = 'principals/test_user2'; // its outbox is the one posted to
    private const ASKING_ADDRESS = 'mailto:test2@test.com';
    private const ASKED_ADDRESS = 'mailto:test@test.com';

    private EntityManagerInterface $em;
    private \PDO $pdo;
    private CalendarBackend $calendarBackend;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var \PDO $pdo */
        $pdo = $this->em->getConnection()->getNativeConnection();
        $this->pdo = $pdo;
        $this->calendarBackend = new CalendarBackend($pdo);

        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    private function server(bool $lookupEnabled, string $principal = self::ASKING): DAV\Server
    {
        $principalBackend = new PrincipalBackend($this->pdo);

        $server = new DAV\Server([
            new CalDAV\Principal\Collection($principalBackend),
            new CalDAV\CalendarRoot($principalBackend, $this->calendarBackend),
        ]);
        $server->setBaseUri('/dav/');

        $auth = new class($principal) implements DAV\Auth\Backend\BackendInterface {
            public function __construct(private string $principal)
            {
            }

            public function check(RequestInterface $request, ResponseInterface $response): array
            {
                return [true, $this->principal];
            }

            public function challenge(RequestInterface $request, ResponseInterface $response): void
            {
            }
        };
        $server->addPlugin(new DAV\Auth\Plugin($auth));

        // As in DAVController
        $acl = new PublicAwareDAVACLPlugin($this->em, true);
        $acl->hideNodesFromListings = true;
        $acl->adminPrincipals[] = 'principals/test_user';
        $server->addPlugin($acl);

        $server->addPlugin(new DAV\PropertyStorage\Plugin(new PropertyStorageBackend($this->pdo)));
        $server->addPlugin(new DAV\Sharing\Plugin());
        $server->addPlugin(new CalDAV\Plugin());
        $server->addPlugin(new FreeBusySchedulePlugin($lookupEnabled));
        $server->addPlugin(new CalDAV\SharingPlugin());

        return $server;
    }

    private function calendarId(string $user): array
    {
        foreach ($this->calendarBackend->getCalendarsForUser('principals/'.$user) as $calendar) {
            if ('default' === $calendar['uri']) {
                return $calendar['id'];
            }
        }

        $this->fail('The fixtures should provide a default calendar for '.$user);
    }

    private function addEvent(string $user, string $start, string $end, string $extra = ''): void
    {
        $uid = uniqid('fb-');
        $this->calendarBackend->createCalendarObject($this->calendarId($user), $uid.'.ics',
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Davis//Test//EN\r\nBEGIN:VEVENT\r\nUID:".$uid."\r\n"
            .'DTSTAMP:20261101T100000Z'."\r\nDTSTART:".$start."\r\nDTEND:".$end."\r\nSUMMARY:Salary talk with Anna\r\n"
            .'LOCATION:Room 4'."\r\n".$extra."END:VEVENT\r\nEND:VCALENDAR\r\n");
    }

    /**
     * @return array<string, array{status: string, data: string}> keyed by recipient
     */
    private function askFor(DAV\Server $server, string ...$attendees): array
    {
        $lines = '';
        foreach ($attendees as $attendee) {
            $lines .= 'ATTENDEE:'.$attendee."\r\n";
        }
        $body = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Davis//Test//EN\r\nMETHOD:REQUEST\r\nBEGIN:VFREEBUSY\r\n"
            ."UID:fb-request\r\nDTSTAMP:20261101T100000Z\r\nDTSTART:20261201T080000Z\r\nDTEND:20261201T180000Z\r\n"
            .'ORGANIZER:'.self::ASKING_ADDRESS."\r\n".$lines."END:VFREEBUSY\r\nEND:VCALENDAR\r\n";

        $request = new Request('POST', '/dav/calendars/test_user2/outbox/', ['Content-Type' => 'text/calendar'], $body);
        $request->setBaseUrl('/dav/');
        $response = new Response();
        $server->httpRequest = $request;
        $server->httpResponse = $response;
        $server->invokeMethod($request, $response, false);

        $this->assertSame(200, $response->getStatus(), $response->getBodyAsString());

        $body = $response->getBodyAsString();
        if ('' === $body) {
            $this->fail('The answer has no body');
        }
        $xml = new \DOMDocument();
        $xml->loadXML($body);
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');

        $answers = [];
        foreach ($xpath->query('//c:response') as $node) {
            $recipient = trim($xpath->evaluate('string(c:recipient/d:href)', $node));
            $answers[$recipient] = [
                'status' => trim($xpath->evaluate('string(c:request-status)', $node)),
                'data' => $xpath->evaluate('string(c:calendar-data)', $node),
            ];
        }

        return $answers;
    }

    /**
     * @return string[] busy periods as "start/end", UTC
     */
    private function busy(string $calendarData, string $type = 'BUSY'): array
    {
        $vcalendar = Reader::read($calendarData);
        $periods = [];
        foreach ($vcalendar->select('VFREEBUSY') as $vfreebusy) {
            foreach ($vfreebusy->select('FREEBUSY') as $freebusy) {
                if ($type !== (string) ($freebusy['FBTYPE'] ?? 'BUSY')) {
                    continue;
                }
                foreach (explode(',', (string) $freebusy) as $period) {
                    $periods[] = $period;
                }
            }
        }

        return $periods;
    }

    public function testSabreAloneDoesNotAnswerForAnotherUser(): void
    {
        $this->addEvent('test_user', '20261201T100000Z', '20261201T110000Z');

        // sabre's principal search trips over the hidden principal on the way (an undefined array
        // key in its ACL plugin); that is sabre's to fix, this test is about the answer
        set_error_handler(fn (int $type, string $message, string $file) => \E_WARNING === $type && str_contains($file, 'DAVACL'));
        try {
            $answer = $this->askFor($this->server(false), self::ASKED_ADDRESS)[self::ASKED_ADDRESS];
        } finally {
            restore_error_handler();
        }

        $this->assertStringStartsWith('3.7', $answer['status']);
    }

    public function testAnotherUsersBusyTimesComeBackWithoutAnyDetail(): void
    {
        $this->addEvent('test_user', '20261201T100000Z', '20261201T110000Z');
        $this->addEvent('test_user', '20261201T120000Z', '20261201T130000Z', "TRANSP:TRANSPARENT\r\n");
        $this->addEvent('test_user', '20261201T140000Z', '20261201T150000Z', "STATUS:CANCELLED\r\n");

        $answer = $this->askFor($this->server(true), self::ASKED_ADDRESS)[self::ASKED_ADDRESS];

        $this->assertStringStartsWith('2.0', $answer['status']);
        $this->assertSame(['20261201T100000Z/20261201T110000Z'], $this->busy($answer['data']));
        $this->assertStringNotContainsString('Salary', $answer['data']);
        $this->assertStringNotContainsString('Room 4', $answer['data']);
        $this->assertStringNotContainsString('SUMMARY', $answer['data']);
    }

    public function testOnesOwnAddressStillWorks(): void
    {
        $this->addEvent('test_user2', '20261201T090000Z', '20261201T093000Z');

        $answer = $this->askFor($this->server(true), self::ASKING_ADDRESS)[self::ASKING_ADDRESS];

        $this->assertStringStartsWith('2.0', $answer['status']);
        $this->assertSame(['20261201T090000Z/20261201T093000Z'], $this->busy($answer['data']));
    }

    public function testEachAttendeeIsAnsweredOnItsOwn(): void
    {
        $answers = $this->askFor($this->server(true), self::ASKED_ADDRESS, 'mailto:nobody@test.com', 'mailto:est@test.com');

        $this->assertStringStartsWith('2.0', $answers[self::ASKED_ADDRESS]['status']);
        $this->assertStringStartsWith('3.7', $answers['mailto:nobody@test.com']['status']);
        // The principal backend searches with LIKE: a part of an address must not find its owner
        $this->assertStringStartsWith('3.7', $answers['mailto:est@test.com']['status']);
    }

    public function testCalendarsSharedWithTheUserDoNotCount(): void
    {
        // test_user2 shares their calendar with test_user, who accepts it
        $this->calendarBackend->updateInvites($this->calendarId('test_user2'), [new Sharee([
            'href' => self::ASKED_ADDRESS,
            'principal' => 'principals/test_user',
            'access' => DAV\Sharing\Plugin::ACCESS_READ,
            'inviteStatus' => DAV\Sharing\Plugin::INVITE_ACCEPTED,
        ])]);
        $this->addEvent('test_user2', '20261201T160000Z', '20261201T170000Z');
        $this->addEvent('test_user', '20261201T100000Z', '20261201T110000Z');

        $answer = $this->askFor($this->server(true), self::ASKED_ADDRESS)[self::ASKED_ADDRESS];

        $this->assertSame(['20261201T100000Z/20261201T110000Z'], $this->busy($answer['data']));
    }

    public function testACalendarMarkedTransparentDoesNotCount(): void
    {
        $this->calendarBackend->updateCalendar($this->calendarId('test_user'), $patch = new DAV\PropPatch([
            '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp' => new CalDAV\Xml\Property\ScheduleCalendarTransp('transparent'),
        ]));
        $patch->commit();
        $this->addEvent('test_user', '20261201T100000Z', '20261201T110000Z');

        $answer = $this->askFor($this->server(true), self::ASKED_ADDRESS)[self::ASKED_ADDRESS];

        $this->assertStringStartsWith('2.0', $answer['status']);
        $this->assertSame([], $this->busy($answer['data']));
    }

    public function testTheStoredAvailabilityOfTheOtherUserApplies(): void
    {
        $availability = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\nBEGIN:VAVAILABILITY\r\nUID:av\r\nDTSTAMP:20261101T000000Z\r\n"
            ."BEGIN:AVAILABLE\r\nUID:av-1\r\nDTSTAMP:20261101T000000Z\r\nDTSTART:20261201T090000Z\r\nDTEND:20261201T170000Z\r\nEND:AVAILABLE\r\n"
            ."END:VAVAILABILITY\r\nEND:VCALENDAR\r\n";
        $patch = new DAV\PropPatch(['{urn:ietf:params:xml:ns:caldav}calendar-availability' => $availability]);
        (new PropertyStorageBackend($this->pdo))->propPatch('calendars/test_user/inbox', $patch);
        $patch->commit();

        $answer = $this->askFor($this->server(true), self::ASKED_ADDRESS)[self::ASKED_ADDRESS];

        $this->assertStringStartsWith('2.0', $answer['status']);
        $this->assertSame(
            ['20261201T080000Z/20261201T090000Z', '20261201T170000Z/20261201T180000Z'],
            $this->busy($answer['data'], 'BUSY-UNAVAILABLE')
        );
    }

    public function testThePrincipalStaysUnreadable(): void
    {
        $server = $this->server(true);
        $this->askFor($server, self::ASKED_ADDRESS);

        // After the request, so that the user is authenticated
        $properties = $server->getPropertiesForPath('principals/test_user', ['{urn:ietf:params:xml:ns:caldav}calendar-home-set'], 0);

        $this->assertSame([], $properties, 'Hidden from a user who is neither its owner nor an admin');
    }
}
