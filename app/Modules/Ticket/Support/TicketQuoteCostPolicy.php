<?php

namespace App\Modules\Ticket\Support;

use App\Models\Settings\CommonSetting;
use App\Modules\Storage\Models\Item;
use Illuminate\Support\Arr;

class TicketQuoteCostPolicy
{
    public const SETTING_TYPE = 'ticket';
    public const SETTING_NAME = 'quote_cost_policy';

    public function settings(): array
    {
        $json = CommonSetting::query()
            ->where('type', self::SETTING_TYPE)
            ->where('name', self::SETTING_NAME)
            ->value('json');

        $payload = json_decode((string) $json, true);
        $threshold = Arr::get(is_array($payload) ? $payload : [], 'quote_required_cost_threshold');

        return [
            'quote_required_cost_threshold' => is_numeric($threshold) && (float) $threshold > 0
                ? round((float) $threshold, 2)
                : null,
        ];
    }

    public function update(array $settings): void
    {
        $threshold = $settings['quote_required_cost_threshold'] ?? null;

        CommonSetting::updateOrCreate(
            ['type' => self::SETTING_TYPE, 'name' => self::SETTING_NAME],
            [
                'description' => 'Ticket quote-required cost routing policy.',
                'json' => json_encode([
                    'quote_required_cost_threshold' => is_numeric($threshold) && (float) $threshold > 0
                        ? round((float) $threshold, 2)
                        : null,
                ]),
            ]
        );
    }

    public function quoteRequiredReasonForStorageItem(Item $item, int $quantity): ?string
    {
        if ($item->requires_customer_quote) {
            return 'Storage item requires customer quote approval.';
        }

        return $this->quoteRequiredReasonForTotal((float) $item->sale_price * $quantity);
    }

    public function quoteRequiredReasonForManualLine(int $quantity, float $unitPriceExVat): ?string
    {
        return $this->quoteRequiredReasonForTotal($unitPriceExVat * $quantity);
    }

    private function quoteRequiredReasonForTotal(float $totalExVat): ?string
    {
        $threshold = $this->settings()['quote_required_cost_threshold'];

        if ($threshold !== null && $totalExVat >= $threshold) {
            return 'Line total requires customer quote approval.';
        }

        return null;
    }
}
