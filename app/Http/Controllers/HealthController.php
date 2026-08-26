<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController
{
    public function check(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return response()->json([
                'status' => 'ok',
                'database' => 'connected',
                'time' => now()->toISOString(),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'database' => $e->getMessage(),
                'time' => now()->toISOString(),
            ], 503);
        }
    }
}
