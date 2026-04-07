<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private string $token;
    private string $chatId;

    public function __construct()
    {
        $this->token  = config('services.telegram.bot_token', '');
        $this->chatId = config('services.telegram.chat_id', '');
    }

    public function send(string $text): bool
    {
        if (!$this->token || !$this->chatId) {
            Log::warning('TelegramService: token or chat_id not configured.');
            return false;
        }

        try {
            $response = Http::timeout(8)->post(
                "https://api.telegram.org/bot{$this->token}/sendMessage",
                [
                    'chat_id'                  => $this->chatId,
                    'text'                     => $text,
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => true,
                ]
            );
            return $response->json('ok', false);
        } catch (\Throwable $e) {
            Log::warning('Telegram notification failed: ' . $e->getMessage());
            return false;
        }
    }

    public function notifyNewOrder(array $data): bool
    {
        $invoiceNumber  = $data['invoice_number']    ?? '';
        $customerName   = htmlspecialchars($data['customer_name']    ?? '', ENT_XML1);
        $customerPhone  = htmlspecialchars($data['customer_phone']   ?? '', ENT_XML1);
        $location       = htmlspecialchars($data['customer_location']?? '', ENT_XML1);
        $items          = $data['items'] ?? [];
        $totalKhr       = number_format((int) ($data['total_khr'] ?? 0));
        $totalUsd       = (float) ($data['total_usd'] ?? 0);
        $paymentBank    = htmlspecialchars($data['payment_bank'] ?? 'Bakong', ENT_XML1);
        $payerName      = htmlspecialchars($data['payer_name']   ?? $customerName, ENT_XML1);
        $bakongHash     = $data['bakong_hash'] ?? '';

        $itemLines = [];
        foreach ($items as $item) {
            $name     = htmlspecialchars($item['name'] ?? 'Item', ENT_XML1);
            $qty      = (int) ($item['qty'] ?? $item['quantity'] ?? 1);
            $priceKhr = isset($item['price']) ? number_format($item['price'] * 4000 * $qty) : '—';
            $itemLines[] = "  • {$name} ×{$qty} = {$priceKhr} ៛";
        }

        $msg  = "🧾 <b>New Order — Invoice #{$invoiceNumber}</b>\n\n";
        $msg .= "👤 <b>Customer:</b> {$customerName}\n";
        if ($customerPhone) $msg .= "📞 <b>Phone:</b> {$customerPhone}\n";
        if ($location)      $msg .= "📍 <b>Location:</b> {$location}\n";
        $msg .= "\n🛒 <b>Items:</b>\n" . implode("\n", $itemLines) . "\n\n";
        $msg .= "💰 <b>Total:</b> {$totalKhr} ៛";
        if ($totalUsd > 0) $msg .= " (~\${$totalUsd})";
        $msg .= "\n💳 <b>Payment:</b> {$paymentBank}\n";
        if ($payerName && $payerName !== $customerName) {
            $msg .= "✅ <b>Paid by:</b> {$payerName}\n";
        } else {
            $msg .= "✅ <b>Payment:</b> Confirmed\n";
        }
        if ($bakongHash) {
            $msg .= "🔗 <b>Bakong Hash:</b> " . substr($bakongHash, 0, 8) . "\n";
        }

        return $this->send($msg);
    }
}
