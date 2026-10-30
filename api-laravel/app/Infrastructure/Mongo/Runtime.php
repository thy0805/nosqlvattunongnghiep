<?php

namespace App\Infrastructure\Mongo;

use MongoDB\Client;
use MongoDB\Database;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use RuntimeException;

final class Runtime
{
    public readonly Client $client;
    public readonly Database $database;

    public function __construct(array $settings, ?CommandSubscriber $subscriber = null)
    {
        Isolation::validate($settings);
        $this->client = new Client($settings['uri'], ['appName' => 'nosql186-c00', 'serverSelectionTimeoutMS' => 2500, 'connectTimeoutMS' => 2500, 'socketTimeoutMS' => 10000], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
        if ($subscriber) {
            $this->client->getManager()->addSubscriber($subscriber);
        }
        $this->database = $this->client->selectDatabase($settings['database']);
        $hello = $this->database->command(['hello' => 1])->toArray()[0];
        if (($hello['setName'] ?? '') !== 'nosql186_rs' || empty($hello['isWritablePrimary'])
            || ($hello['hosts'] ?? []) !== ['localhost:27028'] || ($hello['me'] ?? '') !== 'localhost:27028') {
            throw new RuntimeException('NOSQL_TOPOLOGY_REJECTED');
        }
    }

    public function assertSchema(): void
    {
        $guard = $this->database->selectCollection('coordination_guards')->findOne(['_id' => 'schema:v1']);
        if (!$guard || ($guard['manifest_sha256'] ?? '') !== Schema::hash() || ($guard['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('NOSQL_SCHEMA_REJECTED');
        }
    }
}
