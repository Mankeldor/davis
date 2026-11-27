<?php

namespace App\Plugins;

use App\Services\SchedulingMail;
use DantSu\OpenStreetMapStaticAPI\LatLng;
use DantSu\OpenStreetMapStaticAPI\Markers;
use DantSu\OpenStreetMapStaticAPI\OpenStreetMap;
use Psr\Log\LoggerInterface;
use Sabre\CalDAV\Schedule\IMipPlugin as SabreBaseIMipPlugin;
use Sabre\DAV;
use Sabre\VObject;
use Sabre\VObject\ITip;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * iMIP handler.
 */
final class DavisIMipPlugin extends SabreBaseIMipPlugin
{
    public const MESSAGE_ORIGIN_INDICATOR = '(via Davis)';

    /**
     * @var MailerInterface
     */
    private $mailer;

    /**
     * @var string
     */
    protected $senderEmail;

    /**
     * @var string
     */
    protected $publicDir;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var DAV\Server
     */
    private $server;

    /**
     * @var SchedulingMail
     */
    private $mail;

    /**
     * @var bool
     */
    private $emailLocalUsers;

    /**
     * What the event looked like before the change that is being scheduled, by UID.
     *
     * sabre's broker knows whether an attendee was already invited and whether an event was
     * deleted, but the iTIP message it hands over says neither: an update is a REQUEST like the
     * first invitation, and an attendee who was removed gets the same CANCEL as everybody when
     * the event is called off. This is recorded just before sabre compares the two versions.
     *
     * @var array<string, array{attendees: string[], deleted: bool, cancelled: bool}>
     */
    private $changes = [];

    /**
     * Creates the email handler.
     *
     * @param bool $emailLocalUsers whether users of this server get an email too, on top of the
     *                              invitation that sabre puts into their calendar
     */
    public function __construct(MailerInterface $mailer, string $senderEmail, string $publicDir, LoggerInterface $logger, SchedulingMail $mail, bool $emailLocalUsers = true)
    {
        $this->mailer = $mailer;
        $this->senderEmail = $senderEmail;
        $this->publicDir = $publicDir;
        $this->logger = $logger;
        $this->mail = $mail;
        $this->emailLocalUsers = $emailLocalUsers;
    }

    public function initialize(DAV\Server $server)
    {
        parent::initialize($server);

        $this->server = $server;

        // sabre's scheduling plugin compares the old and the new version on these two events, at
        // the default priority (100). Running just before it is the last moment the old version
        // can still be read.
        $server->on('calendarObjectChange', function ($request, $response, VObject\Component\VCalendar $vCal, $calendarPath, $modified, $isNew) {
            $this->rememberChange($request->getPath(), $vCal, (bool) $isNew);
        }, 90);
        $server->on('beforeUnbind', function (string $path) {
            // sabre does not schedule a MOVE either
            if ('MOVE' !== $this->server->httpRequest->getMethod()) {
                $this->rememberChange($path, null, false);
            }
        }, 90);

        // sabre only emits `schedule` when it has decided to send an iTIP message. These two
        // events fire on every calendar object that gets written, sent or not.
        $server->on('afterCreateFile', function (string $path) {
            $this->explainMissingScheduling($path);
        });
        // This one already hands us the node, so there is no need to look it up again
        $server->on('afterWriteContent', function (string $path, DAV\INode $node) {
            $this->explainMissingScheduling($path, $node);
        });
    }

