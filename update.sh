#!/bin/sh
#
# Updates an existing Davis installation to the version in THIS archive.
#
#     sh update.sh /path/to/installation [--dry-run] [--yes] [--php php84]
#
# Why this is a script and not a list of commands in a manual: the steps of an update are
# not difficult, but their ORDER is. Between replacing the files and `composer install`, new
# code sits on the old vendor/ directory and on a compiled cache of the old version, and
# every console call in that window can stop with "class not found". And unpacking an archive
# over an installation never removes a file, so classes, templates and configuration that
# this version no longer has would keep being loaded.
#
# It runs in POSIX sh (no bash needed) from the unpacked archive and takes the path of the
# installation as its argument, because that path is different on every host.
#
# Options:
#   --dry-run          show what would happen, change nothing
#   --yes              do not ask before starting (for unattended runs)
#   --php NAME         the PHP to use, e.g. php84 (default: php, or $PHP_BIN)
#   --composer PATH    Composer, e.g. /path/to/composer.phar (default: composer in the PATH)
#   --backup-dir DIR   where to put the backup (default: next to the installation)
#   --skip-backup      do not back up (only if you have a backup of your own)

set -eu

PHP_MIN_ID=80200
PHP_MIN="8.2"

# What tells a Davis installation from any other directory. The last file only Davis has.
MARKERS="bin/console composer.json src/Version.php src/Controller/DAVController.php"

# The directories that are program and nothing else. After the update they hold exactly what
# the archive holds: a file that is not in the archive is removed from them.
PROGRAM_DIRS="bin config migrations public src templates translations"

# What earlier archives shipped at the top level and this one leaves out. Removed if present.
RETIRED="tests docker docs _screenshots .github .env.test phpunit.xml.dist .php-cs-fixer.php .hadolint.yaml .dockerignore .gitattributes .gitignore"

SOURCE=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
TARGET=""
DRY_RUN=0
ASSUME_YES=0
SKIP_BACKUP=0
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_ARG=""
BACKUP_ARG=""

say()  { printf '%s\n' "$*"; }
step() { printf '\n==> %s\n' "$*"; }
fail() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

# A path in the form it can be pasted into a shell. The commands this script prints are meant
# to be copied; with a space in the path they would fall apart otherwise.
quoted() {
    case "$1" in
        *[!A-Za-z0-9_./-]*) printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")" ;;
        *)                  printf '%s' "$1" ;;
    esac
}

usage() {
    say "Usage: sh update.sh /path/to/installation [--dry-run] [--yes] [--php NAME]"
    say "                    [--composer PATH] [--backup-dir DIR] [--skip-backup]"
    exit 2
}

while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run)     DRY_RUN=1 ;;
        --yes)         ASSUME_YES=1 ;;
        --skip-backup) SKIP_BACKUP=1 ;;
        --php)         [ $# -ge 2 ] || usage; PHP_BIN="$2"; shift ;;
        --composer)    [ $# -ge 2 ] || usage; COMPOSER_ARG="$2"; shift ;;
        --backup-dir)  [ $# -ge 2 ] || usage; BACKUP_ARG="$2"; shift ;;
        -h|--help)     usage ;;
        -*)            fail "Unknown option: $1" ;;
        *)             [ -z "$TARGET" ] || fail "More than one path given."
                       TARGET="$1" ;;
    esac
    shift
done

[ -n "$TARGET" ] || usage

# What belongs to the server and not to the archive. These paths are neither replaced nor
# removed. vendor/ is one of them although Composer renews it right after: deleting it first
# would mean that a failed `composer install` leaves neither the old state nor the new one.
is_protected() {
    case "$1" in
        .env.local|.env.local.php|.env.*.local) return 0 ;;
        var|var/*|vendor|vendor/*) return 0 ;;
        config/secrets|config/secrets/*) return 0 ;;
        public/bundles|public/bundles/*) return 0 ;;
    esac
    return 1
}

version_of() {
    sed -n "s/.*const VERSION = '\([^']*\)'.*/\1/p" "$1/src/Version.php" 2> /dev/null
}

