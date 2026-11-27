# Installing Davis from a release archive

This file covers the way from a release archive to a running installation, and how to update
one later. `README.md` stays the reference for every setting and for the web server
configuration; this file only says what to do in which order.

## Requirements

- **PHP 8.2 or newer** on the command line *and* in the web server, with the extensions
  `ctype`, `gd`, `iconv`, `intl`, `zip` and the PDO driver of your database (`pdo_mysql`,
  `pdo_pgsql` or `pdo_sqlite`). `ldap` is only needed for LDAP authentication.
- **Composer 2.**
- **A database and a database user that already exist.** MySQL or MariaDB is the usual
  choice. The installation creates the tables, not the database.
- **A web server whose document root can be set** to the `public/` directory of the
  installation.

On a host with several PHP versions, find out what the right one is called on the command
line (`php84`, `php8.4`, …) and use that name in every command below instead of `php`. The
version the web server uses is a separate setting, usually in the control panel of the host.

Once the archive is unpacked, this tells you whether a PHP has what is needed:

```bash
php /path/to/composer.phar check-platform-reqs --lock --no-dev
```

## 1. Get the archive

Every release page has the archive `davis-v<version>.tar.gz` and its checksum
`davis-v<version>.tar.gz.sha256`, and its notes start with the exact commands to fetch both.
Check the checksum before unpacking:

```bash
sha256sum -c davis-vX.Y.Z-st2.N.tar.gz.sha256   # has to say OK
tar -xzf davis-vX.Y.Z-st2.N.tar.gz
```

A download that broke off unpacks fine and is missing files; that shows later, somewhere
that looks unrelated.

Move the unpacked directory to where the installation is to live. The examples below call
that place `/path/to/davis`.

## 2. Configure

Create `/path/to/davis/.env.local`. It is the only file you write: `.env` holds the defaults
and is replaced by every update, and what you set in `.env.local` wins over it.

```shell
APP_ENV=prod
APP_SECRET=<32 random characters>

DATABASE_DRIVER=mysql
DATABASE_URL="mysql://davis:<password>@127.0.0.1:3306/davis?serverVersion=10.11.0-MariaDB&charset=utf8mb4"

ADMIN_LOGIN=admin
ADMIN_PASSWORD=<a password of your own>

INVITE_FROM_ADDRESS=calendar@example.org
MAILER_DSN=smtp://user:<password>@smtp.example.org:587
```

- `APP_ENV=prod` has to be there **before** the next step. Without it Composer starts the
  development environment, which the archive does not have the packages for.
- `APP_SECRET`: `php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'` prints one.
- `DATABASE_URL`: put the version of your own database server after `serverVersion=`. For
  the password, see below.
- `ADMIN_LOGIN` and `ADMIN_PASSWORD` are the login of the dashboard.
- `example.org` in the lines above is a placeholder. Replace it with addresses of your own.

**The database password in `DATABASE_URL`.** Simplest is a long password of letters and
digits only for the database user of Davis; then there is nothing to encode.
`php -r 'echo bin2hex(random_bytes(20)), PHP_EOL;'` prints one with 40 characters.

A password with other characters goes in after two steps: URL-encode it, then double every
`%` of the encoding. `p@ss#word` becomes `p%40ss%23word` and then `p%%40ss%%23word`. The
second step is needed because Davis reads the setting as `%env(resolve:DATABASE_URL)%`, and
`resolve:` takes `%…%` for the name of a container parameter and `%%` for a single `%`.

This prints the finished form; the password is not shown and does not end up in the shell
history. It needs bash or zsh, and PHP under the name it has on your host (`php84`, for
example):

```bash
read -rs P && P="$P" php -r 'echo str_replace("%", "%%", rawurlencode(getenv("P"))), PHP_EOL;'; unset P
```

Type the password and press Enter. When a step is missing, `composer install` stops at the
end, in `cache:clear`:

- Not encoded, and the password has a `/`, `?` or `#`: `Malformed database connection URL`.
  Other characters can get past this point and fail when Davis connects to the database.
- Encoded, but `%` not doubled: `You have requested a non-existent parameter "40ss"`, with
  whatever stands between two `%` of the encoding. A password with only one encoded
  character gets through by chance.

This applies to `DATABASE_URL` only. `MAILER_DSN` is read without `resolve:`; a password in
it is URL-encoded, and its `%` stay single.

Everything else has a default in `.env`, explained there and in `README.md`. The settings
this fork adds are all off by default:

```shell
API_KEY=
API_USER_MANAGEMENT_ENABLED=false
APP_KEYS_ENABLED=false
```

The file holds passwords: make it readable for the account the application runs under and
for nobody else (`chmod 600 .env.local`).

### Settings you will usually want

These go into `.env.local` too, never into `.env`: an update replaces `.env`, and with it
whatever you changed there.

