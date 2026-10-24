<?php

namespace App\Modules\Sales\Queries;

use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Support\SalesQuotePresentation;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;

/** Shared customer document renderer; reads approved snapshots without creating or rewriting evidence. */
class SalesQuotePdf
{
    public function handle(SalesQuoteVersion $version): array
    {
        $version->loadMissing(['quote.opportunity.client', 'lines.optionGroup', 'optionGroups', 'acknowledgements', 'acceptanceSnapshot']);
        $filename = $version->quote->quote_key.'-v'.$version->version_number.'.pdf';
        if ($version->pdf_snapshot_disk && $version->pdf_snapshot_path && Storage::disk($version->pdf_snapshot_disk)->exists($version->pdf_snapshot_path)) {
            return ['content' => Storage::disk($version->pdf_snapshot_disk)->get($version->pdf_snapshot_path), 'filename' => $filename, 'etag' => $version->pdf_snapshot_sha256];
        }
        $html = view('sales::Public.quote-pdf', ['version' => $version, 'opportunity' => $version->quote->opportunity, 'quotePresentation' => app(SalesQuotePresentation::class)->forVersion($version)])->render();
        $dompdf = new Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return ['content' => $dompdf->output(), 'filename' => $filename, 'etag' => null];
    }
}
