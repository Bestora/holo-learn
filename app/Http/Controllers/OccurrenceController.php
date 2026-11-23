<?php

namespace App\Http\Controllers;

use App\Models\Occurrence;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class OccurrenceController extends Controller
{
    /** The player could not embed the video: never choose this occurrence again. */
    public function dead(Request $request, Occurrence $occurrence): Response
    {
        Log::info('occurrence marked dead', ['occurrence_id' => $occurrence->id, 'user_id' => $request->user()?->id]);
        if ($occurrence->dead_at === null) {
            $occurrence->update(['dead_at' => now()]);
        }

        return response()->noContent();
    }
}
