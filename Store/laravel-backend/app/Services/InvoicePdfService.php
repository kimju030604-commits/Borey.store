<?php

namespace App\Services;

class InvoicePdfService
{
    public function generate(array $invoice): string
    {
        // Point FPDF to the libs directory so font includes resolve correctly
        if (!defined('FPDF_FONTPATH')) {
            define('FPDF_FONTPATH', base_path('libs/font/'));
        }

        require_once base_path('libs/InvoicePDF.php');

        $pdfDir = storage_path('app/public/invoices');
        if (!is_dir($pdfDir)) {
            mkdir($pdfDir, 0755, true);
        }

        $filename    = 'Invoice_' . $invoice['invoice_number'] . '.pdf';
        $fullPath    = $pdfDir . DIRECTORY_SEPARATOR . $filename;

        generateInvoicePDF($invoice, $fullPath);

        return 'storage/invoices/' . $filename;
    }
}
