<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Type;

use CoolMS\Core\Doctrine\Type\DateTimeRangeType;
use CoolMS\Core\ValueObject\DateTimeRange;
use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \CoolMS\Core\Doctrine\Type\DateTimeRangeType
 */
final class DateTimeRangeTypeTest extends TestCase
{
    private DateTimeRangeType $type;
    private AbstractPlatform $platform;

    #[Test]
    public function convertToDatabaseValueReturnsNullForNull(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
    }

    #[Test]
    public function convertToDatabaseValueEmitsJsonObjectWithIso(): void
    {
        $range = new DateTimeRange(
            new DateTimeImmutable('2026-05-01T09:00:00+00:00'),
            new DateTimeImmutable('2026-05-01T17:00:00+00:00'),
        );

        $db = $this->type->convertToDatabaseValue($range, $this->platform);

        self::assertIsString($db);
        self::assertStringContainsString('"start":"2026-05-01T09:00:00+00:00"', $db);
        self::assertStringContainsString('"includesEnd":true', $db);
    }

    #[Test]
    public function convertToDatabaseValueRejectsArbitraryObject(): void
    {
        $this->expectException(InvalidType::class);
        $this->type->convertToDatabaseValue(new stdClass(), $this->platform);
    }

    #[Test]
    public function convertToPhpValueParsesJsonObject(): void
    {
        $json = '{"start":"2026-05-01T09:00:00+00:00","end":"2026-05-01T17:00:00+00:00","includesEnd":true}';
        $range = $this->type->convertToPHPValue($json, $this->platform);

        self::assertInstanceOf(DateTimeRange::class, $range);
        self::assertSame(
            new DateTimeImmutable('2026-05-01T09:00:00+00:00')->getTimestamp(),
            $range->start->getTimestamp(),
        );
        self::assertSame(
            new DateTimeImmutable('2026-05-01T17:00:00+00:00')->getTimestamp(),
            $range->end->getTimestamp(),
        );
        self::assertSame(8 * 3600, $range->durationInSeconds());
    }

    #[Test]
    public function convertToPhpValueReturnsNullForNull(): void
    {
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    #[Test]
    public function convertToPhpValuePassThroughDateTimeRange(): void
    {
        $range = new DateTimeRange(
            new DateTimeImmutable('2026-05-01T09:00:00+00:00'),
            new DateTimeImmutable('2026-05-01T17:00:00+00:00'),
        );
        self::assertSame($range, $this->type->convertToPHPValue($range, $this->platform));
    }

    #[Test]
    public function convertToPhpValueWrapsBadJson(): void
    {
        $this->expectException(ValueNotConvertible::class);
        $this->type->convertToPHPValue('not json', $this->platform);
    }

    #[Test]
    public function convertToPhpValueWrapsInvalidRange(): void
    {
        $this->expectException(ValueNotConvertible::class);
        $this->type->convertToPHPValue(
            '{"start":"2026-05-01T17:00:00Z","end":"2026-05-01T09:00:00Z","includesEnd":true}',
            $this->platform,
        );
    }

    #[Test]
    public function roundTripPreservesContent(): void
    {
        $original = new DateTimeRange(
            new DateTimeImmutable('2026-05-01T09:00:00+02:00'),
            new DateTimeImmutable('2026-05-01T17:00:00+02:00'),
        );

        $db = $this->type->convertToDatabaseValue($original, $this->platform);
        self::assertIsString($db);
        $restored = $this->type->convertToPHPValue($db, $this->platform);

        self::assertInstanceOf(DateTimeRange::class, $restored);
        self::assertSame(
            $original->start->getTimestamp(),
            $restored->start->getTimestamp(),
        );
    }

    protected function setUp(): void
    {
        $this->type = new DateTimeRangeType();
        $this->platform = new PostgreSQLPlatform();
    }
}
