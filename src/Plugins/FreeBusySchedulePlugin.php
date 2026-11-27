<?php

namespace App\Plugins;

use Sabre\CalDAV\ICalendar;
use Sabre\CalDAV\ISharedCalendar;
use Sabre\CalDAV\Plugin as CalDAVPlugin;
use Sabre\CalDAV\Schedule\Plugin as SchedulePlugin;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\DAV\ICollection;
use Sabre\DAV\IFile;
use Sabre\DAV\IProperties;
use Sabre\DAV\PropertyStorage\Plugin as PropertyStoragePlugin;
use Sabre\DAV\PropFind;
use Sabre\DAV\Sharing\Plugin as SharingPlugin;
use Sabre\DAVACL\IPrincipalCollection;
use Sabre\DAVACL\Plugin as ACLPlugin;
use Sabre\VObject;

/**
 * Lets users ask for the busy times of other users of this server (RFC 6638: a VFREEBUSY
 * REQUEST posted to one's own outbox), which sabre/dav does not allow out of the box.
 *
 * sabre finds the requested user with an ACL-filtered principal search and reads the calendar
 * home and the inbox from the principal's properties. A principal is readable by its owner
 * (and their proxies) only, so for everybody else the answer is "3.7;No calendar-home-set
 * property found", although the inbox grants schedule-query-freebusy to every authenticated
 * user. With FREE_BUSY_LOOKUP_ENABLED, this plugin finds the user through the principal
 * collection instead and lets that inbox privilege decide; the principal stays unreadable.
 *
 * What leaves the server is the same as for one's own address: busy periods, no titles or
 * other details. Transparent and cancelled events don't count (sabre's generator skips
 * them), nor do calendars marked transparent, nor calendars shared with the user: those belong
 * to someone else, and with them everybody would look busy whenever a colleague is. An
 * availability the user stored on their inbox applies.
 *
 * Disabled (the default), this is sabre's plugin unchanged.
 */
class FreeBusySchedulePlugin extends SchedulePlugin
{
    private const EMAIL_PROPERTY = '{http://sabredav.org/ns}email-address';

    public function __construct(private readonly bool $lookupEnabled = false)
    {
    }