    /**
     * Logs why an event with attendees did not produce an invitation.
     *
     * When sabre decides not to schedule, it saves the event and emits nothing, so `schedule()`
     * below is never called and the log stays empty. This runs the same two checks sabre runs
     * (`Sabre\CalDAV\Schedule\Plugin::calendarObjectChange()`) and logs the one that failed.
     *
     * It runs after the object has been written, so it cannot affect the write.
     */
    public function explainMissingScheduling(string $path, ?DAV\INode $node = null): void
    {
        try {
            $node = $node ?? $this->server->tree->getNodeForPath($path);
            if (!$node instanceof \Sabre\CalDAV\ICalendarObject) {
                return;
            }

            $vCal = VObject\Reader::read($node->get());
            if (!isset($vCal->VEVENT)) {
                return;
            }

            $vevent = $vCal->VEVENT;

            // An event with no attendees or no organiser is never scheduled
            if (!isset($vevent->ATTENDEE) || !isset($vevent->ORGANIZER)) {
                return;
            }

            if ('F' === $this->server->httpRequest->getHeader('Schedule-Reply')) {
                $this->logger->warning('Scheduling: the client asked not to send invitations for this event (Schedule-Reply: F).', ['path' => $path]);

                return;
            }

            $organizer = (string) $vevent->ORGANIZER;
            $addresses = $this->addressesForCalendarOf($path);

            if (!\in_array($organizer, $addresses, true)) {
                $this->logger->warning(
                    'Scheduling: no invitation will be sent because the event organiser is not one of this account\'s calendar addresses. Set the account\'s email to the address its client sends as ORGANIZER.',
                    [
                        'organizer' => $organizer,
                        'account_addresses' => $addresses ?: '(none: the principal has no email)',
                        'path' => $path,
                    ]
                );

                return;
            }

            foreach ($vevent->ATTENDEE as $attendee) {
                if (!$attendee instanceof VObject\Property) {
                    continue;
                }
                $scheduleAgent = $attendee['SCHEDULE-AGENT'];
                if ($scheduleAgent instanceof VObject\Parameter && 'CLIENT' === strtoupper((string) $scheduleAgent->getValue())) {
                    $this->logger->warning('Scheduling: an attendee is marked SCHEDULE-AGENT=CLIENT, so the client sends that invitation itself and Davis does not.', [
                        'attendee' => (string) $attendee,
                        'path' => $path,
                    ]);
                }
            }
        } catch (\Exception $e) {
            // This only logs, so never let it break a write that already succeeded
            $this->logger->debug('Scheduling: could not inspect the stored object', ['path' => $path, 'exception' => $e->getMessage()]);
        }
    }

    /**
     * Returns the addresses of the account that owns this calendar, read from the same
     * `calendar-user-address-set` property sabre uses.
     *
     * @return string[]
     */
    private function addressesForCalendarOf(string $path): array
    {
        $calendar = $this->server->tree->getNodeForPath(\dirname($path));
        if (!$calendar instanceof \Sabre\DAVACL\IACL) {
            return [];
        }

        $owner = $calendar->getOwner();
        if (!$owner) {
            return [];
        }

        $addressSet = '{'.\Sabre\CalDAV\Plugin::NS_CALDAV.'}calendar-user-address-set';
        $properties = $this->server->getProperties($owner, [$addressSet]);

        return isset($properties[$addressSet]) ? $properties[$addressSet]->getHrefs() : [];
    }

    /**
     * Records who was invited before this change, and whether the event is going away.
     *
     * @param VObject\Component\VCalendar|null $new null when the object is deleted
     */
    public function rememberChange(string $path, ?VObject\Component\VCalendar $new, bool $isNew): void
    {
        try {
            $old = null;
            if (!$isNew) {
                $node = $this->server->tree->getNodeForPath($path);
                if (!$node instanceof \Sabre\CalDAV\ICalendarObject) {
                    return;
                }
                $old = VObject\Reader::read($node->get());
            }

            $uid = $this->uidOf($new) ?? ($old instanceof VObject\Component\VCalendar ? $this->uidOf($old) : null);
            if (null === $uid) {
                return;
            }

            $attendees = [];
            foreach ($old ? $old->select('VEVENT') : [] as $vevent) {
                foreach ($vevent->select('ATTENDEE') as $attendee) {
                    if ($attendee instanceof VObject\Property\ICalendar\CalAddress) {
                        $attendees[] = strtolower($attendee->getNormalizedValue());
                    }
                }
            }

            $master = $new ? $this->primaryEvent($new) : null;
            $status = $master ? $this->property($master, 'STATUS') : null;

            $this->changes[$uid] = [
                'attendees' => array_values(array_unique($attendees)),
                'deleted' => null === $new,
                'cancelled' => $status && 'CANCELLED' === strtoupper((string) $status->getValue()),
            ];
        } catch (\Exception $e) {
            // Without it the email says "invited" instead of "updated"; not worth failing a write for
            $this->logger->debug('iMIP: could not read the previous version of the event', ['path' => $path, 'exception' => $e->getMessage()]);
        }
    }

    private function uidOf(?VObject\Component\VCalendar $calendar): ?string
    {
        $event = $calendar ? $this->primaryEvent($calendar) : null;
        $uid = $event ? $this->property($event, 'UID') : null;

        return $uid ? (string) $uid->getValue() : null;
    }

    /**
     * The event itself rather than one of its exceptions, when there is one.
     */
    private function primaryEvent(VObject\Component\VCalendar $calendar): ?VObject\Component\VEvent
    {
        $first = null;
        foreach ($calendar->select('VEVENT') as $vevent) {
            if (!$vevent instanceof VObject\Component\VEvent) {
                continue;
            }
            if (null === $this->property($vevent, 'RECURRENCE-ID')) {
                return $vevent;
            }
            $first ??= $vevent;
        }

        return $first;
    }

