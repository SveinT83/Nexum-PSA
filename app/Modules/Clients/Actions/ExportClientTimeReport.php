<?php

namespace App\Modules\Clients\Actions;

use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Both exports consume exactly the same authorized report as the browser. */
class ExportClientTimeReport
{
    public function download(array $report, string $format)
    {
        $name = 'client-'.$report['client']->id.'-time-'.($report['from'] ?? 'all').'-'.($report['to'] ?? 'all');
        if ($format === 'pdf') {
            $pdf = new Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
            $pdf->loadHtml(view('clients::Tech.time-report-pdf', $report)->render());
            $pdf->setPaper('a4', 'landscape');
            $pdf->render();
            $pdf->getCanvas()->page_text(735, 575, 'Page {PAGE_NUM} / {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('Helvetica'), 8);

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return response()->streamDownload(function () use ($report): void {
            $book = new Spreadsheet;
            $summary = $book->getActiveSheet()->setTitle('Summary');
            $this->row($summary, 1, ['Internal report', $report['client']->name]);
            $this->row($summary, 2, ['Period (work date)', ($report['from'] ?? 'All history').' / '.($report['to'] ?? 'No end date')]);
            $this->row($summary, 3, ['Generated', $report['generated_at']->toIso8601String()]);
            $this->row($summary, 4, ['Units', 'Integer minutes. Registered work and billing basis are separate measures.']);
            $this->row($summary, 5, ['Notes', 'Includes archived and ordered history. Private Tasks excluded. Registered time may include estimate-completion entries; not approved payroll.']);
            $this->row($summary, 6, ['Allocation', 'Covered/billable are stored processed allocations; unresolved billing basis is not guessed as overuse.']);
            $this->row($summary, 8, ['Type', 'Reference', 'Title', 'Status', 'Archived', 'Parent', 'Registered work', 'Billing basis',
                'Covered (processed)', 'Billable (processed)', 'Unresolved billing basis', 'Entries']);
            $index = 9;
            foreach ($report['objects'] as $object) {
                $this->row($summary, $index++, [$object['type'], $object['reference'], $object['title'], $object['status'],
                    $object['archived'] ? 'Yes' : 'No', $object['parent'], $object['work_minutes'], $object['basis_minutes'],
                    $object['covered_minutes'], $object['processed_billable_minutes'], $object['unresolved_minutes'], $object['entry_count']]);
            }
            $this->row($summary, $index, ['TOTAL', '', '', '', '', '', ...array_values($report['totals']), $report['entries']->count()]);
            $detail = $book->createSheet()->setTitle('Time entries');
            $this->row($detail, 1, ['Source', 'Entry ID', 'Object', 'Reference', 'Work date', 'Employee', 'Work description', 'Invoice text',
                'Provenance', 'Registered work', 'Billing basis', 'Covered (processed)', 'Billable (processed)', 'Unresolved billing basis', 'Billing status', 'On order', 'Gross billing basis', 'Correction ID', 'Correction reason']);
            $index = 2;
            foreach ($report['entries'] as $entry) {
                $this->row($detail, $index++, [$entry['source'], $entry['id'], $entry['object_key'], $entry['reference'], $entry['date'],
                    $entry['employee'], $entry['description'], $entry['invoice_text'], $entry['provenance'], $entry['work_minutes'],
                    $entry['basis_minutes'], $entry['covered_minutes'], $entry['processed_billable_minutes'], $entry['unresolved_minutes'],
                    $entry['billing_status'], $entry['ordered'] ? 'Yes' : 'No', $entry['gross_basis_minutes'], $entry['correction_id'], $entry['correction_reason']]);
            }
            $history = $book->createSheet()->setTitle('Corrections');
            $this->row($history, 1, ['ID', 'Kind', 'Source', 'Source ID', 'Reason', 'Effective date', 'Recorded at', 'Adjustment minutes', 'Reversed usage minutes']);
            foreach (($report['corrections'] ?? collect()) as $index => $correction) {
                $this->row($history, $index + 2, array_values($correction));
            }
            foreach ($book->getAllSheets() as $sheet) {
                $sheet->freezePane($sheet === $summary ? 'G9' : 'J2');
                $sheet->getDefaultColumnDimension()->setWidth(20);
            }
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $name.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store']);
    }

    private function row($sheet, int $row, array $values): void
    {
        foreach (array_values($values) as $index => $value) {
            // Explicit text prevents customer titles/notes beginning "=" from becoming formulas.
            $sheet->setCellValueExplicit([$index + 1, $row], $value ?? '',
                is_int($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
        }
    }
}
