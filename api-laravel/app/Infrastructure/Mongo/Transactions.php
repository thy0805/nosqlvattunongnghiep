<?php

namespace App\Infrastructure\Mongo;

use Closure;
use DateTimeImmutable;
use LogicException;
use MongoDB\Driver\Exception\RuntimeException as DriverException;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\WriteConcern;
use RuntimeException;
use Throwable;
use WeakMap;

final class Transactions
{
    private static ?WeakMap $active = null;

    public function __construct(private readonly Runtime $runtime) {}

    public function run(Closure $callback): mixed
    {
        self::$active ??= new WeakMap;
        if (self::$active[$this->runtime] ?? false) {
            throw new LogicException('Nested transactions are forbidden');
        }
        $session = $this->runtime->client->startSession();
        self::$active[$this->runtime] = true;
        $deadline = hrtime(true) + 30_000_000_000;
        $clock = new DateTimeImmutable('now');
        $clock = $clock->setTime((int) $clock->format('H'), (int) $clock->format('i'), (int) $clock->format('s'), intdiv((int) $clock->format('u'), 1000) * 1000);
        try {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $session->startTransaction(['readConcern' => new ReadConcern('snapshot'), 'readPreference' => new ReadPreference('primary'), 'writeConcern' => new WriteConcern('majority', 10000, true), 'maxCommitTimeMS' => 10000]);
                $unit = new UnitOfWork($this->runtime->database, $session, $clock, $attempt);
                try {
                    $unit->guard('domain:v1');
                    $unit->guard('references:v1');
                    $result = $callback($unit);
                    $unit->validateFinalState();
                    if (hrtime(true) >= $deadline) {
                        throw new RuntimeException('Transaction operation deadline');
                    }
                    while (true) {
                        try {
                            $session->commitTransaction();
                            return $result;
                        } catch (DriverException $error) {
                            if ($error->hasErrorLabel('UnknownTransactionCommitResult')) {
                                if (hrtime(true) >= $deadline) {
                                    throw new RuntimeException('TRANSACTION_COMMIT_UNCERTAIN', 0, $error);
                                }
                                usleep(20000);
                                continue;
                            }
                            throw $error;
                        }
                    }
                } catch (Throwable $error) {
                    if ($session->isInTransaction()) {
                        try { $session->abortTransaction(); } catch (Throwable) {}
                    }
                    if ($error instanceof DriverException && $error->hasErrorLabel('TransientTransactionError') && $attempt < 5 && hrtime(true) < $deadline) {
                        usleep(20000 * $attempt);
                        continue;
                    }
                    throw $error;
                } finally {
                    $unit->close();
                }
            }
            throw new RuntimeException('Transaction retry deadline');
        } finally {
            $session->endSession();
            self::$active[$this->runtime] = false;
        }
    }
}
