<?php

namespace App\Support;

use MongoDB\BSON\UTCDateTime;

class NotificationStore
{
    public function __construct(private readonly IdentityStore $identity) {}

    public function list(string $userId, array $branchIds, ?string $status): array
    {
        $scope = ['user_id' => $userId, 'branch_id' => ['$in' => $branchIds]];
        $filter = $scope;
        if ($status !== null) $filter['status'] = $status;
        $collection = $this->collection();
        return ['data' => array_map(fn ($row) => $this->present($row), $collection->find($filter, ['sort' => ['created_at' => -1], 'limit' => 50])->toArray()), 'unread_count' => $collection->countDocuments($scope + ['status' => 'unread'])];
    }

    public function markRead(string $id, string $userId, array $branchIds): ?array
    {
        return $this->identity->transaction(function () use ($id, $userId, $branchIds): ?array {
            abort_unless($this->identity->find('users', $userId)['is_active'] ?? false, 403);
            $assignments = $this->identity->assignments($userId, true);
            $direct = $assignments->whereIn('role_code', ['branch_manager', 'sales_staff'])->pluck('branch_id')->all();
            $chains = $assignments->where('role_code', 'chain_owner')->pluck('chain_id')->all();
            $current = $this->identity->rows('branches', ['$or' => [['_id' => ['$in' => array_values($direct)]], ['chain_id' => ['$in' => array_values($chains)]]]])->pluck('_id')->all();
            abort_if($current === [], 403);
            $filter = ['_id' => $id, 'user_id' => $userId, 'branch_id' => ['$in' => array_values(array_intersect($branchIds, $current))]];
            $options = $this->identity->unitOfWork()->options();
            $collection = $this->collection();
            $collection->updateOne($filter + ['status' => 'unread'], ['$set' => ['status' => 'read', 'read_at' => new UTCDateTime($this->identity->unitOfWork()->clock)]], $options);
            $row = $collection->findOne($filter, $options);
            return $row === null ? null : $this->present($row);
        });
    }

    private function present(array $row): array
    {
        return ['id' => $row['_id'], 'event_id' => $row['event_id'], 'incident_id' => $row['incident_id'], 'branch_id' => $row['branch_id'], 'kind' => $row['kind'], 'status' => $row['status'], 'message' => $row['message'], 'created_at' => $row['created_at']->toDateTime()->format('c'), 'read_at' => isset($row['read_at']) ? $row['read_at']->toDateTime()->format('c') : null, 'resolved_at' => isset($row['resolved_at']) ? $row['resolved_at']->toDateTime()->format('c') : null];
    }

    private function collection(): \MongoDB\Collection
    {
        return $this->identity->runtime->database->selectCollection('notifications');
    }
}
