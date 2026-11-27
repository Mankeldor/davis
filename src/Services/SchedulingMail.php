<?php

namespace App\Services;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\FileExtensionEscapingStrategy;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Words and renders the emails Davis sends for CalDAV scheduling (iMIP).
 *
 * Everything here can be adjusted by the operator without touching the program files:
 *  - INVITE_LOCALE picks the language of the texts (translations/mails+intl-icu.<locale>.xlf),
 *  - INVITE_BRAND_NAME, INVITE_LOGO_URL, INVITE_ACCENT_COLOR and INVITE_FOOTER change the look,
 *  - INVITE_TEMPLATES_DIR points to a directory with templates of one's own, looked up before
 *    the ones shipped in templates/mails/.
 *
 * With none of them set, the emails read and look as they always did.
 *
 * The templates are rendered by a Twig environment of their own rather than the one of the
 * dashboard: a template of the operator's must be able to fail without taking the invitation
 * with it, and it must not see what the dashboard templates see.
 */
class SchedulingMail
{
    public const ACTIONS = ['REQUEST', 'UPDATE', 'CANCEL', 'UNINVITED', 'ACCEPTED', 'TENTATIVE', 'DECLINED'];

    public const TEMPLATES = ['layout.html.twig', 'layout.txt.twig', 'scheduling.html.twig', 'scheduling.txt.twig'];

    public const DEFAULT_ACCENT_COLOR = '#2c7ea9';

    private const DOMAIN = 'mails';

    private ?Environment $customTwig = null;

    private ?Environment $builtInTwig = null;