    /**
     * @param string $email
     *
     * @return array
     */
    protected function getFreeBusyForEmail($email, \DateTimeInterface $start, \DateTimeInterface $end, VObject\Component $request)
    {
        if (!$this->lookupEnabled) {
            return parent::getFreeBusyForEmail($email, $start, $end, $request);
        }

        $caldavNS = '{'.self::NS_CALDAV.'}';
        if (0 === stripos($email, 'mailto:')) {
            $email = substr($email, 7);
        }
        $href = 'mailto:'.$email;

        $principalUri = $this->findPrincipal($email);
        if (null === $principalUri) {
            return ['request-status' => '3.7;Could not find principal', 'href' => $href];
        }

        $caldavPlugin = $this->server->getPlugin('caldav');
        $homeSet = $caldavPlugin instanceof CalDAVPlugin ? $caldavPlugin->getCalendarHomeForPrincipal($principalUri) : null;
        if (null === $homeSet || !$this->server->tree->nodeExists($homeSet)) {
            return ['request-status' => '3.7;No calendar-home-set property found', 'href' => $href];
        }
        $inboxUrl = $homeSet.'/inbox';
        if (!$this->server->tree->nodeExists($inboxUrl)) {
            return ['request-status' => '3.7;No schedule-inbox-URL property found', 'href' => $href];
        }

        // Every authenticated user has it (inside schedule-deliver) unless the inbox's ACL says
        // otherwise. Answered per attendee, so that one refusal doesn't fail the whole request.
        $aclPlugin = $this->server->getPlugin('acl');
        if ($aclPlugin instanceof ACLPlugin && !$aclPlugin->checkPrivileges($inboxUrl, $caldavNS.'schedule-query-freebusy', ACLPlugin::R_PARENT, false)) {
            return ['request-status' => '3.8;No authority', 'href' => $href];
        }

        // From here on as sabre does it, except for the calendars shared with the user and for
        // the availability, which sabre reads through the ACL and so only finds for oneself.
        $home = $this->server->tree->getNodeForPath($homeSet);
        if (!$home instanceof ICollection) {
            return ['request-status' => '3.7;No calendar-home-set property found', 'href' => $href];
        }

        $objects = [];
        $calendarTimeZone = new \DateTimeZone('UTC');

        foreach ($home->getChildren() as $node) {
            if (!$node instanceof ICalendar) {
                continue;
            }

            if ($node instanceof ISharedCalendar
                && !\in_array($node->getShareAccess(), [SharingPlugin::ACCESS_NOTSHARED, SharingPlugin::ACCESS_SHAREDOWNER], true)) {
                continue;
            }

            $sct = $caldavNS.'schedule-calendar-transp';
            $ctz = $caldavNS.'calendar-timezone';
            $props = $node instanceof IProperties ? $node->getProperties([$sct, $ctz]) : [];

            if (isset($props[$sct]) && ScheduleCalendarTransp::TRANSPARENT == $props[$sct]->getValue()) {
                continue;
            }

            if (isset($props[$ctz])) {
                $vtimezoneObj = VObject\Reader::read($props[$ctz]);
                $vtimezone = $this->first($vtimezoneObj, 'VTIMEZONE');
                if ($vtimezone instanceof VObject\Component\VTimeZone) {
                    $calendarTimeZone = $vtimezone->getTimeZone();
                }
                $vtimezoneObj->destroy();
            }

            $urls = $node->calendarQuery([
                'name' => 'VCALENDAR',
                'comp-filters' => [
                    [
                        'name' => 'VEVENT',
                        'comp-filters' => [],
                        'prop-filters' => [],
                        'is-not-defined' => false,
                        'time-range' => [
                            'start' => $start,
                            'end' => $end,
                        ],
                    ],
                ],
                'prop-filters' => [],
                'is-not-defined' => false,
                'time-range' => null,
            ]);

            foreach ($urls as $url) {
                $object = $node->getChild($url);
                if ($object instanceof IFile) {
                    $objects[] = $object->get();
                }
            }
        }

        $vcalendar = new VObject\Component\VCalendar();
        $vcalendar->add('METHOD', 'REPLY');

        $generator = new VObject\FreeBusyGenerator();
        $generator->setObjects($objects);
        $generator->setTimeRange($start, $end);
        $generator->setBaseObject($vcalendar);
        $generator->setTimeZone($calendarTimeZone);

        $availability = $this->storedAvailability($inboxUrl);
        if (null !== $availability) {
            $generator->setVAvailability(VObject\Reader::read($availability));
        }

        $result = $generator->getResult();

        $answer = $this->first($result, 'VFREEBUSY');
        $asked = $this->first($request, 'VFREEBUSY');
        if ($answer instanceof VObject\Component && $asked instanceof VObject\Component) {
            $answer->remove('ATTENDEE');
            $answer->add('ATTENDEE', $href);
            $answer->remove('UID');
            $uid = $this->first($asked, 'UID');
            $answer->add('UID', $uid instanceof VObject\Property ? (string) $uid : '');
            $answer->remove('ORGANIZER');
            foreach ($asked->select('ORGANIZER') as $organizer) {
                $answer->add(clone $organizer);
            }
        }

        return [
            'calendar-data' => $result,
            'request-status' => '2.0;Success',
            'href' => $href,
        ];
    }

    private function first(VObject\Component $component, string $name): ?VObject\Node
    {
        $found = $component->select($name);

        return reset($found) ?: null;
    }

    /**
     * The principal whose address this is, exactly (the backend matches with LIKE, so a search
     * for a@example.org also finds anna@example.org).
     */
    private function findPrincipal(string $email): ?string
    {
        $aclPlugin = $this->server->getPlugin('acl');
        $collections = $aclPlugin instanceof ACLPlugin ? $aclPlugin->principalCollectionSet : ['principals'];

        foreach ($collections as $collectionUri) {
            if (!$this->server->tree->nodeExists($collectionUri)) {
                continue;
            }
            $collection = $this->server->tree->getNodeForPath($collectionUri);
            if (!$collection instanceof IPrincipalCollection) {
                continue;
            }

            foreach ($collection->searchPrincipals([self::EMAIL_PROPERTY => $email]) as $name) {
                $principal = $collection->getChild($name);
                if (!$principal instanceof IProperties) {
                    continue;
                }
                $found = $principal->getProperties([self::EMAIL_PROPERTY])[self::EMAIL_PROPERTY] ?? null;
                if (\is_string($found) && 0 === strcasecmp($found, $email)) {
                    return rtrim($collectionUri, '/').'/'.$name;
                }
            }
        }

        return null;
    }

    /**
     * The availability a client stored on the inbox, read from the property storage alone: no
     * ACL in between, and no default from another plugin.
     */
    private function storedAvailability(string $inboxUrl): ?string
    {
        $storage = $this->server->getPlugin('property-storage');
        if (!$storage instanceof PropertyStoragePlugin) {
            return null;
        }

        $property = '{'.self::NS_CALDAV.'}calendar-availability';
        $propFind = new PropFind($inboxUrl, [$property]);
        $storage->propFind($propFind, $this->server->tree->getNodeForPath($inboxUrl));
        $value = $propFind->get($property);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
