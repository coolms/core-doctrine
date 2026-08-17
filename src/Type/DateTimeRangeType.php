<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Type;

use CoolMS\Core\Exception\InvalidRangeException;
use CoolMS\Core\ValueObject\DateTimeRange;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\Type;
use JsonException;

/**
 * Doctrine DBAL custom type that persists {@see DateTimeRange} as a
 * JSON literal of the wire shape
 * `{"start":"ISO-8601","end":"ISO-8601","includesEnd":bool}`.
 *
 * Registered under the name `datetime_range` -- consumed via
 * `#[Column(type: 'datetime_range')]`.
 *
 * Mirror of {@see DateRangeType}; timezone-preserving (the VO does not
 * normalise to UTC, neither does the type). See
 * {@see DateTimeRange::fromArray()} for the round-trip contract.
 */
final class DateTimeRangeType extends Type
{
    public const string NAME = 'datetime_range';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getJsonTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DateTimeRange
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof DateTimeRange) {
            return $value;
        }

        if (!is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string', DateTimeRange::class]);
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
            return DateTimeRange::fromArray($decoded);
        } catch (InvalidRangeException $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof DateTimeRange) {
            throw InvalidType::new($value, self::NAME, ['null', DateTimeRange::class]);
        }

        try {
            return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ValueNotConvertible::new($value, self::NAME, $e->getMessage(), $e);
        }
    }
}
