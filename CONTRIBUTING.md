# Contributing to `coolms/core-doctrine`

Thank you for taking the time. This file is the whole of the process -- if
something here is unclear or wrong, that is a bug in this file and worth an
issue of its own.

## Releases leave on Tuesdays

`develop` is the integration branch. Merge there when your work is green.

**A release train leaves on Tuesday.** Whatever is in `develop` and passing goes
out. Nothing jumps the train because it feels urgent, and nothing holds the
train because it is not finished -- it catches the next one.

The one exception is a **security fix**, which ships when it is ready, on any
day.

If your pull request misses a Tuesday, nothing is wrong. It goes out on the
next one.

## Versioning is strict semver, and majors are planned

| Change | Version |
|---|---|
| a fix | patch -- `1.2.3` to `1.2.4` |
| a backward-compatible addition | minor -- `1.2.3` to `1.3.0` |
| a break | major -- and **not on the Tuesday it becomes ready** |

Majors are planned, not triggered. A breaking change that is finished waits for
a planned major; it does not create one.

### The major is shared across the platform; the minor and patch are this package's own

`coolms/core-doctrine` is a **platform package** -- it builds on `coolms/core` and only
makes sense inside CoolMS. Every platform package shares a **major** number. The
current generation is **v2**.

Minor and patch move independently, so two platform packages released on the
same day will usually differ after the first dot. That is correct, not a
mistake.

**Which packages share the major?** Exactly those that require `coolms/core`,
directly or through another package, plus `coolms/core` itself. Standalone
libraries published from the same project -- `coolms/rql`, `coolms/rql-doctrine`,
`coolms/dtmpl`, `coolms/dtmpl-bundle` -- have users who never touch CoolMS, so
they version entirely on their own, majors included. If this package gains or
loses a dependency on `coolms/core`, it changes sides.

⚠️ **So the major here is a generation marker, not a per-package break.**
`coolms/core-doctrine 2.0.0` does not assert that this package broke something; it
asserts that it belongs to the v2 generation. Before the generation existed,
requiring one package could resolve the whole set backwards onto an older one --
including a template engine from before output encoding existed -- and Composer
would report success.

**Inside a generation, semver holds exactly:** a minor adds, a patch fixes, and
nothing breaks. A break is announced as a deprecation (below) and removed at the
next generation boundary, when every platform package crosses together.

### What "compatible" guarantees, precisely

**The latest `2.x` of every platform package works with the latest `2.x` of
every other.** Not "any `2.x` with any `2.x`" -- that would be a promise the code
cannot keep, because these packages are developed together and one of them will
use something another added in a later minor.

So the rule for a minor is:

> A minor may raise a sibling constraint's floor **within the same major**
> (`^2.0` to `^2.3`). It may never move to a different major. Only a generation
> boundary does that.

The constraint in `composer.json` is therefore the real guarantee, and CI proves
it by running the suite against both the highest and the **lowest** dependency
resolution. A floor that drifted below what the code actually needs fails the
build rather than waiting to fatal in somebody's application.

## A break arrives as a deprecation first

**This is the part that makes the version numbers mean anything.**

A break is introduced as a **deprecation in a minor release**: the old path keeps
working, emits a deprecation notice, and the changelog names the replacement. It
is removed in the **next planned major**.

So a change that would break callers is two pull requests, usually months apart:

1. Add the replacement. Keep the old path working. Emit a deprecation notice.
   Name the replacement in `CHANGELOG.md`. This ships in a minor.
2. Remove the old path. This ships in the next planned major.

Without the deprecation window a Tuesday cadence would just batch the same
churn -- two breaking changes a fortnight apart still produce 2.0 and then 3.0.
The window is the mechanism; the calendar is not.

## Write the changelog in the same commit as the work

`CHANGELOG.md` is written **at merge time, in the same commit as the change** --
never reconstructed on a Tuesday from a list of merges. Unreleased work lives
under a `## Unreleased` heading; the release renames it and adds a date.

Say what changed and, for anything a caller can notice, why. A changelog entry
that names a replacement is the difference between a deprecation someone can act
on and a notice they will suppress.

A pull request that changes behaviour and does not touch `CHANGELOG.md` is
incomplete. That is not bureaucracy: the changelog is the only place a
deprecation reaches the person who has to act on it.

## Before you open a pull request

```bash
composer install
vendor/bin/phpunit
```

Match the surrounding code -- its comment density, its naming, its idiom. Tests
are expected for anything a caller can observe; a test that would still pass if
the change were reverted is not one.
