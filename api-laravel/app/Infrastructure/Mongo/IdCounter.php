<?php

namespace App\Infrastructure\Mongo;

use MongoDB\BSON\Int64;
use MongoDB\Database;
use MongoDB\Operation\FindOneAndUpdate;
use OverflowException;

final class IdCounter
{
    public function __construct(private readonly Database $database) {}

    public function next(string $collection): string
    {
        $row = $this->database->selectCollection('id_counters')->findOneAndUpdate(
            ['_id' => $collection, 'value' => ['$lt' => new Int64(Codec::MAX_ID)]],
            ['$inc' => ['value' => new Int64('1')]],
            ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER],
        );
        if (!$row) {
            throw new OverflowException('Counter absent or exhausted');
        }
        return Codec::id((string) $row['value']);
    }
}
