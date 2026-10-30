<?php

namespace App\Infrastructure\Mongo;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MongoDB\BSON\Decimal128;
use MongoDB\BSON\Int64;
use MongoDB\BSON\UTCDateTime;

final class Codec
{
    public const MAX_ID = '9223372036854775807';

    public static function id(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,18}$/D', $value)
            || (strlen($value) === 19 && strcmp($value, self::MAX_ID) > 0)) {
            throw new InvalidArgumentException('Invalid canonical BIGINT ID');
        }
        return $value;
    }

    public static function identity(string $id): array
    {
        return ['_id' => self::id($id), 'id_sort' => new Int64($id), '_version' => new Int64('0')];
    }

    public static function decimal(mixed $value, int $precision = 18, int $scale = 2): Decimal128
    {
        if (!is_string($value) || !preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value)
            || $precision < 1 || $precision > 34 || $scale < 0 || $scale > $precision) {
            throw new InvalidArgumentException('Invalid decimal input');
        }
        $decimal = BigDecimal::of($value)->toScale($scale, RoundingMode::Unnecessary);
        if ($decimal->abs()->isGreaterThanOrEqualTo(BigDecimal::of('1'.str_repeat('0', $precision - $scale)))) {
            throw new InvalidArgumentException('Decimal overflow');
        }
        return new Decimal128((string) $decimal);
    }

    public static function fixed(Decimal128 $value, int $scale): string
    {
        return (string) BigDecimal::of((string) $value)->toScale($scale, RoundingMode::Unnecessary);
    }

    public static function calendar(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
            throw new InvalidArgumentException('Invalid calendar date');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Ho_Chi_Minh'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid calendar date');
        }
        return $value;
    }

    public static function instant(DateTimeImmutable $value): UTCDateTime
    {
        if (((int) $value->format('u')) % 1000 !== 0) {
            throw new InvalidArgumentException('Sub-millisecond timestamp requires explicit policy');
        }
        return new UTCDateTime($value);
    }

    public static function present(array $document, array $decimalScales = []): array
    {
        if (isset($document['_id'])) {
            $document['id'] = (string) $document['_id'];
        }
        unset($document['_id'], $document['id_sort'], $document['_version'], $document['lease_token'], $document['lease_until']);
        foreach ($decimalScales as $field => $scale) {
            if (($document[$field] ?? null) instanceof Decimal128) {
                $document[$field] = self::fixed($document[$field], $scale);
            }
        }
        return $document;
    }
}