    private function property(VObject\Component $component, string $name): ?VObject\Property
    {
        $found = $component->select($name);
        $first = reset($found);

        return $first instanceof VObject\Property ? $first : null;
    }

    private function parameter(VObject\Property $property, string $name): ?string
    {
        $parameter = $property->parameters()[$name] ?? null;

        return $parameter instanceof VObject\Parameter ? (string) $parameter->getValue() : null;
    }

    /**
     * Which of the emails this message is.
     */
    private function actionFor(ITip\Message $iTipMessage): ?string
    {
        $change = null !== $iTipMessage->uid ? ($this->changes[$iTipMessage->uid] ?? null) : null;

        switch (strtoupper($iTipMessage->method)) {
            case 'REQUEST':
                return $change && \in_array(strtolower($iTipMessage->recipient), $change['attendees'], true) ? 'UPDATE' : 'REQUEST';
            case 'CANCEL':
                // The event still exists and is not called off: only this attendee was removed
                return $change && !$change['deleted'] && !$change['cancelled'] ? 'UNINVITED' : 'CANCEL';
            case 'REPLY':
                $partstat = strtoupper((string) $iTipMessage->message->VEVENT->ATTENDEE['PARTSTAT']);

                return \in_array($partstat, ['ACCEPTED', 'TENTATIVE', 'DECLINED'], true) ? $partstat : null;
        }

        return null;
    }

