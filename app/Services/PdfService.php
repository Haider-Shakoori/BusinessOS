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

        // Render Blade once, then resolve the small set of CSS custom
        // properties used by browser print. Dompdf targets CSS 2.1 and does
        // not reliably support CSS variables, so literal values produce
        // deterministic PDF styling without maintaining separate templates.
        $html = view($document['view'], $document['data'])->render();
        $accent = (string) ($document['data']['presentation']['accent_color'] ?? '#2563EB');

        $html = strtr($html, [
            'var(--accent)' => $accent,
            'var(--ink)' => '#0f172a',
            'var(--muted)' => '#64748b',
            'var(--line)' => '#e2e8f0',
            'var(--paper)' => '#ffffff',
            'var(--page)' => '#f1f5f9',
        ]);

        $pdf = Pdf::loadHTML($html)
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
