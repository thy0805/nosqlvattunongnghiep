<?php

namespace App\Support;

class OutboxService
{
    public function record(string $aggregateType, string $aggregateId, string $eventType, array $payload): void
    {
        $store = app(PurchaseStore::class);
        $store->unit();
        if (!in_array([$aggregateType,$eventType],[['inventory_alert_incident','inventory.alert_opened'],['inventory_alert_incident','inventory.alert_resolved'],['purchase_receipt','purchase.approved'],['sales_order','sale.confirmed'],['payment','debt.payment_recorded'],['stock_transfer','transfer.requested'],['stock_transfer','transfer.approved'],['stock_transfer','transfer.rejected'],['stock_transfer','transfer.dispatched'],['stock_transfer','transfer.received'],['stock_transfer','transfer.reconciled']],true)) throw new \LogicException('OUTBOX_EVENT_NOT_PORTED');
        $store->insert('outbox_events', ['aggregate_type' => $aggregateType, 'aggregate_id' => $aggregateId, 'event_type' => $eventType, 'payload' => $payload]);
    }
}