    /**
     * Event handler for the 'schedule' event.
     */
    public function schedule(ITip\Message $iTipMessage)
    {
        // Not sending any emails if the system considers the update
        // insignificant.
        if (!$iTipMessage->significantChange) {
            $this->logger->debug('iMIP: no email for an insignificant change', ['method' => $iTipMessage->method, 'recipient' => $iTipMessage->recipient]);

            if (empty($iTipMessage->scheduleStatus)) {
                $iTipMessage->scheduleStatus = '1.0;We got the message, but it\'s not significant enough to warrant an email';
            }

            return;
        }

        if ('mailto' !== parse_url($iTipMessage->sender, PHP_URL_SCHEME)
            || 'mailto' !== parse_url($iTipMessage->recipient, PHP_URL_SCHEME)) {
            // iTIP allows other schemes; this plugin only knows how to send email.
            $this->logger->warning('iMIP: not an email exchange, no invitation sent', ['sender' => $iTipMessage->sender, 'recipient' => $iTipMessage->recipient]);

            return;
        }

        $deliveredLocally = '1.2' === $iTipMessage->getScheduleStatus();

        if ($deliveredLocally && !$this->emailLocalUsers) {
            // sabre has already put the message into the recipient's calendar, where they answer it
            $this->logger->debug('iMIP: the recipient is a user of this server, no email (INVITE_EMAIL_LOCAL_USERS=false)', ['recipient' => $iTipMessage->recipient, 'method' => $iTipMessage->method]);

            return;
        }

        // 7 is the length of `mailto:`.
        $senderEmail = substr($iTipMessage->sender, 7);
        $recipientEmail = substr($iTipMessage->recipient, 7);

        // We fallback the senderName to the email if the iTIP originator does not send a name along.
        $senderName = $iTipMessage->senderName ?? $senderEmail;
        $recipientName = $iTipMessage->recipientName;

        // For a REPLY, the attendee is the sender. If the stored event had no CN for
        // them, the Broker leaves it out of the iTIP payload and some mail clients
        // (Apple Mail for instance) render the attendee name as "(null)".
        // We put the fallback name in the payload too. A REPLY can carry one VEVENT
        // per recurrence instance, so we go through all of them.
        if ('REPLY' === strtoupper($iTipMessage->method)) {
            foreach ($iTipMessage->message->VEVENT as $vevent) {
                if (isset($vevent->ATTENDEE) && !isset($vevent->ATTENDEE['CN'])) {
                    $vevent->ATTENDEE['CN'] = $senderName;
                }
            }
        }

        // Anything other than these leaves the template without an action to render, so the
        // message is not one this plugin knows how to word.
        $action = $this->actionFor($iTipMessage);
        if (null === $action) {
            if ('REPLY' === strtoupper($iTipMessage->method)) {
                $this->logger->warning('iMIP: unsupported PARTSTAT in a REPLY, no email sent', ['partstat' => (string) $iTipMessage->message->VEVENT->ATTENDEE['PARTSTAT'], 'recipient' => $iTipMessage->recipient]);
                $iTipMessage->scheduleStatus = '5.0;Email not delivered. We didn\'t understand this PARTSTAT.';
            } else {
                $this->logger->warning('iMIP: unsupported iTIP method, no email sent', ['method' => $iTipMessage->method, 'recipient' => $iTipMessage->recipient]);
                $iTipMessage->scheduleStatus = '5.0;Email not delivered. We don\'t know how to send this iTIP method.';
            }

            return;
        }

        // Construct objects for the mail template. A message about a recurring event carries
        // the event and its exceptions; the event itself is what the email describes.
        $vevent = $this->primaryEvent($iTipMessage->message) ?? $iTipMessage->message->VEVENT;

        $dtStart = $this->property($vevent, 'DTSTART');
        $start = $dtStart instanceof VObject\Property\ICalendar\DateTime ? $dtStart->getDateTime() : new \DateTimeImmutable('now');
        $allDay = $dtStart instanceof VObject\Property\ICalendar\DateTime && !$dtStart->hasTime();

        $end = null;
        $dtEnd = $this->property($vevent, 'DTEND');
        $duration = $this->property($vevent, 'DURATION');
        if ($dtEnd instanceof VObject\Property\ICalendar\DateTime) {
            $end = $dtEnd->getDateTime();
        } elseif ($duration instanceof VObject\Property\ICalendar\Duration && $dtStart instanceof VObject\Property\ICalendar\DateTime) {
            $end = \DateTimeImmutable::createFromInterface($start)->add($duration->getDateInterval());
        }

        $attendees = [];
        foreach ($vevent->select('ATTENDEE') as $attendee) {
            if (!$attendee instanceof VObject\Property\ICalendar\CalAddress) {
                continue;
            }
            // The address is the value of the property; the EMAIL parameter, when there is
            // one, is the one to write to.
            $email = $this->parameter($attendee, 'EMAIL')
                ?? (string) preg_replace('/^mailto:/i', '', $attendee->getNormalizedValue());
            $attendees[] = [
                'cn' => $this->parameter($attendee, 'CN') ?? $email,
                'email' => '' !== $email ? $email : null,
                'role' => $this->parameter($attendee, 'ROLE'),
            ];
        }
        usort($attendees, function ($a, $b) {
            if ('CHAIR' === $a['role']) {
                return -1;
            }

            return 1;
        });

        $notEmpty = function ($property, $else) use ($vevent) {
            if (isset($vevent->$property)) {
                $handle = (string) $vevent->$property;
                if (!empty($handle)) {
                    return $handle;
                }
            }

            return $else;
        };

        // The invitation renders this as a link, and it is whatever the organiser's client put in
        // VEVENT.URL. Anything but a web or mail address is dropped rather than made clickable.
        $url = $this->linkableUrl($notEmpty('URL', false));
        $description = $notEmpty('DESCRIPTION', false);
        $location = $notEmpty('LOCATION', false);
        $locationImageDataAsBase64 = false;
        $locationLink = false;

        if (isset($vevent->{'X-APPLE-STRUCTURED-LOCATION'})) {
            $match = preg_match(
                '/^(geo:)?(?<latitude>\-?\d+\.\d+),(?<longitude>\-?\d+\.\d+)$/',
                (string) $vevent->{'X-APPLE-STRUCTURED-LOCATION'},
                $coordinates
            );
            if (0 !== $match) {
                $zoom = 16;
                $width = 500;
                $height = 220;

                $latLng = new LatLng((float) $coordinates['latitude'], (float) $coordinates['longitude']);

                // https://github.com/DantSu/php-osm-static-api
                // This fetches map tiles over the network. The map is decoration on an invitation,
                // so a tile server that is unreachable or slow must not take the invitation — and
                // with it the client's PUT — down with it.
                try {
                    $locationImageDataAsBase64 = (new OpenStreetMap($latLng, $zoom, $width, $height))
                        ->addMarkers(
                            (new Markers($this->publicDir.'/images/marker.png'))
                                ->setAnchor(Markers::ANCHOR_CENTER, Markers::ANCHOR_BOTTOM)
                                ->addMarker(new LatLng((float) $coordinates['latitude'], (float) $coordinates['longitude']))
                        )
                        ->getImage()
                        ->getBase64PNG();
                } catch (\Throwable $e) {
                    $this->logger->warning('iMIP: the location map could not be rendered, sending the invitation without it', ['exception' => $e->getMessage()]);
                }

                $locationLink =
                    'https://www.openstreetmap.org'.
                    '/?mlat='.$coordinates['latitude'].
                    '&mlon='.$coordinates['longitude'].
                    '#map='.$zoom.
                    '/'.$coordinates['latitude'].
                    '/'.$coordinates['longitude'];
            }
        }

        // For the mail headers, we don't automatically default to the email
        $mailSenderName = $this->mail->senderDisplayName($iTipMessage->senderName, $senderEmail, static::MESSAGE_ORIGIN_INDICATOR);

        $context = $this->mail->context($action, [
            'senderName' => $senderName,
            'recipientName' => $recipientName,
            'summary' => isset($vevent->SUMMARY) ? (string) $vevent->SUMMARY : '',
            'start' => $start,
            'end' => $end,
            'allDay' => $allDay,
            'recurring' => isset($vevent->RRULE) || isset($vevent->RDATE),
            'attendees' => $attendees,
            'location' => $location,
            'locationImageDataAsBase64' => $locationImageDataAsBase64,
            'locationLink' => $locationLink,
            'url' => $url,
            'description' => $description,
            'attached' => false === $deliveredLocally,
        ]);

        try {
            $body = $this->mail->render($context);
        } catch (\Throwable $e) {
            // Only the shipped templates are left at this point, so this is a bug; it still must
            // not abort the PUT that stores the event.
            $this->logger->error('iMIP: the email could not be rendered, no email sent', ['recipient' => $recipientEmail, 'exception' => $e->getMessage()]);
            $iTipMessage->scheduleStatus = '5.0;Email not delivered. The email could not be rendered.';

            return;
        }

        try {
            $message = (new Email())
                ->from(new Address($this->senderEmail, $mailSenderName))
                ->to(new Address($recipientEmail, $recipientName ?? ''))
                ->replyTo(new Address($senderEmail, $mailSenderName))
                ->subject($this->mail->subject($context));
        } catch (RfcComplianceException $e) {
            // An attendee address comes from the organiser's client and is not validated anywhere
            // before this point. Letting it through would abort the PUT that triggered it.
            $this->logger->error('iMIP: an address in the invitation is not a valid email address, no email sent', [
                'sender' => $senderEmail,
                'recipient' => $recipientEmail,
                'exception' => $e->getMessage(),
            ]);
            $iTipMessage->scheduleStatus = '5.3;Email not delivered. One of the addresses is not a valid email address.';

            return;
        }

        // Keep holiday auto-replies from bouncing back at invitations.
        $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');

        if (DAV\Server::$exposeVersion) {
            $message->getHeaders()->addTextHeader('X-Sabre-Version', DAV\Version::VERSION);
        }

        // Now that we have everything, we can set the message body
        $message->html($body['html'])->text($body['text']);

        if (false === $deliveredLocally) {
            // Attach the event file (invite.ics)
            $message->attach($iTipMessage->message->serialize(), 'invite.ics', 'text/calendar; method='.(string) $iTipMessage->method.'; charset=UTF-8');
        }

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $e) {
            // The scheduling message is a side effect of storing the event: if the mail cannot be
            // sent, the event still has to be saved. Throwing here would surface as a 500 on the
            // client's PUT and lose it.
            $this->logger->error('iMIP: the invitation could not be sent', [
                'recipient' => $recipientEmail,
                'method' => $iTipMessage->method,
                'exception' => $e->getMessage(),
            ]);
            $iTipMessage->scheduleStatus = '5.1;Email not delivered. The mail transport is unavailable.';

            return;
        }

        $this->logger->info('iMIP: invitation sent', ['recipient' => $recipientEmail, 'method' => $iTipMessage->method]);

        if (false === $deliveredLocally) {
            $iTipMessage->scheduleStatus = '1.1;Scheduling message is sent via iMip.';
        }
    }

    /**
     * @param string|false $url
     *
     * @return string|false the url if it is one a mail client should offer to open, false otherwise
     */
    private function linkableUrl($url)
    {
        if (!is_string($url) || '' === $url) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return \in_array(strtolower((string) $scheme), ['http', 'https', 'mailto'], true) ? $url : false;
    }

    /**
     * Returns a bunch of meta-data about the plugin.
     *
     * Providing this information is optional, and is mainly displayed by the
     * Browser plugin.
     *
     * The description key in the returned array may contain html and will not
     * be sanitized.
     *
     * @return array
     */
    public function getPluginInfo()
    {
        return [
            'name' => $this->getPluginName(),
            'description' => 'HTML Email delivery (rfc6047) for CalDAV scheduling',
            'link' => 'http://github.com/tchapi/davis',
        ];
    }
}
