<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Type;

use CoolMS\Core\Exception\InvalidRangeException;
use CoolMS\Core\ValueObject\DateRange;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;
use JsonException;

/**
 * Doctrine DBAL custom type that persists {@see DateRange} as a JSON
 * literal of the wire shape `{"start":"YYYY-MM-DD","end":"YYYY-MM-DD","includesEnd":bool}`.
 *
 * Registered under the name `date_range` -- consumed via
 * `#[Column(type: 'date_range')]` on any entity that owns a calendar-date
 * range (LCAP/BPM consumers: workflow validity windows, scheduler
 * activation windows, contract terms, report period selectors).
 *
 * Same self-registration pattern as `WorkingHoursType`: the type is
 * registered through Core's Extension::prepend()
 * so any module can use it without a host `doctrine.yaml` edit.
 *
 * Storage: portable JSON via `getJsonTypeDeclarationSQL()` -- Postgres
 * `JSON`, MySQL `JSON`, SQLite `CLOB`.
 */
final class DateRangeType extends Type
{
    public const string NAME = 'date_range';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getJsonTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateRange
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof DateRange) {
            return $value;
        }

        if (!is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', DateRange::class]);
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ValueNotConvertible::new($value, self::NAME, 'Invalid JSON: ' . $e->getMessage(), $e);
        }

        if (!is_array($decoded)) {
            throw ValueNotConvertible::new($value, self::NAME, 'Expected JSON object.');
        }

        try {
            /* @var array<string, mixed> $decoded */
            return DateRange::fromArray($decoded);
        } catch (InvalidRangeException $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof DateRange) {
            throw InvalidType::new($value, self::NAME, ['null', DateRange::class]);
        }

        try {
            return $value->toArray()
                    |> (fn (array $arr): string => json_encode($arr, JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }
}
