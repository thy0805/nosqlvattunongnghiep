<?php

namespace App\Infrastructure\Mongo;

use MongoDB\BSON\Int64;
use MongoDB\Database;
use RuntimeException;

final class Schema
{
    public static function path(): string
    {
        return dirname(__DIR__, 3).'/database/mongodb/manifest.json';
    }

    public static function hash(): string
    {
        return hash_file('sha256', self::path());
    }

    public static function manifest(): array
    {
        $manifest = \MongoDB\BSON\Document::fromJSON(file_get_contents(self::path()))->toPHP(['root' => 'array', 'document' => 'array', 'array' => 'array']);
        $manifest['collections']['invoices']['validator']['$and'][0]['$jsonSchema']['properties']['shipping_amount'] = ['bsonType'=>'decimal'];
        $manifest['collections'] += \App\Commerce\CommerceSchema::specs();
        return $manifest;
    }

    public static function apply(Database $database): array
    {
        $manifest = self::manifest();
        $existing = iterator_to_array($database->listCollectionNames());
        foreach ($manifest['collections'] as $name => $spec) {
            $options = array_intersect_key($spec, array_flip(['validator', 'validationLevel', 'validationAction']));
            if (in_array($name, $existing, true)) {
                $database->command(['collMod' => $name] + $options);
            } else {
                $database->createCollection($name, $options);
            }
        }
        foreach ($manifest['collections'] as $name => $spec) {
            $collection = $database->selectCollection($name);
            foreach ($spec['indexes'] as $index) {
                $key = $index['key'];
                unset($index['key']);
                $collection->createIndex($key, $index);
            }
            if (in_array($spec['category'], ['domain', 'auth_compatibility'], true) || in_array($name, ['product_contents', 'audit_logs'], true)) {
                $database->selectCollection('id_counters')->updateOne(['_id' => $name], ['$setOnInsert' => ['value' => new Int64('0')]], ['upsert' => true]);
            }
        }
        foreach (['domain:v1', 'references:v1'] as $key) {
            $database->selectCollection('coordination_guards')->updateOne(['_id' => $key], ['$setOnInsert' => ['version' => new Int64('0')]], ['upsert' => true]);
        }
        $database->selectCollection('coordination_guards')->updateOne(['_id' => 'schema:v1'], ['$set' => ['version' => new Int64('0'), 'schema_version' => 1, 'manifest_sha256' => self::hash()]], ['upsert' => true]);
        return self::readback($database);
    }

    public static function readback(Database $database): array
    {
        $result = [];
        foreach ($database->listCollections() as $collection) {
            $name = $collection->getName();
            $indexes = [];
            foreach ($database->selectCollection($name)->listIndexes() as $index) {
                $indexes[] = $index->__debugInfo();
            }
            $result[$name] = ['options' => $collection->getOptions(), 'indexes' => $indexes];
        }
        ksort($result);
        if (array_keys($result) !== (function () {
            $keys = array_keys(self::manifest()['collections']);
            sort($keys);
            return $keys;
        })()) {
            throw new RuntimeException('Unexpected collection set');
        }
        return $result;
    }
}
