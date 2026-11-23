<?php

namespace App\Http\Controllers;

use App\Learning\NewItems;
use App\Models\Review;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, NewItems $newItems): Response
    {
        $user = $request->user();
        $yesterday = now()->setTimezone(config('hololearn.timezone'))->subDay()->toDateString();
        $learned = $user->reviews()->with('item:id,kind')->get()->countBy(fn (Review $r) => $r->item->kind)->all();

        return Inertia::render('dashboard', [
            'stats' => [
                // a missed day breaks the streak even before the next lesson resets it
                'streak_days' => $user->last_lesson_on?->toDateString() >= $yesterday ? $user->streak_days : 0,
                'due_count' => $user->reviews()->where('due_at', '<=', now())
                    ->whereHas('item.references', fn ($q) => $q->where('status', 'verified'))->count(),
                'learned' => array_merge(array_fill_keys(NewItems::KINDS, 0), $learned),
                'available' => $newItems->availableCounts(),
            ],
        ]);
    }
}
