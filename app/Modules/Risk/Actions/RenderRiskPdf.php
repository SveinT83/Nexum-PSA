<?php

namespace App\Modules\Risk\Actions;

use App\Models\Risk\RiskAssessment;
use App\Models\Risk\RiskItem;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;

/** Shared browser/API PDF with the existing Risk template and summary. */
final class RenderRiskPdf
{
    public function handle(RiskAssessment $risk): Response
    {
        // All report relationships are materialized in one repeatable-read snapshot.
        $risk = \Illuminate\Support\Facades\DB::transaction(fn () => RiskAssessment::query()->with(['items.category', 'items.updates.creator', 'client', 'workContext', 'approver'])->findOrFail($risk->id));

        $groupedItems = $risk->items
            ->groupBy(fn (RiskItem $item) => $item->category?->name ?? 'Uncategorized')
            ->sortKeys();

        $summary = $this->summaryFor($risk);

        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('chroot', public_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('risk::Tech.pdf', compact('risk', 'groupedItems', 'summary'))->render());
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'Risikoanalyse_'.str_replace(' ', '_', $risk->title).'_'.now()->format('Y-m-d').'.pdf';

        return response($dompdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$filename.'"');

    }

    private function summaryFor(RiskAssessment $risk): array
    {
        $avgScore = $risk->items->avg('score') ?? 0;

        return [
            'total' => $risk->items->count(),
            'mitigated' => $risk->items->whereIn('status', ['mitigated', 'accepted'])->count(),
            'open' => $risk->items->where('status', 'open')->count(),
            'critical_areas' => $risk->items->where('score', '>=', 16)->pluck('category.name')->unique()->filter()->values()->all(),
            'level' => match (true) {
                $avgScore >= 16 => 'Critical',
                $avgScore >= 10 => 'High',
                $avgScore >= 5 => 'Medium',
                default => 'Low',
            },
        ];
    }
}
