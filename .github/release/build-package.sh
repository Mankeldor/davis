#!/bin/sh
#
# Builds the release archive and its checksum from the commit that is checked out.
#
#     sh .github/release/build-package.sh v5.6.3-st2.3
#
# What goes into the archive is decided in one place: .gitattributes (export-ignore), which
# `git archive` reads. This script does not keep a second list of what to pack. It keeps two
# short lists of what to CHECK, one in each direction, because the result is published and
# cannot be taken back: a file that must be there and is not, a file that must not be there
# and is.
#
# Writes dist/davis-<tag>.tar.gz and dist/davis-<tag>.tar.gz.sha256 (DIST= another directory).

set -eu

TAG="${1:-}"
[ -n "$TAG" ] || { echo "Usage: sh .github/release/build-package.sh <tag>" >&2; exit 2; }

DIST="${DIST:-dist}"
NAME="davis-${TAG}"
ARCHIVE="${NAME}.tar.gz"

mkdir -p "$DIST"
rm -rf "${DIST:?}/${NAME}" "${DIST:?}/${ARCHIVE}" "${DIST:?}/${ARCHIVE}.sha256"

git archive --format=tar --prefix="${NAME}/" HEAD | tar -x -C "$DIST"

# One path of every kind whose absence breaks an install: entry points, the dependency files,
# the default configuration, the schema, a template, a translation, and what the operator is
# told to read and run.
missing=""
for f in .env composer.json composer.lock symfony.lock LICENSE README.md INSTALL.md update.sh \
         bin/console public/index.php public/.htaccess public/css public/js \
         config/bundles.php config/services.yaml config/packages/prod/monolog.yaml \
         src/Kernel.php src/Version.php src/Controller/Api/UserManagementController.php \
         migrations templates/index.html.twig translations/messages+intl-icu.en.xlf; do
    [ -e "${DIST}/${NAME}/$f" ] || missing="$missing $f"
done
[ -n "$(ls -A "${DIST}/${NAME}/migrations" 2> /dev/null)" ] || missing="$missing migrations/*"
[ -x "${DIST}/${NAME}/update.sh" ] || missing="$missing update.sh(executable)"

# The more important list. A missing file shows itself; one that should not be there never does.
surplus=""
for f in .git .github .gitattributes .gitignore .dockerignore .hadolint.yaml .php-cs-fixer.php \
         .env.local .env.test tests phpunit.xml.dist bin/phpunit docker docs _screenshots \
         vendor var dist CHANGELOG.fork.md RELEASE.md; do
    [ ! -e "${DIST}/${NAME}/$f" ] || surplus="$surplus $f"
done

if [ -n "$missing" ]; then
    echo "ERROR: These paths are missing from the archive:$missing"
    echo "       Check .gitattributes: an export-ignore probably covers too much."
fi
if [ -n "$surplus" ]; then
    echo "ERROR: These paths do not belong in the archive:$surplus"
    echo "       Check .gitattributes: an export-ignore is missing there."
fi
[ -z "$missing$surplus" ] || exit 1

FILES=$(find "${DIST}/${NAME}" -type f | wc -l | tr -d ' ')
rm -rf "${DIST:?}/${NAME}"

# `gzip -n` leaves the time out of the header, so building the same tag twice gives the same
# file and the same checksum.
git archive --format=tar --prefix="${NAME}/" HEAD | gzip -n -9 > "${DIST}/${ARCHIVE}"

if command -v sha256sum > /dev/null 2>&1; then
    ( cd "$DIST" && sha256sum "$ARCHIVE" > "${ARCHIVE}.sha256" )
else
    ( cd "$DIST" && shasum -a 256 "$ARCHIVE" > "${ARCHIVE}.sha256" )
fi

echo "--> ${DIST}/${ARCHIVE}: ${FILES} files, $(du -h "${DIST}/${ARCHIVE}" | cut -f1 | tr -d ' ')"
echo "--> $(cat "${DIST}/${ARCHIVE}.sha256")"
