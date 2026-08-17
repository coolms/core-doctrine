<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Type;

use CoolMS\Core\Doctrine\Type\DateRangeType;
use CoolMS\Core\ValueObject\DateRange;
use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \CoolMS\Core\Doctrine\Type\DateRangeType
 */
final class DateRangeTypeTest extends TestCase
{
    private DateRangeType $type;
    private AbstractPlatform $platform;

    #[Test]
    public function convertToDatabaseValueReturnsNullForNull(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
    }

    #[Test]
    public function convertToDatabaseValueEmitsJsonObject(): void
    {
        $range = new DateRange(
            new DateTimeImmutable('2026-05-01'),
            new DateTimeImmutable('2026-05-31'),
        );

        $db = $this->type->convertToDatabaseValue($range, $this->platform);

        self::assertIsString($db);
        self::assertSame(
            '{"start":"2026-05-01","end":"2026-05-31","includesEnd":true}',
            $db,
        );
    }

    #[Test]
    public function convertToDatabaseValueRejectsArbitraryObject(): void
    {
        $this->expectException(InvalidType::class);
        $this->type->convertToDatabaseValue(new stdClass(), $this->platform);
    }

    #[Test]
    public function convertToPhpValueReturnsNullForNull(): void
    {
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    #[Test]
    public function convertToPhpValueParsesJsonObject(): void
    {
        $json = '{"start":"2026-05-01","end":"2026-05-31","includesEnd":false}';
        $range = $this->type->convertToPHPValue($json, $this->platform);

        self::assertInstanceOf(DateRange::class, $range);
        self::assertSame('2026-05-01', $range->start->format('Y-m-d'));
        self::assertSame('2026-05-31', $range->end->format('Y-m-d'));
        self::assertFalse($range->includesEnd);
    }

    #[Test]
    public function convertToPhpValuePassThroughDateRange(): void
    {
        $range = new DateRange(
            new DateTimeImmutable('2026-05-01'),
            new DateTimeImmutable('2026-05-31'),
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
    public function convertToPhpValueWrapsBadShape(): void
    {
        $this->expectException(ValueNotConvertible::class);
        $this->type->convertToPHPValue('"plain string"', $this->platform);
    }

    #[Test]
    public function convertToPhpValueWrapsInvalidRange(): void
    {
        // end < start should propagate as ValueNotConvertible.
        $this->expectException(ValueNotConvertible::class);
        $this->type->convertToPHPValue(
            '{"start":"2026-05-31","end":"2026-05-01","includesEnd":true}',
            $this->platform,
        );
    }

    #[Test]
    public function convertToPhpValueRejectsNonStringDbValue(): void
    {
        $this->expectException(InvalidType::class);
        $this->type->convertToPHPValue(42, $this->platform);
    }

    #[Test]
    public function roundTripPreservesContent(): void
    {
        $original = new DateRange(
            new DateTimeImmutable('2026-05-01'),
            new DateTimeImmutable('2026-05-31'),
            includesEnd: false,
        );

        $db = $this->type->convertToDatabaseValue($original, $this->platform);
        self::assertIsString($db);
        $restored = $this->type->convertToPHPValue($db, $this->platform);

        self::assertInstanceOf(DateRange::class, $restored);
        self::assertEquals($original->toArray(), $restored->toArray());
    }

    #[Test]
    public function getSqlDeclarationDelegatesToPlatformJsonType(): void
    {
        $sql = $this->type->getSQLDeclaration(['length' => null], $this->platform);
        self::assertSame('JSON', $sql);
    }

    protected function setUp(): void
    {
        $this->type = new DateRangeType();
        $this->platform = new PostgreSQLPlatform();
    }
}