    public function __construct(
        private TranslatorInterface $translator,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
        private string $builtInTemplatesDir,
        private string $translationsDir,
        private string $locale = 'en',
        private string $brandName = '',
        private string $logoUrl = '',
        private string $accentColor = '',
        private string $footer = '',
        private string $templatesDir = '',
    ) {
        $this->locale = '' !== trim($this->locale) ? trim($this->locale) : 'en';
        $this->brandName = self::oneLine($this->brandName);
        $this->footer = self::oneLine($this->footer);
        $this->templatesDir = rtrim(trim($this->templatesDir), '/');
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * Whether the chosen language has texts of its own, rather than falling back to English.
     */
    public function hasTranslation(): bool
    {
        return 'en' === $this->locale || is_file($this->translationsDir.'/'.self::DOMAIN.'+intl-icu.'.$this->locale.'.xlf');
    }

    /**
     * Dates in another language than English need the intl extension and the ICU data for that
     * language. Without them they stay in English while the rest of the text is translated.
     * Some builds of ICU ship English only (Alpine Linux without icu-data-full, for one).
     */
    public function canFormatDates(): bool
    {
        if ('en' === $this->locale || str_starts_with($this->locale, 'en_')) {
            return true;
        }
        if (!\extension_loaded('intl')) {
            return false;
        }

        $known = \ResourceBundle::getLocales('') ?: [];
        $language = strtok(str_replace('-', '_', $this->locale), '_');

        return \in_array(str_replace('-', '_', $this->locale), $known, true) || \in_array($language, $known, true);
    }

    public function brandName(): string
    {
        return $this->brandName;
    }

    /**
     * The logo is shown only from an absolute web address: a relative one means nothing to a
     * mail client, and anything else would be put into every email as is.
     */
    public function logoUrl(): ?string
    {
        $url = trim($this->logoUrl);
        if ('' === $url) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return \in_array($scheme, ['https', 'http'], true) && parse_url($url, PHP_URL_HOST) ? $url : null;
    }

    public function logoIsInvalid(): bool
    {
        return '' !== trim($this->logoUrl) && null === $this->logoUrl();
    }

    public function accentColor(): string
    {
        return $this->accentColorIsInvalid() || '' === trim($this->accentColor) ? self::DEFAULT_ACCENT_COLOR : '#'.strtolower(ltrim(trim($this->accentColor), '#'));
    }

    /**
     * Written without the `#` it counts too: in a .env file an unquoted value that starts with
     * `#` is a comment, and the setting arrives empty.
     */
    public function accentColorIsInvalid(): bool
    {
        $color = trim($this->accentColor);

        return '' !== $color && 1 !== preg_match('/^#?[0-9a-fA-F]{6}$/', $color);
    }

    public function templatesDir(): ?string
    {
        return '' !== $this->templatesDir ? $this->templatesDir : null;
    }

    /**
     * The templates of the operator's that fail to compile, with the reason.
     *
     * @return array<string, string> template name => error, empty when there is nothing wrong
     *                               (or no directory configured)
     */
    public function brokenCustomTemplates(): array
    {
        $twig = $this->customTwig();
        if (!$twig) {
            return [];
        }

        $broken = [];
        foreach (self::TEMPLATES as $name) {
            if (!is_file($this->templatesDir.'/'.$name)) {
                continue;
            }
            try {
                $twig->load($name);
            } catch (\Throwable $e) {
                $broken[$name] = $e->getMessage();
            }
        }

        return $broken;
    }

    /**
     * The name shown next to the system address in From and Reply-To.
     */
    public function senderDisplayName(?string $name, string $email, string $originIndicator): string
    {
        $name = self::oneLine((string) $name);

        if ('' !== $this->brandName) {
            return $this->trans('sender.branded', ['name' => '' !== $name ? $name : $email, 'brand' => $this->brandName]);
        }

        // Without a brand name, as it has always been: the person's name, and the email
        // address only when there is an origin indicator to go with it.
        if ('' !== $name) {
            return $originIndicator ? $name.' '.$originIndicator : $name;
        }

        return $originIndicator ? $email.' '.$originIndicator : '';
    }

    /**
     * @param array<string, mixed> $context as built by {@see context()}
     */
    public function subject(array $context): string
    {
        return self::oneLine($this->trans('subject.'.strtolower($context['action']), [
            'name' => $context['senderName'],
            'summary' => $context['summary'],
            'when' => $context['when']['subject'],
        ]));
    }

    /**
     * Everything the templates get. The keys are a contract with templates of the operator's
     * (see the README): add to them, do not rename or remove them.
     *
     * @param array<string, mixed> $event the event as read by the scheduling plugin
     *
     * @return array<string, mixed>
     */
    public function context(string $action, array $event): array
    {
        $when = $this->when($event['start'], $event['end'], $event['allDay']);

        return $event + [
            'action' => $action,
            'when' => $when,
            'dateTime' => $event['start'],
            'locale' => $this->locale,
            'siteUrl' => $this->siteUrl(),
            'brand' => [
                'name' => $this->brandName,
                'logoUrl' => $this->logoUrl(),
                'accentColor' => $this->accentColor(),
                'footer' => $this->footer,
            ],
        ];
    }

    /**
     * Renders the HTML and the text part. A template of the operator's that fails is logged
     * and replaced by the shipped one, so that the invitation still goes out.
     *
     * @param array<string, mixed> $context
     *
     * @return array{html: string, text: string}
     */
    public function render(array $context): array
    {
        $custom = $this->customTwig();
        if ($custom) {
            try {
                return $this->renderWith($custom, $context);
            } catch (\Throwable $e) {
                $this->logger->warning('iMIP: a template in INVITE_TEMPLATES_DIR failed, the shipped one was used instead', [
                    'directory' => $this->templatesDir,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $this->renderWith($this->builtInTwig(), $context);
    }

    /**
     * @return array{html: string, text: string}
     */
    private function renderWith(Environment $twig, array $context): array
    {
        return [
            'html' => $twig->render('scheduling.html.twig', $context),
            'text' => $twig->render('scheduling.txt.twig', $context),
        ];
    }

    /**
     * The dates of the event, formatted for the emails in their language.
     *
     * @return array<string, string|null>
     */
    public function when(\DateTimeInterface $start, ?\DateTimeInterface $end, bool $allDay): array
    {
        $timezone = new \DateTimeZone(date_default_timezone_get());
        $start = \DateTimeImmutable::createFromInterface($start);
        $end = $end ? \DateTimeImmutable::createFromInterface($end) : null;

        if ($allDay) {
            // An all-day DTEND is the day after the last one
            $last = $end ? $end->modify('-1 day') : null;
            if ($last && $last->format('Y-m-d') <= $start->format('Y-m-d')) {
                $last = null;
            }
            $date = $this->formatDate($start, 'full');

            return [
                'date' => $last ? $this->trans('when.date_range', ['start' => $date, 'end' => $this->formatDate($last, 'full')]) : $date,
                'time' => null,
                'day' => $this->formatDate($start, 'day'),
                'weekday' => $this->formatDate($start, 'weekday'),
                'month' => $this->formatDate($start, 'month'),
                'subject' => $this->trans('when.subject_all_day', ['date' => $this->formatDate($start, 'short')]),
            ];
        }

        $start = $start->setTimezone($timezone);
        $end = $end?->setTimezone($timezone);
        $zone = $this->formatDate($start, 'zone');

        if (!$end || $end <= $start) {
            $time = $this->trans('when.time', ['start' => $this->formatDate($start, 'time'), 'zone' => $zone]);
        } elseif ($end->format('Y-m-d') === $start->format('Y-m-d')) {
            $time = $this->trans('when.time_range', ['start' => $this->formatDate($start, 'time'), 'end' => $this->formatDate($end, 'time'), 'zone' => $zone]);
        } else {
            $time = $this->trans('when.time_until', [
                'start' => $this->formatDate($start, 'time'),
                'endDate' => $this->formatDate($end, 'full'),
                'end' => $this->formatDate($end, 'time'),
                'zone' => $zone,
            ]);
        }

        return [
            'date' => $this->formatDate($start, 'full'),
            'time' => $time,
            'day' => $this->formatDate($start, 'day'),
            'weekday' => $this->formatDate($start, 'weekday'),
            'month' => $this->formatDate($start, 'month'),
            'subject' => $this->trans('when.subject', ['date' => $this->formatDate($start, 'short'), 'time' => $this->formatDate($start, 'time')]),
        ];
    }

    private function formatDate(\DateTimeImmutable $date, string $form): string
    {
        if (!\extension_loaded('intl')) {
            // The formats the emails have always used
            return $date->format(match ($form) {
                'full' => 'l, F jS Y',
                'short' => 'D, M j, Y',
                'time' => 'g:ia',
                'zone' => 'T',
                'day' => 'j',
                'weekday' => 'l',
                'month' => 'F',
            });
        }

        try {
            return $this->formatWithIntl($date, $form, $this->locale);
        } catch (\Throwable $e) {
            // A locale that ICU does not accept (INVITE_LOCALE=german, say): English dates
            // rather than no email
            return $this->formatWithIntl($date, $form, 'en');
        }
    }

    private function formatWithIntl(\DateTimeImmutable $date, string $form, string $locale): string
    {
        $pattern = match ($form) {
            'full' => null,
            'short' => (new \IntlDatePatternGenerator($locale))->getBestPattern('EEEdMMMy'),
            'time' => null,
            'zone' => 'z',
            'day' => 'd',
            'weekday' => 'cccc',
            'month' => 'LLLL',
        };

        // ICU knows zone identifiers only; an offset or an abbreviation ("Z", "+01:00") is
        // refused, so such a date is shown in UTC
        if (!\in_array($date->getTimezone()->getName(), \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            $date = $date->setTimezone(new \DateTimeZone('UTC'));
        }

        $formatter = new \IntlDateFormatter(
            $locale,
            'full' === $form ? \IntlDateFormatter::FULL : \IntlDateFormatter::NONE,
            'time' === $form ? \IntlDateFormatter::SHORT : \IntlDateFormatter::NONE,
            $date->getTimezone()->getName(),
            \IntlDateFormatter::GREGORIAN,
            $pattern
        );

        $formatted = $formatter->format($date);

        return false === $formatted ? $date->format('Y-m-d H:i') : $formatted;
    }

    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters, self::DOMAIN, $this->locale);
    }

    private function siteUrl(): string
    {
        return $this->requestStack->getMainRequest()?->getSchemeAndHttpHost() ?? '';
    }

    private function customTwig(): ?Environment
    {
        if (null === $this->templatesDir() || !is_dir($this->templatesDir)) {
            return null;
        }

        return $this->customTwig ??= $this->twig([$this->templatesDir, $this->builtInTemplatesDir], false);
    }

    private function builtInTwig(): Environment
    {
        return $this->builtInTwig ??= $this->twig([$this->builtInTemplatesDir], true);
    }

    /**
     * @param string[] $paths looked up in this order
     */
    private function twig(array $paths, bool $strict): Environment
    {
        $loader = new FilesystemLoader($paths);
        // So that a template of the operator's can extend the shipped one and change a block
        $loader->setPaths([$this->builtInTemplatesDir], 'davis');

        $twig = new Environment($loader, [
            'autoescape' => [FileExtensionEscapingStrategy::class, 'guess'],
            'strict_variables' => $strict,
            'cache' => false,
        ]);
        $twig->addFunction(new TwigFunction('t', fn (string $id, array $parameters = []): string => $this->trans($id, $parameters)));

        return $twig;
    }

    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
