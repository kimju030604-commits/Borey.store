<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InvoicePdfService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function show(Request $request)
    {
        $invoiceNumber = trim($request->query('id', ''));
        $orderId       = trim($request->query('order', ''));

        if ($invoiceNumber) {
            $invoice = DB::table('invoices')->where('invoice_number', $invoiceNumber)->first();
        } elseif ($orderId) {
            $invoice = DB::table('invoices')->where('order_id', $orderId)->first();
        } else {
            return response()->json(['status' => 'error', 'message' => 'Missing id or order'], 400);
        }

        if (!$invoice) {
            return response()->json(['status' => 'error', 'message' => 'Invoice not found'], 404);
        }

        $data         = (array) $invoice;
        $data['items'] = json_decode($data['items'] ?? '[]', true) ?: [];

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    public function store(Request $request)
    {
        $orderId          = trim($request->post('order_id', ''));
        $customerName     = trim($request->post('customer_name', ''));
        $customerPhone    = trim($request->post('customer_phone', ''));
        $customerLocation = trim($request->post('customer_location', ''));
        $items            = $request->post('items', '[]');
        $subtotal         = (float) $request->post('subtotal', 0);
        $deliveryFee      = (float) $request->post('delivery_fee', 0);
        $totalUsd         = (float) $request->post('total_usd', 0);
        $totalKhr         = (int)   $request->post('total_khr', 0);
        $paymentMethod    = trim($request->post('payment_method', 'KHQR'));
        $paymentBank      = trim($request->post('payment_bank', 'Bakong'));
        $payerName        = trim($request->post('payer_name', $customerName));
        $payerAccountId   = trim($request->post('payer_account_id', ''));
        $bakongHash       = trim($request->post('bakong_hash', ''));
        $paymentTimeInput = trim($request->post('payment_time', ''));
        $paymentTime      = $paymentTimeInput ?: now()->toDateTimeString();

        if (!$orderId || !$customerName || !$customerPhone) {
            return response()->json(['status' => 'error', 'message' => 'Missing required fields'], 400);
        }

        // Normalize phone
        $digits = preg_replace('/\D+/', '', $customerPhone);
        if (str_starts_with($digits, '855')) {
            $digits = '0' . substr($digits, 3);
        }
        $customerPhone = $digits;
        if (!preg_match('/^0\d{8,9}$/', $customerPhone)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid phone number. Use format 0XXXXXXXXX'], 400);
        }

        // Idempotency: return existing invoice if already created
        $existing = DB::table('invoices')->where('order_id', $orderId)->first();
        if ($existing) {
            if ($bakongHash && !$existing->bakong_hash) {
                DB::table('invoices')->where('order_id', $orderId)->update(['bakong_hash' => $bakongHash]);
            }
            return response()->json(['status' => 'success', 'message' => 'Invoice already exists', 'data' => [
                'invoice_number' => $existing->invoice_number,
                'order_id'       => $orderId,
            ]]);
        }

        $itemsArray = json_decode($items, true) ?: [];

        // Wrap in a DB transaction to deduct stock atomically
        try {
            $result = DB::transaction(function () use (
                $orderId, $customerName, $customerPhone, $customerLocation,
                $items, $itemsArray, $subtotal, $deliveryFee, $totalUsd, $totalKhr,
                $paymentMethod, $paymentBank, $payerName, $payerAccountId, $bakongHash, $paymentTime
            ) {
                // Check & deduct stock
                foreach ($itemsArray as $item) {
                    $pid = (int) ($item['id'] ?? 0);
                    $qty = max(1, (int) ($item['qty'] ?? $item['quantity'] ?? 1));
                    if ($pid <= 0) continue;

                    $product = DB::table('products')->lockForUpdate()->find($pid);
                    if ($product && $product->stock < $qty) {
                        throw new \Exception('Insufficient stock for ' . ($product->name ?? 'item'), 409);
                    }
                    DB::table('products')->where('id', $pid)->decrement('stock', $qty);
                }

                // Generate invoice number
                $maxNum = DB::table('invoices')
                    ->selectRaw("MAX(CAST(SUBSTRING_INDEX(invoice_number, '.', -1) AS UNSIGNED)) AS max_num")
                    ->value('max_num') ?? 0;
                $next          = (int) $maxNum + 1;
                $invoiceNumber = 'Borey.' . str_pad($next, 4, '0', STR_PAD_LEFT);
                $orderNumber   = 'BRY-'   . str_pad($next, 4, '0', STR_PAD_LEFT);

                DB::table('invoices')->insert([
                    'invoice_number'   => $invoiceNumber,
                    'order_id'         => $orderId,
                    'order_number'     => $orderNumber,
                    'customer_name'    => $customerName,
                    'customer_phone'   => $customerPhone,
                    'customer_location'=> $customerLocation,
                    'items'            => $items,
                    'subtotal'         => $subtotal,
                    'delivery_fee'     => $deliveryFee,
                    'total_usd'        => $totalUsd,
                    'total_khr'        => $totalKhr,
                    'payment_method'   => $paymentMethod,
                    'payment_bank'     => $paymentBank,
                    'payer_name'       => $payerName,
                    'payer_account_id' => $payerAccountId,
                    'bakong_hash'      => $bakongHash,
                    'payment_status'   => 'paid',
                    'payment_time'     => $paymentTime,
                    'created_at'       => now(),
                ]);

                return ['invoice_number' => $invoiceNumber, 'order_number' => $orderNumber];
            });
        } catch (\Exception $e) {
            $code = $e->getCode() === 409 ? 409 : 500;
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        }

        $invoiceNumber = $result['invoice_number'];
        $orderNumber   = $result['order_number'];

        // Generate PDF
        $pdfPath = null;
        try {
            $invoiceData = [
                'invoice_number'   => $invoiceNumber,
                'order_id'         => $orderId,
                'order_number'     => $orderNumber,
                'customer_name'    => $customerName,
                'customer_phone'   => $customerPhone,
                'customer_location'=> $customerLocation,
                'items'            => $items,
                'subtotal'         => $subtotal,
                'delivery_fee'     => $deliveryFee,
                'total_usd'        => $totalUsd,
                'total_khr'        => $totalKhr,
                'payment_method'   => $paymentMethod,
                'payment_bank'     => $paymentBank,
                'payer_name'       => $payerName,
                'payer_account_id' => $payerAccountId,
                'bakong_hash'      => $bakongHash,
                'payment_status'   => 'paid',
                'payment_time'     => $paymentTime,
                'created_at'       => now()->toDateTimeString(),
            ];
            $pdfPath = app(InvoicePdfService::class)->generate($invoiceData);
            DB::table('invoices')->where('invoice_number', $invoiceNumber)->update(['pdf_path' => $pdfPath]);
        } catch (\Throwable $e) {
            \Log::warning('PDF generation failed: ' . $e->getMessage());
        }

        // Send Telegram notification
        try {
            app(TelegramService::class)->notifyNewOrder([
                'invoice_number'   => $invoiceNumber,
                'customer_name'    => $customerName,
                'customer_phone'   => $customerPhone,
                'customer_location'=> $customerLocation,
                'items'            => $itemsArray,
                'total_khr'        => $totalKhr,
                'total_usd'        => $totalUsd,
                'payment_bank'     => $paymentBank,
                'payer_name'       => $payerName,
                'bakong_hash'      => $bakongHash,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Telegram notification failed: ' . $e->getMessage());
        }

        return response()->json(['status' => 'success', 'message' => 'Invoice created', 'data' => [
            'invoice_number' => $invoiceNumber,
            'order_id'       => $orderId,
            'order_number'   => $orderNumber,
            'pdf_path'       => $pdfPath,
        ]]);
    }
}
