<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductAdminController extends Controller
{
    public function index()
    {
        $products = DB::table('products')->orderByDesc('id')->get();
        return response()->json(['status' => 'success', 'data' => $products]);
    }

    public function store(Request $request)
    {
        $name     = trim($request->post('name', ''));
        $nameEn   = trim($request->post('name_en', ''));
        $price    = (float) $request->post('price', 0);
        $category = trim($request->post('category', ''));
        $stock    = (int) $request->post('stock', 0);

        if (!$name || $price <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Name and price are required'], 400);
        }

        $imagePath = null;
        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            $file   = $request->file('image');
            $ext    = strtolower($file->getClientOriginalExtension());
            $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            if (in_array($ext, $allowed, true)) {
                $filename  = 'product_' . uniqid() . '.' . $ext;
                $file->storeAs('public/img/products', $filename);
                $imagePath = 'assets/img/products/' . $filename;
            }
        }

        $id = DB::table('products')->insertGetId([
            'name'       => $name,
            'name_en'    => $nameEn,
            'price'      => $price,
            'category'   => $category,
            'stock'      => $stock,
            'image'      => $imagePath,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'success', 'message' => 'Product added', 'data' => ['id' => $id]]);
    }

    public function updateStock(Request $request)
    {
        $id    = (int) $request->post('id', 0);
        $stock = (int) $request->post('stock', 0);

        if ($id <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Invalid product id'], 400);
        }

        DB::table('products')->where('id', $id)->update(['stock' => max(0, $stock), 'updated_at' => now()]);
        return response()->json(['status' => 'success', 'message' => 'Stock updated']);
    }

    public function destroy(int $id)
    {
        if ($id <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Invalid id'], 400);
        }
        DB::table('products')->where('id', $id)->delete();
        return response()->json(['status' => 'success', 'message' => 'Product deleted']);
    }
}
