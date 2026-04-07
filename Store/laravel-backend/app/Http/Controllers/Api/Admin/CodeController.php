<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CodeController extends Controller
{
    public function index()
    {
        $codes = DB::table('access_codes')->orderByDesc('created_at')->get();
        return response()->json(['status' => 'success', 'data' => $codes]);
    }

    public function generate()
    {
        $code    = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $expires = now()->addDays(90)->toDateTimeString();

        $id = DB::table('access_codes')->insertGetId([
            'code'       => $code,
            'expires_at' => $expires,
            'is_active'  => 1,
            'created_by' => 'admin_panel',
            'created_at' => now(),
        ]);

        return response()->json(['status' => 'success', 'message' => 'Code generated', 'data' => [
            'id'      => $id,
            'code'    => $code,
            'expires' => $expires,
        ]]);
    }

    public function deactivate(Request $request)
    {
        $id = (int) $request->post('id', 0);
        if ($id <= 0) {
            return response()->json(['status' => 'error', 'message' => 'Invalid id'], 400);
        }

        DB::table('access_codes')->where('id', $id)->update(['is_active' => 0]);
        return response()->json(['status' => 'success', 'message' => 'Code deactivated']);
    }
}
