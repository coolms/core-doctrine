# Changelog

All notable changes to `coolms/core-doctrine` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning is described in `CONTRIBUTING.md` -- read it before assuming what a
major number means here.

⚠️ Entries dated before 2026-09-01 were **reconstructed** from tags and commit
history when this file was created. Every entry after that is written in the
same commit as the change it describes.

## 2.0.0-alpha1 - 2026-09-01

**A pre-release. It carries no compatibility promise**, which is the honest
statement of where the platform is: the shape is still moving, and a stable tag
would be a promise that cannot be kept yet.

Composer will not install it under default stability. Either set
`"minimum-stability": "alpha"` with `"prefer-stable": true`, or ask for it per
package with `^2.0@alpha`. A bare `composer require coolms/core-doctrine` takes the
newest **stable** release instead -- which is the previous generation -- and
reports success while doing it.

Releases are suspended while development is moving fast and there are no
external consumers of these packages. This tag establishes the baseline the
documentation describes; nothing follows it until somebody outside the project
installs one, at which point the release policy resumes.

### The v2 generation -- a version number, and nothing else

This release moves `coolms/core-doctrine` to `2.0.0` **without a single change to its
code**. Nothing was added, removed, renamed or fixed.

Every CoolMS platform package -- everything that requires `coolms/core` --
shares a major number, so that a set of packages carrying the same major is
known to work together. The whole set crosses to v2 at once, and this package
has nothing else in the crossing.

Before the shared major existed, `composer require coolms/entity-bundle`
resolved the entire set backwards onto its first generation -- including a
template engine from before output encoding existed -- and Composer reported
success. A shared major makes that resolution unreachable by accident.

**Upgrading: widen your constraint from `^1.0` to `^2.0`. There is nothing
else to do.** No class, signature, or behaviour changed. Breaks are announced as
deprecations in a minor and removed at a generation boundary; this boundary
removes none, because there were none to remove.

The standalone libraries published alongside the platform -- `coolms/rql`,
`coolms/rql-doctrine`, `coolms/dtmpl`, `coolms/dtmpl-bundle` -- do **not** take
this major. They have users who never touch CoolMS, and their numbers answer to
their own APIs.

### Changed: sibling constraints move to the v2 generation

- `coolms/core`: `^1.0` to `^2.0`
- `coolms/core-persistence-implementation` (provided): `1.0` to `2.0`


The constraints on `coolms/rql` and `coolms/rql-doctrine` are unchanged. Those
are standalone libraries and do not take the platform generation.

### Fixed: the `coolms/rql-doctrine` floor was dishonest

Raised from `^1.0` to `^1.0.1`.

This package imports `CoolMS\Rql\Doctrine\DoctrineRqlVisitor`. That namespace
did not exist until `rql-doctrine` **1.0.1**, which renamed it from
`CoolMS\RqlDoctrine\`. Installing the floor this package declared therefore
produced a fatal error on a class that is not there.

Nobody hit it, because every resolution picks the newest version. That is
exactly why the constraint drifted, and why CI now resolves dependencies at
their **lowest** admissible versions as well as their highest -- a floor that
has drifted below what the code needs fails the build instead of waiting to
fatal in an application.

## 1.0.1 - 2026-08-17

### Fixed

Raise the `symfony/event-dispatcher-contracts` floor to 3.4.2. The declared
floor admitted a version without the interface this package calls.

## 1.0.0 - 2026-08-17

First release. Doctrine ORM and DBAL adapters for `coolms/core`: the outbox and
inbox stores, the sync change feed, config overrides, range column types, and
the RQL-aware base repository.
