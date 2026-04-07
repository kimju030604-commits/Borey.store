<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function check(Request $request)
    {
        $ok = $request->session()->get('admin_logged_in', false);
        return response()->json([
            'status'  => $ok ? 'success' : 'error',
            'message' => $ok ? 'Authenticated' : 'Not authenticated',
            'data'    => ['logged_in' => $ok],
        ], $ok ? 200 : 401);
    }

    public function logout(Request $request)
    {
        $request->session()->forget('admin_logged_in');
        $request->session()->forget('access_code_id');
        return response()->json(['status' => 'success', 'message' => 'Logged out']);
    }

    public function login(Request $request)
    {
        $ip           = $request->ip();
        $attemptsKey  = 'login_attempts_' . md5($ip);
        $tsKey        = 'login_ts_' . md5($ip);
        $attempts     = $request->session()->get($attemptsKey, 0);
        $lastAttempt  = $request->session()->get($tsKey, 0);

        if (time() - $lastAttempt > 900) {
            $attempts = 0;
        }

        if ($attempts >= 10) {
            $wait = max(0, 900 - (time() - $lastAttempt));
            return response()->json([
                'status'  => 'error',
                'message' => 'Too many failed attempts. Please wait ' . ceil($wait / 60) . ' minute(s).',
            ], 429);
        }

        $code = trim($request->post('access_code', ''));
        if (!$code) {
            return response()->json(['status' => 'error', 'message' => 'Please enter your access code.'], 400);
        }

        $row = DB::selectOne(
            "SELECT * FROM access_codes WHERE code = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())",
            [$code]
        );

        if ($row) {
            DB::table('access_codes')->where('id', $row->id)->update([
                'used_count' => DB::raw('used_count + 1'),
                'last_used'  => now(),
            ]);
            $request->session()->regenerate();
            $request->session()->put('admin_logged_in', true);
            $request->session()->put('access_code_id', $row->id);
            $request->session()->forget([$attemptsKey, $tsKey]);

            return response()->json(['status' => 'success', 'message' => 'Authenticated']);
        }

        $request->session()->put($attemptsKey, $attempts + 1);
        $request->session()->put($tsKey, time());
        return response()->json(['status' => 'error', 'message' => 'Invalid or expired access code.'], 401);
    }
}
