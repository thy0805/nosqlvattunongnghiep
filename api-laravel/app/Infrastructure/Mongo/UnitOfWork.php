<?php

namespace App\Infrastructure\Mongo;

use Closure;
use DateTimeImmutable;
use LogicException;
use MongoDB\BSON\Int64;
use MongoDB\Database;
use MongoDB\Driver\Session;
use RuntimeException;

final class UnitOfWork
{
    private array $deferred = [];
    private bool $active = true;

    public function __construct(public readonly Database $database, public readonly Session $session, public readonly DateTimeImmutable $clock, public readonly int $attempt) {}

    public function options(): array
    {
        if (!$this->active || !$this->session->isInTransaction()) {
            throw new LogicException('Active UnitOfWork required');
        }
        return ['session' => $this->session];
    }

    public function guard(string $key): void
    {
        $result = $this->database->selectCollection('coordination_guards')->updateOne(['_id' => $key], ['$inc' => ['version' => new Int64('1')]], $this->options());
        if ($result->getMatchedCount() !== 1) {
            throw new RuntimeException('Guard is not initialized');
        }
    }

    public function insert(string $collection, array $document): void
    {
        $this->database->selectCollection($collection)->insertOne($document, $this->options());
    }

    public function cas(string $collection, string $id, Int64|int $version, array $set, array $predicate = []): void
    {
        if (array_intersect(['_id', 'id_sort', '_version'], array_keys($set))) {
            throw new LogicException('Identity/version are controlled internally');
        }
        $result = $this->database->selectCollection($collection)->updateOne(['_id' => Codec::id($id), '_version' => $version] + $predicate, ['$set' => $set, '$inc' => ['_version' => new Int64('1')]], $this->options());
        if ($result->getMatchedCount() !== 1) {
            throw new RuntimeException('CAS_CONFLICT');
        }
    }

    public function defer(Closure $check): void
    {
        $this->options();
        $this->deferred[] = $check;
    }

    public function validateFinalState(): void
    {
        foreach ($this->deferred as $check) {
            $check($this);
        }
    }

    public function close(): void
    {
        $this->active = false;
    }
}
