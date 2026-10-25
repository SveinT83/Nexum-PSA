<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Support\ContractCustomerDocument;
use App\Modules\Commercial\Support\ContractDocumentReadiness;
use App\Modules\System\Support\CompanyProfileSettings;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;

/** One PDF renderer and immutable document source for browser and API. */
class BuildContractPdf
{
    public function handle(Contracts $contract): array
    {
        $contract->load(['client', 'sla', 'termSnapshots.termVersion', 'items.slaPolicy', 'items.timeRates']);
        if (! $contract->isReady() && ! in_array($contract->approval_status, ['approved', 'sent_quote', 'sent_contract', 'won'], true)) {
            throw new \DomainException('Contract is not ready for PDF export.');
        }
        $documents = app(ContractCustomerDocument::class);
        $profile = app(CompanyProfileSettings::class)->get();
        $customerDocument = $documents->resolve($contract, $profile);
        if (! $documents->hasStoredSnapshot($contract) && app(ContractDocumentReadiness::class)->missingLegalIdentity($contract, $profile) !== []) {
            throw new \DomainException(app(ContractDocumentReadiness::class)->failureMessage($contract, $profile));
        }
        $html = view('commercial::Tech.cs.contracts.pdf', [
            'contract' => $contract,
            'companyProfile' => $profile,
            'customerDocument' => $customerDocument,
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $footerLeft = $customerDocument['document']['type'].' #'.$customerDocument['document']['contract_number']
            .' · '.Str::limit((string) ($customerDocument['parties']['customer']['name'] ?? ''), 48);
        $dompdf->getCanvas()->page_text(
            36,
            816,
            $footerLeft,
            $font,
            8,
            [0.35, 0.4, 0.47],
        );
        $dompdf->getCanvas()->page_text(485, 816, 'Side {PAGE_NUM} av {PAGE_COUNT}', $font, 8, [0.35, 0.4, 0.47]);

        return ['bytes' => $dompdf->output(), 'filename' => $this->fileName($contract)];
    }

    private function fileName(Contracts $contract): string
    {
        $clientSlug = Str::slug($contract->client?->name ?: 'client');

        return 'contract-'.$contract->id.'-'.$clientSlug.'.pdf';
    }
}
