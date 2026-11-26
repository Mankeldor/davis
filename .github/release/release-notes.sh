#!/bin/sh
#
# Writes the text of the release page to dist/release-notes.md.
#
#     GITHUB_REPOSITORY=owner/davis sh .github/release/release-notes.sh v5.6.3-st2.3
#
# A FILE, on purpose: the text is full of backticks, and handed to a command as an argument a
# backtick is a command substitution. Words went missing from a published note that way.
#
# Whoever reads the note has nothing yet, so it starts with getting the archive, with the full
# address written out, and with checking the checksum. Run it before tagging and read the
# result (see RELEASE.md): afterwards it is on a public page.

set -eu

TAG="${1:-}"
[ -n "$TAG" ] || { echo "Usage: sh .github/release/release-notes.sh <tag>" >&2; exit 2; }
: "${GITHUB_REPOSITORY:?Set GITHUB_REPOSITORY to owner/repository, as GitHub Actions does.}"

DIST="${DIST:-dist}"
CHANGELOG="CHANGELOG.fork.md"
VERSION="${TAG#v}"
NAME="davis-${TAG}"
ARCHIVE="${NAME}.tar.gz"
BASE="${GITHUB_SERVER_URL:-https://github.com}/${GITHUB_REPOSITORY}/releases/download/${TAG}"
NOTES="${DIST}/release-notes.md"

[ -f "${DIST}/${ARCHIVE}.sha256" ] || { echo "ERROR: ${DIST}/${ARCHIVE}.sha256 is missing. Run build-package.sh first." >&2; exit 1; }

# In this block a backtick has to be written \` and a dollar sign \$, or the shell acts on it.
cat > "$NOTES" <<EOF
Davis is a CalDAV, CardDAV and WebDAV server with an admin dashboard. This is Davis ${VERSION%%-*} with API endpoints to manage users and their app keys.

## Installing

On the server, get the archive and its checksum, check it and unpack it:

\`\`\`bash
curl -fLO ${BASE}/${ARCHIVE}
curl -fLO ${BASE}/${ARCHIVE}.sha256
sha256sum -c ${ARCHIVE}.sha256   # has to say OK
tar -xzf ${ARCHIVE}
cd ${NAME}
\`\`\`

**Check the checksum.** A download that broke off unpacks fine and is missing files; that shows later, somewhere that looks unrelated.

Then configure and install. \`.env.local\` is the only file you write; \`.env\` holds the defaults and is replaced by every update.

\`\`\`bash
# .env.local needs at least APP_ENV=prod, APP_SECRET, DATABASE_URL, ADMIN_LOGIN and
# ADMIN_PASSWORD. INSTALL.md lists them with examples.
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate --no-interaction
\`\`\`

Three things the commands do not tell you:

- **The database and its user have to exist beforehand.** The migrations create the tables, nothing else.
- **The document root of the web server is the \`public/\` directory**, not the directory above it. Everything else, \`.env.local\` included, must not be reachable from the web.
- **On a host with several PHP versions**, use the name of the right one for both commands, for example \`php84 /path/to/composer.phar install …\` and \`php84 bin/console …\`. The version the web server uses is a separate setting.

The full instructions are in INSTALL.md inside the archive.

## Updating an existing installation

**Read the "Changes" section below first.** A release can need a manual step that is due *before* the update; it is named there.

Do **not** unpack the archive over a live installation. Unpacking adds and overwrites, it never removes, so files that this version no longer has keep being loaded.

Use the script in the archive instead. It runs from the unpacked directory and takes the path to your installation:

\`\`\`bash
curl -fLO ${BASE}/${ARCHIVE}
curl -fLO ${BASE}/${ARCHIVE}.sha256
sha256sum -c ${ARCHIVE}.sha256   # has to say OK
tar -xzf ${ARCHIVE}
cd ${NAME}
sh update.sh /path/to/your/installation --dry-run   # shows what would change
sh update.sh /path/to/your/installation
\`\`\`

It first makes sure that the path really is a Davis installation, and that PHP and its extensions are what this version needs. Then it backs up the configuration, the code and the database, replaces the program files (removing the ones this version no longer has), installs the dependencies, runs the migrations and clears the cache. \`.env.local\`, \`var/\` and anything of your own next to the program directories stay as they are.

- **PHP under another name:** \`sh update.sh /path/to/your/installation --php php84\`. Composer is started with that PHP too; \`--composer /path/to/composer.phar\` says where it is.
- **The backup** is written next to the installation, as \`davis-backup-<time>\`. If the update fails after the files were replaced, the script prints the commands that put the backup back.
- **Davis has no maintenance mode.** A request that arrives during the few seconds of the file swap can fail; DAV clients try again.
- **\`public/.htaccess\` belongs to the program** and is replaced like every other file. Lines of your own in it are gone afterwards.

An installation that is a git checkout is updated with git, not with this script; it says so and stops.

## What is in the archive

The files of the tag that an installation runs from: \`bin/\`, \`config/\`, \`migrations/\`, \`public/\`, \`src/\`, \`templates/\`, \`translations/\`, the Composer files, \`.env\` with the defaults, \`README.md\` (the reference for every setting), \`INSTALL.md\`, \`update.sh\` and \`LICENSE\`. The stylesheets and scripts are in \`public/\` as they are; nothing has to be built.

Not in it: the tests, the Docker build, the CI configuration and the API documentation, which are only needed to develop. **\`vendor/\` is not included either**; Composer installs it on the server.

SHA256:

\`\`\`
$(cat "${DIST}/${ARCHIVE}.sha256")
\`\`\`

## Changes

EOF

# The section of this version, appended as it is. It ends at the next "## " heading.
awk -v v="$VERSION" '
    index($0, "## [" v "]") == 1 { inside = 1; next }
    inside && /^## / { exit }
    inside { print }
' "$CHANGELOG" | sed -e '1{/^$/d;}' >> "$NOTES"

echo "--> ${NOTES} ($(wc -l < "$NOTES" | tr -d ' ') lines)"
