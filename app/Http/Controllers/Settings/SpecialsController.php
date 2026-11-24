<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SpecialsController extends Controller
{
    /** Bonus (special) items on or off. */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']], [
            'enabled.required' => 'Please choose on or off.',
            'enabled.boolean' => 'Please choose on or off.',
        ]);

        $request->user()->forceFill(['specials_enabled' => (bool) $data['enabled']])->save();

        return back();
    }
}
