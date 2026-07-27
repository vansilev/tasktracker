<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LocaleController extends Controller
{
    private const SUPPORTED = ['ru', 'uk', 'en'];

    public function switch(Request $request, string $locale): RedirectResponse
    {
        if (! in_array($locale, self::SUPPORTED, true)) {
            abort(404);
        }

        $request->session()->put('locale', $locale);

        if ($user = Auth::user()) {
            $user->update(['locale' => $locale]);
        }

        return back();
    }
}
