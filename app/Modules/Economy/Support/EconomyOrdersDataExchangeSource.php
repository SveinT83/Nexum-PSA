<?php

namespace App\Modules\Economy\Support;

use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Support\DataExchangeFieldDefinition;
use App\Modules\DataExchange\Support\DataExchangeSourceDefinition;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Models\EconomyOrderLine;

class EconomyOrdersDataExchangeSource
{
    public function definition(): DataExchangeSourceDefinition
    {
        return new DataExchangeSourceDefinition(
            key: 'economy.orders',
            label: 'Economy Orders',
            module: 'Economy',
            modelClass: EconomyOrder::class,
            permission: 'economy.view',
            fields: $this->fields(),
            relations: [
                ['key' => 'client', 'label' => 'Client', 'cardinality' => 'one'],
                ['key' => 'lines', 'label' => 'Order lines', 'cardinality' => 'many'],
                ['key' => 'lines.ticket', 'label' => 'Ticket', 'cardinality' => 'one'],
            ],
            filters: [
                'order.status',
                'order.period_start',
                'order.period_end',
                'client.client_number',
                'client.name',
            ],
            exporter: fn (DataExchangeProfile $profile, array $options = []): array => $this->exportRows($profile, $options),
        );
    }

    /**
     * @return array<int, DataExchangeFieldDefinition>
     */
    private function fields(): array
    {
        return [
            new DataExchangeFieldDefinition('order.id', 'Order ID', 'integer'),
            new DataExchangeFieldDefinition('order.order_number', 'Order number'),
            new DataExchangeFieldDefinition('order.period_start', 'Period start', 'date'),
            new DataExchangeFieldDefinition('order.period_end', 'Period end', 'date'),
            new DataExchangeFieldDefinition('order.status', 'Order status'),
            new DataExchangeFieldDefinition('order.generated_at', 'Generated at', 'datetime'),
            new DataExchangeFieldDefinition('order.ready_at', 'Ready at', 'datetime'),
            new DataExchangeFieldDefinition('order.subtotal_ex_vat', 'Order subtotal ex. VAT', 'decimal'),
            new DataExchangeFieldDefinition('order.vat_amount', 'Order VAT amount', 'decimal'),
            new DataExchangeFieldDefinition('order.total_inc_vat', 'Order total incl. VAT', 'decimal'),
            new DataExchangeFieldDefinition('client.id', 'Client ID', 'integer', relation: 'client'),
            new DataExchangeFieldDefinition('client.client_number', 'Client number', relation: 'client'),
            new DataExchangeFieldDefinition('client.name', 'Client name', relation: 'client'),
            new DataExchangeFieldDefinition('client.org_no', 'Client org number', relation: 'client'),
            new DataExchangeFieldDefinition('client.billing_email', 'Client billing email', relation: 'client'),
            new DataExchangeFieldDefinition('line.id', 'Line ID', 'integer', relation: 'lines'),
            new DataExchangeFieldDefinition('line.work_date', 'Line work date', 'date', relation: 'lines'),
            new DataExchangeFieldDefinition('line.line_type', 'Line type', relation: 'lines'),
            new DataExchangeFieldDefinition('line.description', 'Line description', relation: 'lines'),
            new DataExchangeFieldDefinition('line.quantity', 'Line quantity', 'decimal', relation: 'lines'),
            new DataExchangeFieldDefinition('line.unit', 'Line unit', relation: 'lines'),
            new DataExchangeFieldDefinition('line.unit_price_ex_vat', 'Line unit price ex. VAT', 'decimal', relation: 'lines'),
            new DataExchangeFieldDefinition('line.line_total_ex_vat', 'Line total ex. VAT', 'decimal', relation: 'lines'),
            new DataExchangeFieldDefinition('line.vat_rate', 'Line VAT rate', 'decimal', relation: 'lines'),
            new DataExchangeFieldDefinition('line.vat_amount', 'Line VAT amount', 'decimal', relation: 'lines'),
            new DataExchangeFieldDefinition('line.total_inc_vat', 'Line total incl. VAT', 'decimal', relation: 'lines'),
            new DataExchangeFieldDefinition('line.currency', 'Line currency', relation: 'lines'),
            new DataExchangeFieldDefinition('ticket.ticket_key', 'Ticket key', relation: 'lines.ticket'),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportRows(DataExchangeProfile $profile, array $options): array
    {
        $statuses = (array) ($options['order_statuses'] ?? data_get($profile->settings, 'default_order_statuses', []));
        $query = EconomyOrder::query()
            ->with(['client', 'lines.ticket'])
            ->orderBy('id');

        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        return $query->get()
            ->flatMap(function (EconomyOrder $order): array {
                if ($order->lines->isEmpty()) {
                    return [$this->row($order, null)];
                }

                return $order->lines
                    ->map(fn (EconomyOrderLine $line): array => $this->row($order, $line))
                    ->all();
            })
            ->values()
            ->all();
    }

    private function row(EconomyOrder $order, ?EconomyOrderLine $line): array
    {
        return [
            'order.id' => $order->id,
            'order.order_number' => $order->order_number,
            'order.period_start' => $order->period_start?->toDateString(),
            'order.period_end' => $order->period_end?->toDateString(),
            'order.status' => $order->status,
            'order.generated_at' => $order->generated_at?->toDateTimeString(),
            'order.ready_at' => $order->ready_at?->toDateTimeString(),
            'order.subtotal_ex_vat' => $order->subtotal_ex_vat,
            'order.vat_amount' => $order->vat_amount,
            'order.total_inc_vat' => $order->total_inc_vat,
            'client.id' => $order->client?->id,
            'client.client_number' => $order->client?->client_number,
            'client.name' => $order->client?->name,
            'client.org_no' => $order->client?->org_no,
            'client.billing_email' => $order->client?->billing_email,
            'line.id' => $line?->id,
            'line.work_date' => $line?->work_date?->toDateString(),
            'line.line_type' => $line?->line_type,
            'line.description' => $line?->description,
            'line.quantity' => $line?->quantity,
            'line.unit' => $line?->unit,
            'line.unit_price_ex_vat' => $line?->unit_price_ex_vat,
            'line.line_total_ex_vat' => $line?->line_total_ex_vat,
            'line.vat_rate' => $line?->vat_rate,
            'line.vat_amount' => $line?->vat_amount,
            'line.total_inc_vat' => $line?->total_inc_vat,
            'line.currency' => $line?->currency,
            'ticket.ticket_key' => $line?->ticket?->ticket_key,
        ];
    }
}
