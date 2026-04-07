<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index()
    {
        $products = DB::table('products')
            ->select('id', 'name', 'name_en', 'price', 'category', 'rating', 'image', 'stock', 'created_at', 'updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(function ($p) {
                $p->image = preg_replace('#^img/products/#', 'assets/img/products/', $p->image ?? '');
                return $p;
            });

        return response()->json(['status' => 'success', 'data' => $products]);
    }
}
