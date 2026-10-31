<?php

namespace App\Support;

use App\Infrastructure\Mongo\Codec;
use MongoDB\BSON\UTCDateTime;

final class OutboxStore
{
    public function __construct(private readonly IdentityStore $identity) {}

    public function ownerBranches(string $userId): array
    {
        $chains = $this->identity->assignments($userId, true)->where('role_code', 'chain_owner')->pluck('chain_id')->unique()->values()->all();
        return $this->identity->rows('branches', ['chain_id' => ['$in' => $chains]])->pluck('_id')->values()->all();
    }

    private function inScope(array $event, array $branches): bool
    {
        $branch = $event['payload']['branch_id'] ?? $event['payload']['from_branch_id'] ?? null;
        return $branch !== null && in_array($branch, $branches, true);
    }

    public function listing(array $branches, string $status): array
    {
        $cursor = $this->identity->runtime->database->selectCollection('outbox_events')->find([
            'status' => $status, '$or' => [['payload.branch_id' => ['$in' => $branches]], ['payload.from_branch_id' => ['$in' => $branches]]],
        ], ['sort' => ['id_sort' => -1], 'limit' => 50]);
        $rows = [];
        foreach ($cursor as $event) {
            if (!$this->inScope($event, $branches)) continue;
            $present = Codec::present($event);
            foreach (['created_at', 'next_attempt_at', 'published_at'] as $field) {
                if (($present[$field] ?? null) instanceof UTCDateTime) {
                    $present[$field] = $present[$field]->toDateTime()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
                }
            }
            $rows[] = array_intersect_key($present, array_flip([
                'id', 'aggregate_type', 'aggregate_id', 'event_type', 'status', 'attempt_count',
                'next_attempt_at', 'last_error', 'created_at', 'published_at',
            ]));
        }
        return $rows;
    }

    public function retry(string $userId, string $id): void
    {
        $this->identity->transaction(function () use ($userId, $id): void {
            $actor = $this->identity->find('users', $userId);
            abort_unless($actor && $actor['is_active'], 403);
            $branches = $this->ownerBranches($userId);
            abort_if($branches === [], 403);
            $unit = $this->identity->unitOfWork();
            $event = $unit->database->selectCollection('outbox_events')->findOne(['_id' => $id], $unit->options());
            abort_if($event === null, 404);
            abort_unless($this->inScope($event, $branches), 403);
            abort_unless($event['status'] === 'failed', 409);
            $unit->cas('outbox_events', $id, $event['_version'], [
                'status' => 'retry', 'attempt_count' => 0, 'next_attempt_at' => new UTCDateTime($unit->clock),
                'locked_at' => null, 'lease_token' => null, 'lease_until' => null,
            ], ['status' => 'failed']);
        });
    }
}
