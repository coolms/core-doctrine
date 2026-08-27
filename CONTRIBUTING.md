# Contributing to `coolms/core-doctrine`

Thank you for taking the time. This file is the whole of the process — if
something here is unclear or wrong, that is a bug in this file and worth an
issue of its own.

## Releases leave on Tuesdays

`develop` is the integration branch. Merge there when your work is green.

**A release train leaves on Tuesday.** Whatever is in `develop` and passing goes
out. Nothing jumps the train because it feels urgent, and nothing holds the
train because it is not finished — it catches the next one.

The one exception is a **security fix**, which ships when it is ready, on any
day.

If your pull request misses a Tuesday, nothing is wrong. It goes out on the
next one.

## Versioning is strict semver, and majors are planned

| Change | Version |
|---|---|
| a fix | patch — `1.2.3 -> 1.2.4` |
| a backward-compatible addition | minor — `1.2.3 -> 1.3.0` |
| a break | major — and **not on the Tuesday it becomes ready** |

Majors are planned, not triggered. A breaking change that is finished waits for
a planned major; it does not create one.

### A break arrives as a deprecation first

**This is the part that makes the version numbers mean anything.**

A break is introduced as a **deprecation in a minor release**: the old path keeps
working, emits a deprecation notice, and the changelog names the replacement. It
is removed in the **next planned major**.

So a change that would break callers is two pull requests, usually months apart:

1. Add the replacement. Keep the old path working. Emit a deprecation notice.
   Name the replacement in `CHANGELOG.md`. This ships in a minor.
2. Remove the old path. This ships in the next planned major.

Without the deprecation window a Tuesday cadence would just batch the same
churn — two breaking changes a fortnight apart still produce 2.0 and then 3.0.
The window is the mechanism; the calendar is not.

## Write the changelog in the same commit as the work

`CHANGELOG.md` is written **at merge time**, in the same commit as the change —
never reconstructed on a Tuesday from a list of merges. Unreleased work lives
under a `## Unreleased` heading; the release simply renames it.

Say what changed and, for anything a caller can notice, why. A changelog entry
that names a replacement is the difference between a deprecation someone can act
on and a notice they will suppress.

## The major is shared; the minor and patch are yours

Every published CoolMS package shares a **major** number — the current
generation is **v2**. Minor and patch move independently, so two packages
released on the same day will usually differ after the first dot. That is
correct, not a mistake.

⚠️ **This means the major is a generation marker, not a per-package break.**
`coolms/rql 2.0.0` does not assert that `rql` broke something; it asserts that
`rql` belongs to the v2 generation. Any set of v2 packages is known to work
together, and that is what the shared number is for.

**Inside a generation, semver holds exactly:** a minor adds, a patch fixes, and
nothing breaks. A break is announced as a deprecation (above) and removed at the
next generation boundary, when every package crosses together.

Compatibility is expressed where a machine can enforce it — the `^2.0`
constraints in `composer.json` — and proven by CI running the suite against both
the `highest` and `lowest` dependency resolutions, so a constraint floor that
drifted below what the code needs fails the build.

## Before you open a pull request

```bash
composer install
vendor/bin/phpunit
```

Match the surrounding code — its comment density, its naming, its idiom. Tests
are expected for anything a caller can observe; a test that would still pass if
the change were reverted is not one.

## Why the versions look like this

Two questions come up often enough to answer here rather than elsewhere.

**Why does every package share a major?** So that "which generation am I on"
has a single answer, and so that any set of packages carrying the same major is
known to work together. Before that was true, requiring one package could
resolve the whole set backwards onto an older generation — including a template
engine from before output encoding existed — and Composer would report success.

**Why do the minors and patches differ, then?** Because they record what
actually changed. Moving every package to the same minor whenever one of them
fixed something would mean publishing eleven releases containing nothing, in
eleven separate repositories, each with its own commit and CI run.
