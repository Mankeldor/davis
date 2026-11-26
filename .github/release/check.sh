#!/bin/sh
#
# First step of a release, before anything is built or published: is this tag releasable?
#
#     sh .github/release/check.sh v5.6.3-st2.3
#
# It stops the release when the changelog has no section for the version, when the version the
# application shows is not the version of the tag, or when the tag is not the commit that is
# checked out. At this point nothing has been published, so a wrong tag can still be taken back.
#
# RELEASE_DRY_RUN=1 lets it run before the tag exists (see RELEASE.md).

set -eu

TAG="${1:-}"
[ -n "$TAG" ] || { echo "Usage: sh .github/release/check.sh <tag>" >&2; exit 2; }

CHANGELOG="CHANGELOG.fork.md"
VERSION="${TAG#v}"
problems=0

case "$TAG" in
    v[0-9]*.[0-9]*.[0-9]*-st2.[0-9]*) ;;
    *)
        echo "ERROR: '$TAG' does not look like a release tag of this fork (vX.Y.Z-st2.N)."
        exit 1
        ;;
esac

# The release notes are built from this section. Without it the release page would say
# nothing about what changed.
if ! awk -v v="$VERSION" 'index($0, "## [" v "]") == 1 { found = 1 } END { exit !found }' "$CHANGELOG"; then
    echo "ERROR: $CHANGELOG has no section '## [${VERSION}]'."
    echo
    echo "  The release notes are built from that section."
    problems=1
fi

# The home page, the diagnostics page and the asset URLs show this constant. A release whose
# archive says another version than its tag cannot be told apart from the one before.
SHOWN=$(sed -n "s/.*const VERSION = '\([^']*\)'.*/\1/p" src/Version.php)
if [ "$SHOWN" != "$VERSION" ]; then
    echo "ERROR: src/Version.php says '${SHOWN}', the tag says '${VERSION}'."
    problems=1
fi

# The archive is built from the commit that is checked out, so that has to be the tag.
if git rev-parse -q --verify "refs/tags/${TAG}" > /dev/null; then
    if [ "$(git rev-parse "refs/tags/${TAG}^{commit}")" != "$(git rev-parse HEAD)" ]; then
        echo "ERROR: The tag ${TAG} is not the commit that is checked out."
        problems=1
    fi
elif [ "${RELEASE_DRY_RUN:-0}" != "1" ]; then
    echo "ERROR: There is no tag ${TAG} here. To try a release before tagging, set RELEASE_DRY_RUN=1."
    problems=1
fi

if [ "$problems" -ne 0 ]; then
    echo
    echo "  Nothing has been published yet. If the tag is already pushed, take it back, fix"
    echo "  the branch and tag again:"
    echo "    git tag -d ${TAG}"
    echo "    git push --delete origin ${TAG}"
    echo "    # fix ${CHANGELOG} and src/Version.php, commit, push"
    echo "    git tag -a ${TAG} -m 'Davis ${VERSION}' && git push origin ${TAG}"
    echo
    echo "  See RELEASE.md for the full list of steps."
    exit 1
fi

echo "--> ${TAG}: changelog section found, src/Version.php matches."