# ------------------------------------------------------------------ the archive
for marker in $MARKERS composer.lock .env; do
    [ -e "$SOURCE/$marker" ] || fail "This script has to run from the unpacked archive: $SOURCE/$marker is missing."
done

# ------------------------------------------------------------------- the target
#
# The most important check in this script. Files are about to be removed from the program
# directories of the target; a mistyped path must not empty somebody else's project.
[ -d "$TARGET" ] || fail "There is no directory '$TARGET'."
TARGET=$(CDPATH='' cd -- "$TARGET" && pwd)

[ "$TARGET" != "$SOURCE" ] || fail "The path is the unpacked archive itself. Give the path of the installation."
case "$TARGET/" in "$SOURCE"/*) fail "The installation lies inside the unpacked archive. Unpack the archive somewhere else." ;; esac
case "$SOURCE/" in "$TARGET"/*) fail "The archive was unpacked inside the installation. Unpack it somewhere else: its files would be taken for part of the installation." ;; esac

for marker in $MARKERS; do
    [ -e "$TARGET/$marker" ] || fail "'$TARGET' is not a Davis installation: $marker is missing. Nothing was changed."
done
grep -q '"name": *"tchapi/davis"' "$TARGET/composer.json" \
    || fail "'$TARGET' is not a Davis installation: its composer.json is another project's. Nothing was changed."

if [ -e "$TARGET/.git" ]; then
    fail "'$TARGET' is a git checkout. Update it with git; this script is for installations made from an archive. Nothing was changed."
fi

if [ ! -f "$TARGET/.env.local" ] && [ ! -f "$TARGET/.env.local.php" ]; then
    fail "'$TARGET' has no .env.local: the files are there, but it was never configured. Follow INSTALL.md instead. Nothing was changed."
fi

[ -d "$TARGET/vendor" ] || fail "'$TARGET' has no vendor/ directory: the installation was never finished. Follow INSTALL.md instead. Nothing was changed."

FROM=$(version_of "$TARGET"); [ -n "$FROM" ] || FROM="unknown"
TO=$(version_of "$SOURCE");   [ -n "$TO" ]   || fail "The archive does not say which version it is (src/Version.php)."

# --------------------------------------------------------------------------- PHP
#
# A server rarely has "a PHP". It has several, one of them is called php, and that is often
# the oldest. So the name can be given, and when it does not fit, the script lists what it
# found, because that is the next question.
list_php() {
    for candidate in php php8.5 php85 php8.4 php84 php8.3 php83 php8.2 php82; do
        if command -v "$candidate" > /dev/null 2>&1; then
            printf '    %-8s %s\n' "$candidate" "$("$candidate" -r 'echo PHP_VERSION;' 2> /dev/null || echo '?')"
        fi
    done
}

if ! command -v "$PHP_BIN" > /dev/null 2>&1; then
    say "PHP found on this machine:"; list_php
    fail "There is no PHP called '$PHP_BIN'. Choose one with --php NAME."
fi
if ! "$PHP_BIN" -r "exit(PHP_VERSION_ID >= $PHP_MIN_ID ? 0 : 1);" 2> /dev/null; then
    say "PHP found on this machine:"; list_php
    fail "'$PHP_BIN' is PHP $("$PHP_BIN" -r 'echo PHP_VERSION;' 2> /dev/null || echo '?'), Davis needs $PHP_MIN or newer. Choose another one with --php NAME."
fi
PHP_VERSION=$("$PHP_BIN" -r 'echo PHP_VERSION;')

# ---------------------------------------------------------------------- Composer
#
# A Composer installed on a server is a file with a "#!" line of its own and runs under THAT
# PHP, whichever was chosen above. It would then check, install and run the post-install
# commands for a PHP the application never uses. So it is started with the chosen PHP, and
# the script makes sure the file really is Composer: PHP prints any other file and says
# nothing went wrong.
COMPOSER_FILE="$COMPOSER_ARG"
if [ -z "$COMPOSER_FILE" ]; then
    COMPOSER_FILE=$(command -v composer 2> /dev/null || command -v composer.phar 2> /dev/null || true)
fi
[ -n "$COMPOSER_FILE" ] || fail "Composer was not found. Say where it is with --composer /path/to/composer.phar."

COMPOSER_MODE=""
if [ -f "$COMPOSER_FILE" ] && "$PHP_BIN" "$COMPOSER_FILE" --version --no-ansi 2> /dev/null | grep -q '^Composer version'; then
    COMPOSER_MODE="php"
elif "$COMPOSER_FILE" --version --no-ansi 2> /dev/null | grep -q '^Composer version'; then
    COMPOSER_MODE="direct"
else
    fail "'$COMPOSER_FILE' does not answer like Composer. Say where it is with --composer /path/to/composer.phar."
fi

run_composer() {
    if [ "$COMPOSER_MODE" = "php" ]; then
        "$PHP_BIN" "$COMPOSER_FILE" "$@"
    else
        "$COMPOSER_FILE" "$@"
    fi
}

composer_command() {
    if [ "$COMPOSER_MODE" = "php" ]; then
        printf '%s %s' "$(quoted "$PHP_BIN")" "$(quoted "$COMPOSER_FILE")"
    else
        printf '%s' "$(quoted "$COMPOSER_FILE")"
    fi
}

# Does this PHP have what the NEW version needs? Asked of the lock file of the archive, which
# also knows the extensions that come in through a dependency. Before anything is changed.
step "Checking PHP $PHP_VERSION against the requirements of $TO"
if PLATFORM=$( cd "$SOURCE" && run_composer check-platform-reqs --lock --no-dev --no-ansi 2>&1 ); then
    say "    PHP and its extensions meet them"
else
    printf '%s\n' "$PLATFORM"
    fail "This PHP does not meet the requirements above. Nothing was changed. On a host with several PHP versions, choose another one with --php NAME."
fi
if [ "$COMPOSER_MODE" = "direct" ]; then
    say "    NOTE: '$COMPOSER_FILE' could not be started with '$PHP_BIN' and runs under its own PHP."
    say "          The check above is for that PHP. To use '$PHP_BIN', give --composer /path/to/composer.phar."
fi

# ---------------------------------------------------------------------- database
DB_ENGINE=""; DB_HOST=""; DB_PORT=""; DB_USER=""; DB_PASS=""; DB_NAME=""; DB_DUMP=""

read_database() {
    DBINFO=$(mktemp)
    if ! ( cd "$TARGET" && "$PHP_BIN" -r '
        require "vendor/autoload.php";
        (new Symfony\Component\Dotenv\Dotenv())->bootEnv(".env");
        $url = (string) ($_SERVER["DATABASE_URL"] ?? $_ENV["DATABASE_URL"] ?? "");
        $url = str_replace("%kernel.project_dir%", getcwd(), $url);
        if (0 === strpos($url, "sqlite:")) {
            $path = preg_replace("/\?.*$/", "", substr($url, strlen("sqlite:///")));
            if ("" !== $path && "/" !== $path[0]) { $path = getcwd()."/".$path; }
            echo "sqlite\n\n\n\n\n", $path, "\n";
            exit(0);
        }
        $p = parse_url($url);
        if (!is_array($p) || empty($p["scheme"]) || !empty($p["query"]) && false !== strpos($p["query"], "unix_socket")) { exit(3); }
        echo $p["scheme"], "\n", $p["host"] ?? "", "\n", $p["port"] ?? "", "\n",
            rawurldecode($p["user"] ?? ""), "\n", rawurldecode($p["pass"] ?? ""), "\n", ltrim($p["path"] ?? "", "/"), "\n";
    ' ) > "$DBINFO" 2> /dev/null; then
        rm -f "$DBINFO"
        return 1
    fi
    DB_ENGINE=$(sed -n '1p' "$DBINFO"); DB_HOST=$(sed -n '2p' "$DBINFO"); DB_PORT=$(sed -n '3p' "$DBINFO")
    DB_USER=$(sed -n '4p' "$DBINFO");   DB_PASS=$(sed -n '5p' "$DBINFO"); DB_NAME=$(sed -n '6p' "$DBINFO")
    rm -f "$DBINFO"

    case "$DB_ENGINE" in
        mysql|mariadb|pdo-mysql|pdo_mysql|mysql2)
            DB_ENGINE="mysql"
            for candidate in mariadb-dump mysqldump; do
                if command -v "$candidate" > /dev/null 2>&1; then DB_DUMP=$candidate; break; fi
            done
            [ -n "$DB_DUMP" ] || { DB_PROBLEM="neither mariadb-dump nor mysqldump is in the PATH"; return 1; }
            ;;
        postgres|postgresql|pgsql|pdo-pgsql|pdo_pgsql)
            DB_ENGINE="postgresql"
            command -v pg_dump > /dev/null 2>&1 || { DB_PROBLEM="pg_dump is not in the PATH"; return 1; }
            DB_DUMP="pg_dump"
            ;;
        sqlite)
            [ -f "$DB_NAME" ] || { DB_PROBLEM="the SQLite file '$DB_NAME' does not exist"; return 1; }
            ;;
        *)
            DB_PROBLEM="the database type '$DB_ENGINE' is not one this script can back up"; return 1
            ;;
    esac
    [ -n "$DB_NAME" ] || { DB_PROBLEM="DATABASE_URL names no database"; return 1; }
    return 0
}

DB_PROBLEM="DATABASE_URL could not be read from the configuration of the installation"
if [ "$SKIP_BACKUP" -eq 0 ]; then
    read_database || fail "The database cannot be backed up: $DB_PROBLEM.
       Nothing was changed. Back it up yourself and run again with --skip-backup."
fi

# ------------------------------------------------------------- what would change
retired_paths() {
    for p in $RETIRED; do
        if [ -e "$TARGET/$p" ] || [ -L "$TARGET/$p" ]; then printf '%s\n' "$p"; fi
    done
}

# Files in the program directories of the installation that the archive does not have.
stale_files() {
    for d in $PROGRAM_DIRS; do
        [ -d "$TARGET/$d" ] || continue
        ( cd "$TARGET" && find "$d" \( -type f -o -type l \) ) | while IFS= read -r f; do
            is_protected "$f" && continue
            if [ ! -e "$SOURCE/$f" ] && [ ! -L "$SOURCE/$f" ]; then printf '%s\n' "$f"; fi
        done
    done
}

BACKUP_PARENT="$BACKUP_ARG"
if [ -z "$BACKUP_PARENT" ]; then
    BACKUP_PARENT=$(dirname -- "$TARGET")
    [ "$BACKUP_PARENT" != "/" ] || BACKUP_PARENT=""
fi
BACKUP_BASE="$BACKUP_PARENT/davis-backup-$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR=$BACKUP_BASE
BACKUP_NUMBER=1
while [ -e "$BACKUP_DIR" ]; do
    BACKUP_NUMBER=$((BACKUP_NUMBER + 1))
    BACKUP_DIR="$BACKUP_BASE-$BACKUP_NUMBER"
done

step "Davis $FROM -> $TO"
say "    installation: $TARGET"
say "    archive:      $SOURCE"
say "    PHP:          $PHP_BIN ($PHP_VERSION)"
say "    Composer:     $(composer_command)"
if [ "$SKIP_BACKUP" -eq 1 ]; then
    say "    backup:       none (--skip-backup)"
else
    say "    backup:       $BACKUP_DIR"
    if [ "$DB_ENGINE" = "sqlite" ]; then
        say "    database:     SQLite, $DB_NAME"
    else
        say "    database:     $DB_ENGINE, '$DB_NAME' on ${DB_HOST:-localhost}"
    fi
fi
[ "$FROM" != "$TO" ] || say "    NOTE: the installation already says $TO. Its files are replaced all the same."

STALE=$(stale_files)
GONE=$(retired_paths)

say
say "    Kept as they are: .env.local, var/, config/secrets/ and everything at the top"
say "    level that is not part of the archive."
say "    Replaced: the files of the archive, $(cd "$SOURCE" && find $PROGRAM_DIRS -type f | wc -l | tr -d ' ') of them in $(echo "$PROGRAM_DIRS" | sed 's/ /, /g')."
if [ -n "$STALE" ]; then
    say "    Removed, because $TO does not have them:"
    printf '%s\n' "$STALE" | sed 's/^/      /'
else
    say "    Removed from the program directories: nothing."
fi
if [ -n "$GONE" ]; then
    say "    Removed, because the archive no longer ships them:"
    printf '%s\n' "$GONE" | sed 's/^/      /'
fi
if ! cmp -s "$SOURCE/public/.htaccess" "$TARGET/public/.htaccess" 2> /dev/null; then
    say "    NOTE: public/.htaccess differs from the one in the archive and is replaced."
    say "          Lines of your own in it are gone afterwards (they are in the backup)."
fi
say "    Then: Composer installs the dependencies, the migrations run, the cache is cleared."

if [ "$DRY_RUN" -eq 1 ]; then
    say
    say "Dry run: nothing was changed."
    exit 0
fi

if [ "$ASSUME_YES" -eq 0 ]; then
    [ -t 0 ] || fail "Not asking, because there is no terminal. Run again with --yes. Nothing was changed."
    printf '\nUpdate %s now? [y/N] ' "$TARGET"
    read -r answer
    case "$answer" in y|Y|yes|YES) ;; *) say "Nothing was changed."; exit 0 ;; esac
fi

# ------------------------------------------------------------------------ backup
#
# Migrations run forwards. There is no way back but this copy, and a script that only
# RECOMMENDS a backup does not have one. So it comes before the first change, and a backup
# that fails stops the update.
if [ "$SKIP_BACKUP" -eq 1 ]; then
    step "Backup skipped (--skip-backup)"
else
    step "Backing up to $BACKUP_DIR"
    ( umask 077 && mkdir -p "$BACKUP_DIR" ) \
        || fail "Could not create $BACKUP_DIR. Nothing was changed. Choose another place with --backup-dir DIR."

    # The code with .env.local, without var/ (data and cache) and vendor/ (Composer brings it back).
    ( umask 077 && cd "$TARGET" && tar -czf "$BACKUP_DIR/files.tar.gz" --exclude=./var --exclude=./vendor . ) \
        || fail "Could not back up the files. Nothing was changed."
    say "    files.tar.gz     the program files and .env.local of $FROM"

    # The password goes through the environment, not as an argument: arguments are in the
    # process list for everybody on the machine to read.
    case "$DB_ENGINE" in
        mysql)
            ( umask 077 && MYSQL_PWD="$DB_PASS" "$DB_DUMP" --host="${DB_HOST:-localhost}" --port="${DB_PORT:-3306}" \
                --user="$DB_USER" --single-transaction --no-tablespaces "$DB_NAME" > "$BACKUP_DIR/database.sql" ) \
                || fail "Could not back up the database with $DB_DUMP. Nothing was changed.
       Back it up yourself and run again with --skip-backup."
            ;;
        postgresql)
            ( umask 077 && PGPASSWORD="$DB_PASS" pg_dump --host="${DB_HOST:-localhost}" --port="${DB_PORT:-5432}" \
                --username="$DB_USER" --no-owner --file="$BACKUP_DIR/database.sql" "$DB_NAME" ) \
                || fail "Could not back up the database with pg_dump. Nothing was changed.
       Back it up yourself and run again with --skip-backup."
            ;;
        sqlite)
            ( umask 077 && cp "$DB_NAME" "$BACKUP_DIR/database.sqlite" ) \
                || fail "Could not copy the SQLite file. Nothing was changed."
            ;;
    esac
    if [ "$DB_ENGINE" != "sqlite" ] && [ ! -s "$BACKUP_DIR/database.sql" ]; then
        fail "The database backup is empty. Nothing was changed. Back it up yourself and run again with --skip-backup."
    fi
    say "    database.*       the database before the migrations"
fi

# ------------------------------------------------------------- when it goes wrong
#
# Davis has no maintenance mode to leave switched on, so a run that stops after the files
# were replaced says exactly that, and how to go back. Nothing is rolled back by itself.
FILES_TOUCHED=0
MIGRATIONS_STARTED=0

on_exit() {
    code=$?
    if [ "$code" -ne 0 ] && [ "$FILES_TOUCHED" -eq 1 ]; then
        say
        say "The update stopped after the files were replaced. The installation is in between"
        say "two versions and must not stay that way."
        if [ "$SKIP_BACKUP" -eq 1 ]; then
            say "There is no backup from this run (--skip-backup). Restore your own."
        else
            say "To go back to $FROM:"
            say
            say "    cd $(quoted "$TARGET")"
            say "    rm -rf $PROGRAM_DIRS"
            say "    tar -xzf $(quoted "$BACKUP_DIR/files.tar.gz")"
            say "    rm -rf var/cache/*"
            say "    $(composer_command) install --no-dev --optimize-autoloader"
            say
            say "Files at the top level that only $TO has stay behind. Nothing loads them."
            if [ "$MIGRATIONS_STARTED" -eq 1 ]; then
                say
                say "The migrations had started, so the database goes back too:"
                case "$DB_ENGINE" in
                    mysql)      say "    mysql --host=${DB_HOST:-localhost} --user=$DB_USER -p $DB_NAME < $(quoted "$BACKUP_DIR/database.sql")" ;;
                    postgresql) say "    psql --host=${DB_HOST:-localhost} --username=$DB_USER $DB_NAME < $(quoted "$BACKUP_DIR/database.sql")   # into an emptied database" ;;
                    sqlite)     say "    cp $(quoted "$BACKUP_DIR/database.sqlite") $(quoted "$DB_NAME")" ;;
                esac
            fi
        fi
    fi
    exit "$code"
}
trap on_exit EXIT

# ----------------------------------------------------------------- replace files
step "Replacing files"
FILES_TOUCHED=1

printf '%s\n' "$GONE" | while IFS= read -r p; do
    [ -n "$p" ] || continue
    rm -rf "${TARGET:?}/$p"
    say "    removed: $p"
done

# tar and not cp -r: it keeps the permissions and creates directories. `-m` gives the files
# the time of the update instead of the time in the archive, so a code cache of PHP that
# compares times sees them as changed. Whatever belongs to the server is left out, in case
# Composer was run inside the unpacked archive.
( cd "$SOURCE" && tar -cf - --exclude=./var --exclude=./vendor --exclude=./.env.local \
    --exclude=./.env.local.php --exclude=./config/secrets --exclude=./public/bundles . ) \
    | ( cd "$TARGET" && tar -xmf - )

# The part that unpacking alone does not do.
printf '%s\n' "$STALE" | while IFS= read -r f; do
    [ -n "$f" ] || continue
    rm -f "${TARGET:?}/$f"
    say "    removed: $f"
done
for d in $PROGRAM_DIRS; do
    [ -d "$TARGET/$d" ] || continue
    find "$TARGET/$d" -depth -type d -exec rmdir {} \; 2> /dev/null || true
done

# The compiled container of the old version. With it in place the next console call would
# start the OLD application on the NEW files.
for entry in "$TARGET"/var/cache/*; do
    if [ -e "$entry" ]; then rm -rf "$entry"; fi
done
say "    files replaced, compiled cache of $FROM removed"

# ------------------------------------------------------------------ dependencies
#
# First Composer, and only then the console: until here the new code has the old vendor/.
step "Installing the dependencies"
( cd "$TARGET" && run_composer install --no-dev --optimize-autoloader --no-interaction --no-ansi )

step "Running the migrations"
MIGRATIONS_STARTED=1
( cd "$TARGET" && "$PHP_BIN" bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration )

step "Clearing the cache"
( cd "$TARGET" && "$PHP_BIN" bin/console cache:clear --no-interaction )

NOW=$(version_of "$TARGET")
[ "$NOW" = "$TO" ] || fail "The installation says '$NOW' after the update, not '$TO'."

FILES_TOUCHED=0
step "Done: Davis $TO"
if [ "$SKIP_BACKUP" -eq 0 ]; then
    say "    The backup of $FROM is in $BACKUP_DIR."
    say "    It holds .env.local and the database: keep it out of reach, delete it when you are sure."
fi
say "    New settings come with their default in .env. To change one, set it in .env.local;"
say "    the release notes name what is new."
say "    If the web server keeps PHP running with a code cache (PHP-FPM with OPcache) and the"
say "    old version keeps answering, reload it."
