# Changelog of this fork

What this fork changes compared with the Davis release it is built on, from the point of
view of whoever runs an installation. It is not the changelog of Davis itself.

A version is named after the Davis release it carries, with the number of the fork release
added: `5.6.3-st2.3` is the third release on top of Davis 5.6.3.

The release notes of a version are built from its section in this file. A tag without a
section is not released (see `RELEASE.md`).

## [Unreleased]

## [5.6.3-st2.5] - 2026-11-26

> **Updating needs no manual step.** No migration, no new setting. One thing changes for
> whatever calls the API: an email address that another user already has is now refused.

### Changed

- **The API gives an email address to one user only.** `POST /api/v1/users/create` and
  `PATCH /api/v1/users/{userId}` answer `409` with the message `Email Already In Use` when
  another user has the address. Upper and lower case make no difference. Davis finds the
  user an invitation or a reply is for by this address, and with two users on the same one
  it cannot tell which of them is meant.
  - The username is checked first. When username and address are both taken, the answer is
    still `User Already Exists`, so a caller can tell the two apart: another username can be
    tried, another address cannot.
  - When a user is changed, the user itself does not count: sending a user the address it
    already has is never an error.
  - It is a rule of the API only. The dashboard does not check it, and users who already
    share an address stay as they are and can still be changed.

## [5.6.3-st2.4] - 2026-11-26

> **Updating changes nothing in the application.** No migration, no new setting. This
> release corrects `update.sh`. An update always runs the `update.sh` of the archive it
> updates *to*, so updating to this release already uses the corrected script.

### Fixed

- **`update.sh` could not back up the database when the account that runs it has a
  `~/.my.cnf`.** With another user and password in that file, the dump logged in with the
  user of Davis and the password from the file and was refused
  (`mariadb-dump: Got error: 1045: "Access denied …"`), and the update stopped before it
  had changed anything. The dump now reads the options files of the system, where the socket
  and TLS settings of the machine are, and not the one of the account. That also keeps
  options for the dump in such a file (`[mysqldump]`) from quietly thinning out the backup.
  If the client only connects with the options of the account, the script uses them after
  all and says so.
- **A backup that fails is removed again.** Before, it left a `davis-backup-<time>`
  directory behind with a copy of `.env.local` and an empty dump in it. If a run of the
  earlier script failed for you, look for such a directory next to the installation and
  delete it.
- **`update.sh` finds out whether the database can be read before it starts,** and
  `--dry-run` does too. Before, a dry run could pass and the update then stop at the backup.
- **A password in `DATABASE_URL` with doubled percent signs** (`%%`, which `.env` files
  need for a URL-encoded password) is read the way the application reads it.

## [5.6.3-st2.3] - 2026-11-26

> **Updating changes nothing in how the application behaves.** No migration, no new
> setting. This release is about how an installation is made and updated.

### Added

- **A release archive with a checksum.** Every release now comes with
  `davis-v<version>.tar.gz` and a `.sha256` file next to it, and its page says how to fetch
  and check them. The archive holds what an installation runs from; the tests, the Docker
  build and the API documentation are left out.
- **`INSTALL.md`** in the archive: installing from the archive, step by step, with the
  settings that go into `.env.local`.
- **`update.sh`** in the archive, to update an installation that was made from an archive:

  ```bash
  sh update.sh /path/to/your/installation --dry-run
  sh update.sh /path/to/your/installation
  ```

  It checks that the path is a Davis installation and that PHP meets the requirements of
  the new version, backs up the files and the database, replaces the program files and
  removes the ones the new version no longer has, installs the dependencies, runs the
  migrations and clears the cache. `.env.local`, `var/` and `config/secrets/` are not
  touched. `--php php84` chooses the PHP on a host that has several.

  Updating from 5.6.3-st2.1 or 5.6.3-st2.2: unpack this archive next to the installation,
  not over it, and run the script from there. It also removes what the archive of
  5.6.3-st2.1 still carried and an installation does not need (`tests/`, `docker/`, `docs/`
  and the like).

### Removed

- **The workflow that publishes Docker images.** It pushed to the image registry of the
  original project, which a fork cannot do. This fork publishes no images; use the archive.

## [5.6.3-st2.2] - 2026-11-26

> **Updating needs no manual step.** No migration, no new setting.

### Added

- **`PATCH /api/v1/users/{userId}`** changes the display name and the email address of a
  user, one of them or both. The username cannot be changed, an administrator cannot be
  changed over the API, and a password or an admin flag in the request is ignored. Like the
  other user management endpoints it needs `API_USER_MANAGEMENT_ENABLED=true`, and it is
  written to the log, with the names of the fields that changed and not their values. Events
  that are already stored keep the old address where they name it.

### Changed

- **The archive of a tag leaves out what is only needed to develop:** the tests, the Docker
  build, the CI configuration, the screenshots and the API documentation.

## [5.6.3-st2.1] - 2026-11-26

> **Updating from Davis 5.6.3 needs no manual step.** No migration. Two new settings, both
> off by default: without them the API does exactly what it did before.

### Added

- **Creating and deleting users over the API**, behind a new setting:

  ```shell
  API_USER_MANAGEMENT_ENABLED=false
  ```

  With `true`, `POST /api/v1/users/create` creates a user with its principal, default
  calendar and default address book, and `DELETE /api/v1/users/{userId}` deletes a user with
  everything that belongs to it. A user created this way has no usable password and is never
  an administrator, and an administrator cannot be deleted over the API. It is a separate
  setting because it makes the API key more powerful: deleting a user removes all of its
  calendars and address books and cannot be undone.
- **Creating and revoking the app keys of a user over the API:**
  `POST /api/v1/users/{userId}/app_keys/create` and
  `POST /api/v1/users/{userId}/app_keys/{appId}/revoke`. They need
  `API_USER_MANAGEMENT_ENABLED=true` and `APP_KEYS_ENABLED=true`. A key made here is for
  exactly one user who is not an administrator; keys for all users, for several users or
  for an administrator can neither be created nor revoked over the API. With both settings
  on, whoever holds the API key can log in as any user who is not an administrator: keep the
  key on the server side.
- **A line in the log for each of these requests.** The production log only wrote when a
  request failed; users and app keys that are created, deleted or revoked over the API are
  now written to the same log file on a channel of their own (`api`). The secret of an app
  key is never logged.
- **The diagnostics page shows whether user management over the API is on.**

### Changed

- **The version shown** on the home page, on the diagnostics page and in the addresses of
  the stylesheets and scripts carries the number of the fork release, so an installation
  can be told apart from a stock Davis 5.6.3.
- **A user whose principal is missing can be deleted** in the dashboard. Before, that
  request failed.

## Older versions

Before 5.6.3-st2.1 this fork had no releases of its own. For Davis 5.6.3 and earlier, see
the release notes of Davis.