**`APP_TIMEZONE`**: the time zone Davis works in, as a name from
[PHP's list of time zones](https://www.php.net/manual/en/timezones.php):

```shell
APP_TIMEZONE=Europe/Berlin
```

- Use the name, not an abbreviation. PHP accepts `CET`, but the diagnostics page marks it as
  wrong; `MEZ` or `GMT+1` are not accepted at all, and Davis keeps the time zone of the
  server.
- Left empty, Davis uses the time zone PHP is set to (`date.timezone` in `php.ini`, UTC when
  nothing is set there), and the diagnostics page warns about it.

**`BIRTHDAY_REMINDER_OFFSET`**: when the birthday calendar reminds of a birthday. It is
neither a time zone nor a time of day, but a duration in the format of iCalendar
([RFC 5545, section 3.3.6](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.3.6)),
counted from the start of the birthday, 0:00 on the day:

| Value | Reminder |
| --- | --- |
| `PT9H` (the default) | 9:00 on the day |
| `PT8H` | 8:00 on the day |
| `-PT15H` | 9:00 on the day before |
| `-P1D` | 0:00 on the day before |
| `false` | none |

- Davis does not check the value. It goes into every birthday event as it is, and a value
  in another format (`MEZ9H`, `9H`) gives events with a reminder that sabre/vobject, the
  library Davis itself uses, cannot read. The diagnostics page does not show this.
- A new value applies to birthday events written from then on. Davis rewrites an existing
  one only when the date or the name in the contact changes;
  `php bin/console dav:sync-birthday-calendar` rebuilds all of them with the new value.

**Invitation emails**: language, look, and whether users of this server get one. Without
these, Davis writes in English and emails every attendee:

```shell
INVITE_LOCALE=de
INVITE_BRAND_NAME="Example Calendar"
INVITE_ACCENT_COLOR="#000000"
INVITE_EMAIL_LOCAL_USERS=false
```

- Put a color in quotes. Unquoted, a value starting with `#` counts as a comment and arrives
  empty.
- With `INVITE_EMAIL_LOCAL_USERS=false`, users of this server find invitations and replies
  only in their calendar; people elsewhere still get an email.
- German dates need the PHP extension `intl` with German ICU data. The diagnostics page
  says when they are missing; the texts are German either way.
- Logo, footer and templates of your own are described in the README, under the mailer
  configuration.

**Free-busy lookup**: whether logged-in users can see when other users of this server are
busy, for instance to find a time for a meeting. Only busy periods, no titles or other
details. Off by default:

```shell
FREE_BUSY_LOOKUP_ENABLED=true
```

## 3. Install the dependencies

```bash
cd /path/to/davis
composer install --no-dev --optimize-autoloader
```

With another PHP: `php84 /path/to/composer.phar install --no-dev --optimize-autoloader`.
Started as `composer …`, Composer runs under the PHP named in its own first line, which is
not necessarily the one you chose.

## 4. Create the tables

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

## 5. Point the web server at `public/`

The document root is `/path/to/davis/public`, **not** `/path/to/davis`. Nothing above
`public/` may be reachable from the web: `.env.local` is there, and so are the logs.

For Apache, `public/.htaccess` already does the rewriting. Examples for Apache, Nginx and
Caddy, and the redirections for `/.well-known/caldav` and `/.well-known/carddav`, are in
`README.md`.

`var/` has to be writable for the account the web server runs PHP under.

## 6. Check

- Open the address of the installation: the home page shows the version.
- Log in at `/dashboard` with `ADMIN_LOGIN` and `ADMIN_PASSWORD`.
- Open the diagnostics page in the dashboard. It names what is not right yet (the database,
  pending migrations, the log file, the sender address for invitations).

## Updating

Fetch and unpack the new archive **next to** the installation, never over it. Unpacking adds
and overwrites, it never removes, so files that the new version no longer has would keep
being loaded. Then run the script that is in the archive:

```bash
cd davis-vX.Y.Z-st2.N
sh update.sh /path/to/davis --dry-run   # shows what would change
sh update.sh /path/to/davis
```

Read the changes on the release page first. A release can need a manual step that is due
before the update; it is named there.

What the script does, in this order:

1. It makes sure `/path/to/davis` is a Davis installation that was configured, and stops
   without changing anything if it is not. An installation that is a git checkout is updated
   with git instead.
2. It checks the PHP against what the new version needs.
3. It backs up the program files with `.env.local`, and the database, to
   `davis-backup-<time>` next to the installation. If that fails, it stops and removes what
   it had written. The dump of a MySQL or MariaDB database is made with the user and
   password from `DATABASE_URL` and does not read a `~/.my.cnf` of the account that runs
   the script. Whether the database can be read is checked first, in a dry run too.
4. It replaces the program files and removes the ones the new version no longer has.
5. It lets Composer install the dependencies, runs the migrations and clears the cache.

It does not touch `.env.local`, `var/`, `config/secrets/` or anything of your own at the top
level of the installation. `public/.htaccess` is a program file and is replaced; lines of
your own in it are gone afterwards.

Options:

| Option | |
| --- | --- |
| `--dry-run` | show what would happen, change nothing |
| `--yes` | do not ask before starting |
| `--php NAME` | the PHP to use, for example `php84`. Composer is started with it too |
| `--composer PATH` | where Composer is, for example `/path/to/composer.phar` |
| `--backup-dir DIR` | where to put the backup, if the place next to the installation is not writable |
| `--skip-backup` | do not back up. Only if you have a backup of your own |

Davis has no maintenance mode. A request that arrives during the few seconds in which the
files are replaced can fail; DAV clients try again.

The backup holds `.env.local` and the whole database. Keep it out of reach of the web
server and delete it once the new version has proven itself.

## If an update fails

Before the files are replaced, the script stops with "Nothing was changed" and the reason.

After that point it prints the commands that put the backup back: the program files, the
dependencies, and the database if the migrations had already started. Nothing is rolled back
by itself.

## If the installation does not answer

- **A blank page or a 500 right after installing:** look at `var/log/prod.log`, and check
  that `var/` is writable for the web server.
- **`Class "Symfony\Bundle\WebProfilerBundle\WebProfilerBundle" not found`:** `APP_ENV=prod`
  is missing from `.env.local`.
- **The page lists the files of the installation, or shows `.env`:** the document root is
  the installation and not its `public/` directory. Fix that first.
- More in the troubleshooting section of `README.md`.
