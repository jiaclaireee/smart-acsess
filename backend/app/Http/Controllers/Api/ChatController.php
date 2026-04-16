<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function chat(Request $request)
    {
        $data = $request->validate([
            'message' => ['required','string','max:5000'],
            'context' => ['nullable','array'],
        ]);

        $msg = $data['message'];

        return response()->json([
            'reply' => "Nakuha ko: \"{$msg}\". (Stub) Kapag may connected DB na, puwede akong mag-generate ng summary tables at basic reports.",
            'table' => null,
            'chart' => null
        ]);
    }
}
