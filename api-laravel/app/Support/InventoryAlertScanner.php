<?php

namespace App\Support;

use MongoDB\BSON\UTCDateTime;

class InventoryAlertScanner
{
    public function __construct(private readonly InventoryAlertStore $store) {}

    public function run(OutboxService $outbox): array
    {
        if ($this->store->purchase->identity->runtime->database->getDatabaseName() !== 'nosql186_web_test_c00_20261016_f72e93') throw new \RuntimeException('NOSQL_SCANNER_TEST_ONLY');
        return $this->store->purchase->transaction(function () use ($outbox): array {
            $active = $this->store->rows('inventory_alert_incidents', ['status' => 'open'])->keyBy('incident_key');
            $data = $this->store->data();
            $current = [];
            foreach ($data['low_stock'] as $row) $current['low:'.$row['warehouse_id'].':'.$row['product_id']] = $row + ['kind' => 'low_stock', 'severity' => 'low_stock', 'lot_id' => null, 'message' => $row['product_name'].' tại '.$row['warehouse_name'].' đang dưới ngưỡng tồn tối thiểu.'];
            foreach ($data['expiring_lots'] as $row) $current['expiry:'.$row['warehouse_id'].':'.$row['lot_id']] = $row + ['kind' => 'expiry', 'severity' => $row['status'], 'message' => $row['product_name'].' lô '.$row['lot_no'].' tại '.$row['warehouse_name'].($row['status'] === 'expired' ? ' đã hết hạn.' : ' sắp hết hạn.')];
            $now = new UTCDateTime($this->store->purchase->unit()->clock);
            $created = 0; $resolved = 0; $recipients = [];
            foreach ($current as $key => $alert) {
                if (isset($active[$key])) {
                    $this->store->update('inventory_alert_incidents', $active[$key], ['last_seen_at' => $now, 'severity' => $alert['severity']]);
                    continue;
                }
                $fields = array_intersect_key($alert, array_flip(['kind', 'severity', 'branch_id', 'warehouse_id', 'product_id', 'lot_id']));
                $id = $this->store->insert('inventory_alert_incidents', $fields + ['incident_key' => $key, 'status' => 'open', 'opened_at' => $now, 'last_seen_at' => $now, 'resolved_at' => null]);
                $recipients[$alert['branch_id']] ??= $this->store->recipients($alert['branch_id'], $data['as_of_date']);
                $outbox->record('inventory_alert_incident', $id, 'inventory.alert_opened', $fields + ['message' => $alert['message'], 'recipient_user_ids' => $recipients[$alert['branch_id']]]);
                $created++;
            }
            foreach ($active as $key => $incident) {
                if (isset($current[$key])) continue;
                $this->store->update('inventory_alert_incidents', $incident, ['status' => 'resolved', 'resolved_at' => $now]);
                $outbox->record('inventory_alert_incident', $incident['_id'], 'inventory.alert_resolved', ['branch_id' => $incident['branch_id'], 'incident_id' => $incident['_id']]);
                $resolved++;
            }
            return ['open' => count($current), 'created' => $created, 'resolved' => $resolved];
        });
    }
}
