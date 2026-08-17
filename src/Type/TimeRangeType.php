<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Type;

use CoolMS\Core\Exception\InvalidRangeException;
use CoolMS\Core\ValueObject\TimeRange;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;
use JsonException;

/**
 * Doctrine DBAL custom type that persists {@see TimeRange} as a JSON
 * literal of the wire shape `{"start":"HH:MM","end":"HH:MM"}` (or
 * `HH:MM:SS` when seconds are non-zero).
 *
 * Registered under the name `time_range` -- consumed via
 * `#[Column(type: 'time_range')]`.
 *
 * The existing `WorkingHoursType` (`coolms_calendar_calendars.working_hours`)
 * keeps its own format for now; the Working Hours editor refactor onto
 * this generic VO is deferred.
 */
final class TimeRangeType extends Type
{
    public const string NAME = 'time_range';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getJsonTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?TimeRange
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof TimeRange) {
            return $value;
        }

        if (!is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', TimeRange::class]);
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
            return TimeRange::fromArray($decoded);
        } catch (InvalidRangeException $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof TimeRange) {
            throw InvalidType::new($value, self::NAME, ['null', TimeRange::class]);
        }

        try {
            return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }
}
