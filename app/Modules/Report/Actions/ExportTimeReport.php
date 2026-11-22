<?php

namespace App\Modules\Report\Actions;

use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Exports retain every matching row and use explicit string cells for untrusted labels/notes. */
class ExportTimeReport
{
    public function download(array $report, string $format)
    {
        $name = 'ticket-task-time-'.($report['filters']['date_from'] ?? 'all').'-'.($report['filters']['date_to'] ?? 'all');
        if ($format === 'pdf') {
            $pdf = new Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
            $pdf->loadHtml(view('report::Tech.time-pdf', $report)->render());
            $pdf->setPaper('a4', 'landscape');
            $pdf->render();

            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"', 'Cache-Control' => 'private, no-store']);
        }

        return response()->streamDownload(function () use ($report) {
            $book = new Spreadsheet;
            $summary = $book->getActiveSheet()->setTitle('Summary');
            $this->row($summary, 1, ['Internal report — Ticket and Task time', $report['generated_at']]);
            $this->row($summary, 2, ['Registered minutes', $report['totals']['minutes']]);
            $this->row($summary, 3, ['Scope', json_encode($report['filters'], JSON_UNESCAPED_UNICODE)]);
            $this->row($summary, 4, ['Registered work only; not payroll or an invoice. Task billing projections excluded.']);
            $this->row($summary, 6, ['Group ('.$report['filters']['group_by'].')', 'Minutes', 'Entries']);
            foreach ($report['groups'] as $i => $group) {
                $this->row($summary, $i + 7, [$group['label'], $group['minutes'], $group['entry_count']]);
            }
            $detail = $book->createSheet()->setTitle('Time entries');
            $this->row($detail, 1, ['Work date', 'Employee', 'Client / Internal', 'Source', 'Reference', 'Title',
                'Minutes', 'Billable source', 'Provenance', 'Status', 'Archived', 'Parent Ticket', 'Note']);
            foreach ($report['entries'] as $i => $entry) {
                $this->row($detail, $i + 2, [$entry['date'], $entry['employee'], $entry['client'], $entry['source'],
                    $entry['reference'], $entry['title'], $entry['minutes'], $entry['billable'] ? 'Yes' : 'No',
                    $entry['provenance'], $entry['status'], $entry['archived'] ? 'Yes' : 'No',
                    $entry['parent_ticket_reference'], $entry['note']]);
            }
            foreach ($book->getAllSheets() as $sheet) {
                $sheet->getDefaultColumnDimension()->setWidth(22);
                $sheet->freezePane($sheet === $summary ? 'A7' : 'A2');
            }
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $name.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store']);
    }

    private function row($sheet, int $row, array $values): void
    {
        foreach (array_values($values) as $index => $value) {
            $sheet->setCellValueExplicit([$index + 1, $row], $value ?? '',
                is_int($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
        }
    }
}
