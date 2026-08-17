# coolms/core-doctrine

[![CI](https://github.com/coolms/core-doctrine/actions/workflows/ci.yml/badge.svg)](https://github.com/coolms/core-doctrine/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/coolms/core-doctrine)](https://packagist.org/packages/coolms/core-doctrine)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.5-777bb4)](https://www.php.net/releases/8.5/en.php)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**The Doctrine ORM/DBAL adapter for
[`coolms/core`](https://github.com/coolms/core).** Everywhere the platform
commits to Doctrine, in one package.

```json
"provide": { "coolms/core-persistence-implementation": "1.0" }
```

That line is the point. `coolms/core-module` requires the virtual package, this
one provides it, and an alternative adapter substitutes by providing the same
thing. Nothing upstream names a Doctrine class.

## Installation

```bash
composer require coolms/core-doctrine
```

Then register the bundle:

```php
// config/bundles.php
CoolMS\Core\Doctrine\CoreDoctrineBundle::class => ['all' => true],
```

That is the whole integration. The bundle prepends its own Doctrine config, so
no edit to your `doctrine.yaml` is required.

## What you get

Registering the bundle binds the kernel's persistence contracts:

| Contract (`coolms/core`) | Implementation |
|---|---|
| `TransactionRunnerInterface` | `DoctrineTransactionRunner` |
| `OutboxAppenderInterface` | `PersistingOutboxAppender` |
| `OutboxRelayRepositoryInterface` | `DbalOutboxRelayRepository` |
| `ProcessedMessageStoreInterface` | `DbalProcessedMessageStore` |
| `ConfigOverrideRepositoryInterface` | `ConfigOverrideRepository` |

Plus three custom DBAL column types — `date_range`, `datetime_range`,
`time_range` — and the ORM mapping for Core's four persisted rows.

## Why the mapping is XML

The entity classes ship in `coolms/core`, which must not import the ORM. So the
mapping lives here and travels with the adapter:

```php
'is_bundle' => false,
'type'      => 'xml',
'dir'       => '%kernel.project_dir%/vendor/coolms/core-doctrine/src/mapping',
'prefix'    => 'CoolMS\\Core',
```

> **The driver owns that whole namespace.** A new entity added under
> `CoolMS\Core` without a matching `.orm.xml` is simply not mapped, and nothing
> reports it — the class just never becomes an entity. Add the mapping file at
> the same time as the class. The file name is the class name minus the prefix,
> dots for separators: `CoolMS\Core\Outbox\OutboxRecord` →
> `Outbox.OutboxRecord.orm.xml`.

## The RQL-aware base repository

`DoctrineRepository` gives you `findByRql()`, plus `save()`/`delete()` honouring
the platform's deferred-flush transaction mode. It is typed against
`coolms/core`'s `RepositoryInterface`, so consumers depend on the contract.

## Writing another adapter

Provide the virtual package, bind the same contracts, ship your own mapping:

```json
"provide": { "coolms/core-persistence-implementation": "1.0" }
```

Swapping is then one line in `config/bundles.php` plus the Composer require.

## License

MIT. See [LICENSE](LICENSE).
