<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Type;

use CoolMS\Core\Doctrine\Type\TimeRangeType;
use CoolMS\Core\ValueObject\TimeOfDay;
use CoolMS\Core\ValueObject\TimeRange;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CoolMS\Core\Doctrine\Type\TimeRangeType
 */
final class TimeRangeTypeTest extends TestCase
{
    private TimeRangeType $type;
    private AbstractPlatform $platform;

    #[Test]
    public function convertToDatabaseValueReturnsNullForNull(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
    }

    #[Test]
    public function convertToDatabaseValueEmitsJsonObject(): void
    {
        $range = new TimeRange(new TimeOfDay(9, 0), new TimeOfDay(17, 0));
        $db = $this->type->convertToDatabaseValue($range, $this->platform);
        self::assertSame('{"start":"09:00","end":"17:00"}', $db);
    }

    #[Test]
    public function convertToDatabaseValueRejectsNonTimeRange(): void
    {
        $this->expectException(InvalidType::class);
        $this->type->convertToDatabaseValue('not a range', $this->platform);
    }

    #[Test]
    public function convertToPhpValueParsesJsonObject(): void
    {
        $r = $this->type->convertToPHPValue('{"start":"09:00","end":"17:00"}', $this->platform);
        self::assertInstanceOf(TimeRange::class, $r);
        self::assertSame(9, $r->start->hour);
        self::assertSame(17, $r->end->hour);
    }

    #[Test]
    public function convertToPhpValueWrapsBadShape(): void
    {
        $this->expectException(ValueNotConvertible::class);
        $this->type->convertToPHPValue('"flat string"', $this->platform);
    }

    #[Test]
    public function convertToPhpValueWrapsInverted(): void
    {
        $this->expectException(ValueNotConvertible::class);
        $this->type->convertToPHPValue('{"start":"17:00","end":"09:00"}', $this->platform);
    }

    #[Test]
    public function roundTripPreservesContent(): void
    {
        $original = new TimeRange(new TimeOfDay(9, 30), new TimeOfDay(17, 0));
        $db = $this->type->convertToDatabaseValue($original, $this->platform);
        self::assertIsString($db);
        $restored = $this->type->convertToPHPValue($db, $this->platform);
        self::assertInstanceOf(TimeRange::class, $restored);
        self::assertEquals($original->toArray(), $restored->toArray());
    }

    protected function setUp(): void
    {
        $this->type = new TimeRangeType();
        $this->platform = new PostgreSQLPlatform();
    }
}
