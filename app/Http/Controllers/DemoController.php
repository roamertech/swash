<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class DemoController
{
    public function reset(): JsonResponse
    {
        try {
            Artisan::call('migrate:fresh', [
                '--seed' => true,
                '--force' => true,
            ]);

            return response()->json([
                'reset' => true,
                'message' => 'Demo content restored.',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'reset' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
