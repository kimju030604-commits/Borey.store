<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    public function index()
    {
        $products = DB::table('products')->selectRaw('COUNT(*) AS total')->first();
        $invoices = DB::table('invoices')->selectRaw('COUNT(*) AS total, SUM(total_usd) AS revenue')->first();
        $stock    = DB::table('products')->selectRaw('SUM(stock) AS total_stock, SUM(stock = 0) AS out_of_stock')->first();
        $codes    = DB::table('access_codes')->selectRaw('COUNT(*) AS active')->where('is_active', 1)->first();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_products' => (int)   ($products->total     ?? 0),
                'total_invoices' => (int)   ($invoices->total     ?? 0),
                'total_revenue'  => (float) ($invoices->revenue   ?? 0),
                'total_stock'    => (int)   ($stock->total_stock  ?? 0),
                'out_of_stock'   => (int)   ($stock->out_of_stock ?? 0),
                'active_codes'   => (int)   ($codes->active       ?? 0),
            ],
        ]);
    }
}
