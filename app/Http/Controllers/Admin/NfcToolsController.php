<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NfcToolsController extends Controller
{
    /** GET /admin/nfc-tools */
    public function index(Request $request): Response
    {
        $card = null;

        if ($publicId = trim((string) $request->query('card', ''))) {
            $card = Card::where('public_id', $publicId)->first();
        }

        return response()->view('admin.nfc-tools', ['card' => $card])
            ->header('Cache-Control', 'no-store, private, no-cache, must-revalidate, max-age=0');
    }
}
