<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

final class PdfService
{
    /**
     * @param  array{view: string, filename: string, data: array<string, mixed>}  $document
     */
    public function render(array $document, bool $download = false): Response
    {
        $filename = $this->safeFilename($document['filename']);

        $pdf = Pdf::loadView($document['view'], $document['data'])
            ->setPaper('a4')
            ->setOption([
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'isFontSubsettingEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ]);

        if ($download) {
            return $pdf->download($filename);
        }

        return $pdf->stream($filename, ['Attachment' => false]);
    }

    private function safeFilename(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'document.pdf';
        $filename = trim($filename, '-.');

        if (! str_ends_with(strtolower($filename), '.pdf')) {
            $filename .= '.pdf';
        }

        return $filename !== '.pdf' ? $filename : 'document.pdf';
    }
}
