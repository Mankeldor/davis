# Cutting a release of this fork

A release is the one step that cannot be taken back: once the archive is on the release
page, somebody may have fetched it. That is why every step is written down here.

**A tag releases.** Pushing the branch publishes nothing; pushing a tag `vX.Y.Z-st2.N` starts
the workflow `.github/workflows/release.yml`, which builds the archive from the tag and
creates the release page. Nothing is deployed by either.

Releases are cut from the branch `st2`. The branches that are meant for the original project
have none of the files named here.

## 1. Pick the version

`X.Y.Z` is the Davis release the branch is built on, `N` counts the releases of the fork on
top of it and starts again at 1 when the Davis release changes. A tag that is pushed is
never moved: a fix gets the next number.

## 2. Close the changelog section

In `CHANGELOG.fork.md`, add `## [X.Y.Z-st2.N] - YYYY-MM-DD` under `## [Unreleased]` and write
what changes for whoever runs an installation: what is new, what a setting does, and above
all whether the update needs a manual step. The first lines of the section are the first
thing on the release page under "Changes".

Keep the last heading of the file, `## Older versions`. The release notes take a section up
to the next `## ` heading; without one below it, the oldest section would run to the end of
the file.

## 3. Set the version the application shows

`src/Version.php` has to say `X.Y.Z-st2.N`. The home page, the diagnostics page and the
addresses of the stylesheets and scripts show it, and `update.sh` reads it to say which
version it comes from and goes to. The release stops if it does not match the tag.

## 4. Try the release before tagging

The steps of the workflow are scripts, and before the tag exists is the only time a mistake
in the archive or in the notes costs nothing. From the branch, with everything committed:

```bash
export RELEASE_DRY_RUN=1 DIST=/tmp/davis-release GITHUB_REPOSITORY=<owner>/<repository>
sh .github/release/check.sh vX.Y.Z-st2.N
sh .github/release/build-package.sh vX.Y.Z-st2.N
sh .github/release/release-notes.sh vX.Y.Z-st2.N
```

Then **read** `/tmp/davis-release/release-notes.md` from top to bottom, and look at what is
in the archive:

```bash
tar -tzf /tmp/davis-release/davis-vX.Y.Z-st2.N.tar.gz | awk -F/ '{print $2}' | sort -u
```

`build-package.sh` checks the archive in both directions and stops if a file is missing or
one is in it that should not be. What goes into the archive is decided in `.gitattributes`
and nowhere else. A new file at the top level that an installation does not need gets an
`export-ignore` line there, and a line in the "must not" list of the script; a new file an
installation cannot do without gets a line in its "must" list.

## 5. Look for addresses that are not placeholders

Examples in the files of the archive use reserved names only (`example.org`, `.invalid`). A
name that merely looks like a placeholder can be registered by anybody.

```bash
git grep -InE '(https?://|@)[A-Za-z0-9.-]+\.[a-z]{2,}' -- INSTALL.md update.sh CHANGELOG.fork.md RELEASE.md .github/release \
  | grep -vE 'example\.(com|net|org)|\.(example|invalid|test|localhost)\b'
```

What is left has to be an address that really is meant.

## 6. Run the tests

The workflow runs the tests for the tag before it releases (PHP 8.4, MariaDB), so a tag whose
tests fail is not released. Run them before tagging all the same; a tag that cannot be
released has to be replaced by the next number.

## 7. Commit and push the branch

```bash
git push origin st2
```

## 8. Tag and push the tag

```bash
git tag -a vX.Y.Z-st2.N -m "Davis X.Y.Z-st2.N"
git push origin vX.Y.Z-st2.N
```

## 9. What the workflow does now

1. **Changelog and version** (`check.sh`): stops if `CHANGELOG.fork.md` has no section for
   the version or `src/Version.php` says another one. Nothing has been published then.
2. **Tests.**
3. **Archive** (`build-package.sh`): `git archive` of the tag, checked in both directions,
   packed as `davis-vX.Y.Z-st2.N.tar.gz` with a `.sha256` file.
4. **Notes** (`release-notes.sh`): how to fetch and check the archive, how to install, how
   to update, what is in the archive, the checksum, and the changelog section.
5. **Release page** with both files attached, created with the token of the run.

## 10. Check afterwards

- The release page: is the text complete, are the two files attached?
- Fetch the archive with the commands from the page and let `sha256sum -c` say OK.
- The page also offers "Source code" archives that GitHub builds itself. They have the same
  content; the attached archive is the one the checksum is for.

## When something goes wrong

**The first job stops for a missing changelog section or a wrong version.** Nothing is
published. Take the tag back, fix the branch, tag again:

```bash
git tag -d vX.Y.Z-st2.N
git push --delete origin vX.Y.Z-st2.N
# fix CHANGELOG.fork.md and src/Version.php, commit, push
git tag -a vX.Y.Z-st2.N -m "Davis X.Y.Z-st2.N" && git push origin vX.Y.Z-st2.N
```

That is only right as long as nothing was published under the tag. Afterwards the fix is the
next number.

**A later job fails for a reason outside the tag** (a download that timed out, a service
that did not start). Start the workflow again for the same tag: Actions, "Release", "Run
workflow", branch `st2`, and the tag in the field. The tag stays where it is.

**The workflow file is wrong.** Fix `.github/workflows/release.yml` on the branch, push the
branch and start the workflow again for the same tag with "Run workflow". A run started that
way takes the workflow file from the branch and checks out the tag, so the tag does not have
to move. Nothing in the archive changes either: `.github/` is not in it.

**The scripts themselves are wrong.** They are taken from the tag, so a fix needs the next
number.

### What went wrong before

- **The tests of the first release run found no tests.** The test job runs in the PHP image,
  which has no git. Without git, `actions/checkout` downloads an archive of the tag instead
  of cloning it, and that archive leaves out everything `.gitattributes` marks
  `export-ignore`: `tests/`, `phpunit.xml.dist`, `.env.test`. PHPUnit printed its help and
  the job failed with nothing but an exit code. The job now installs git before the
  checkout and checks that the tests are there before it starts them. Keep both: any job
  that needs a file that is not in the archive needs a real checkout.

## Settings of the repository

- Actions have to be enabled for the repository. In a fork they are off until somebody turns
  them on in the "Actions" tab.
- `st2` has to be the default branch of the repository. GitHub offers "Run workflow" only
  for workflows whose file is on the default branch.
- The workflow asks for `contents: write` for the job that creates the release page, and
  uses the token of the run. No personal token and no secret has to be stored.
