<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Services\InvoicePdfService;

class PaymentController extends Controller
{
    /** Forward generate-KHQR to the FastAPI Bakong gateway */
    public function generateKhqr(Request $request)
    {
        $gatewayUrl = rtrim(config('services.bakong.gateway_url'), '/');

        $response = Http::asForm()->timeout(30)->post("{$gatewayUrl}/api/payment", array_merge(
            $request->only(['amount', 'order_id', 'description', 'customer_name', 'customer_phone', 'customer_location']),
            ['action' => 'generate_khqr']
        ));

        return response()->json($response->json(), $response->status());
    }

    /** Forward check-payment status to FastAPI */
    public function checkPayment(Request $request)
    {
        $gatewayUrl = rtrim(config('services.bakong.gateway_url'), '/');

        $response = Http::asForm()->timeout(15)->post("{$gatewayUrl}/api/payment", [
            'action'   => 'check_payment',
            'order_id' => $request->post('order_id', ''),
        ]);

        return response()->json($response->json(), $response->status());
    }

    /** Handle receipt image upload */
    public function uploadReceipt(Request $request)
    {
        $orderId = trim($request->post('order_id', ''));
        if (!$orderId) {
            return response()->json(['status' => 'error', 'message' => 'Missing order_id'], 400);
        }

        if (!$request->hasFile('receipt') || !$request->file('receipt')->isValid()) {
            return response()->json(['status' => 'error', 'message' => 'File upload failed'], 400);
        }

        $file      = $request->file('receipt');
        $allowed   = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $ext       = strtolower($file->getClientOriginalExtension());
        $mimeOk    = in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);

        if (!in_array($ext, $allowed, true) || !$mimeOk) {
            return response()->json(['status' => 'error', 'message' => 'Invalid file type. JPG/PNG/WebP/GIF only'], 400);
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return response()->json(['status' => 'error', 'message' => 'File too large. Max 5 MB'], 400);
        }

        $safeOrder = preg_replace('/[^a-zA-Z0-9\-_]/', '', $orderId);
        $filename  = "receipt_{$safeOrder}_" . now()->format('Ymd_His') . ".{$ext}";
        $stored    = $file->storeAs('public/uploads/receipts', $filename);
        $relPath   = 'storage/uploads/receipts/' . $filename;

        $paymentTime = now()->toDateTimeString();
        DB::table('invoices')
            ->where('order_id', $orderId)
            ->update(['receipt_path' => $relPath, 'payment_time' => $paymentTime]);

        // Regenerate PDF with receipt
        $invoice = DB::table('invoices')->where('order_id', $orderId)->first();
        if ($invoice) {
            try {
                $data = (array) $invoice;
                $data['receipt_path'] = $relPath;
                $pdfPath = app(InvoicePdfService::class)->generate($data);
                DB::table('invoices')->where('order_id', $orderId)->update(['pdf_path' => $pdfPath]);
            } catch (\Throwable $e) {
                \Log::warning('PDF regeneration failed: ' . $e->getMessage());
            }
        }

        return response()->json(['status' => 'success', 'message' => 'Receipt uploaded', 'data' => [
            'filename' => $filename,
            'order_id' => $orderId,
        ]]);
    }
}
