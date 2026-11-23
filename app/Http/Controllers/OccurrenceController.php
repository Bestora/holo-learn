<?php

namespace App\Http\Controllers;

use App\Models\Occurrence;
use Illuminate\Http\Response;

class OccurrenceController extends Controller
{
    /** The player could not embed the video: never choose this occurrence again. */
    public function dead(Occurrence $occurrence): Response
    {
        if ($occurrence->dead_at === null) {
            $occurrence->update(['dead_at' => now()]);
        }

        return response()->noContent();
    }
}
