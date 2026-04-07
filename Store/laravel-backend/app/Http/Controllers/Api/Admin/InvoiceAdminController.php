<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\InvoicePdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InvoiceAdminController extends Controller
{
    public function index(Request $request)
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;
        $date    = $request->query('date', '');

        $query = DB::table('invoices');
        if ($date) {
            $query->whereDate('created_at', $date);
        }

        $total   = $query->count();
        $revenue = $query->sum('total_usd');
        $items   = (clone $query)->orderByDesc('created_at')->offset($offset)->limit($perPage)->get()
            ->map(function ($inv) {
                $inv->items = json_decode($inv->items ?? '[]', true) ?: [];
                return $inv;
            });

        return response()->json([
            'status' => 'success',
            'data'   => $items,
            'total'  => $total,
            'revenue'=> round($revenue, 2),
            'total_pages' => (int) ceil($total / $perPage),
        ]);
    }

    public function destroy(int $id)
    {
        $invoice = DB::table('invoices')->find($id);
        if (!$invoice) {
            return response()->json(['status' => 'error', 'message' => 'Invoice not found'], 404);
        }

        // Remove PDF file if it exists
        if ($invoice->pdf_path) {
            $fullPath = storage_path('app/public/' . str_replace('storage/', '', $invoice->pdf_path));
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }

        DB::table('invoices')->delete($id);
        return response()->json(['status' => 'success', 'message' => 'Invoice deleted']);
    }

    public function regeneratePdf(Request $request)
    {
        $id = (int) $request->post('id', 0);
        $invoice = DB::table('invoices')->find($id);
        if (!$invoice) {
            return response()->json(['status' => 'error', 'message' => 'Invoice not found'], 404);
        }

        try {
            $data = (array) $invoice;
            $pdfPath = app(InvoicePdfService::class)->generate($data);
            DB::table('invoices')->where('id', $id)->update(['pdf_path' => $pdfPath]);
            return response()->json(['status' => 'success', 'message' => 'PDF regenerated', 'data' => ['pdf_path' => $pdfPath]]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
