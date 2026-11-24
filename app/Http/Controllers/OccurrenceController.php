<?php

namespace App\Http\Controllers;

use App\Models\Occurrence;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OccurrenceController extends Controller
{
    /**
     * The player could not embed the video. A player error alone is not proof (a headless or
     * blocked browser errors too), so YouTube's oEmbed must confirm: 401/403/404 → never choose
     * this occurrence again; anything else leaves it alone.
     */
    public function dead(Request $request, Occurrence $occurrence): Response
    {
        abort_if($occurrence->video_id === null, 404);
        if ($occurrence->dead_at === null && $this->goneOnYoutube($occurrence->video_id)) {
            $occurrence->update(['dead_at' => now()]);
            Log::info('occurrence marked dead', ['occurrence_id' => $occurrence->id, 'user_id' => $request->user()?->id]);
        }

        return response()->noContent();
    }

    private function goneOnYoutube(string $videoId): bool
    {
        try {
            $status = Http::timeout(5)
                ->withUserAgent('holo-learn (+https://holo-learn.u0x.de)')
                ->get('https://www.youtube.com/oembed', [
                    'url' => 'https://www.youtube.com/watch?v='.$videoId,
                    'format' => 'json',
                ])->status();
        } catch (ConnectionException) {
            return false;
        }

        return in_array($status, [401, 403, 404], true);
    }
}
