<?php

namespace App\Http\Controllers;

use App\Models\Talent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FavoriteController extends Controller
{
    public function index(Request $request): Response
    {
        $favorites = $request->user()->favorites()->pluck('talents.id')->all();

        // ponytail: sorted in PHP (priority, nulls last, then name) — about a hundred talents
        $talents = Talent::query()->where('is_secondary', false)->get()
            ->sort(fn (Talent $a, Talent $b) => [$a->priority === null, $a->priority, $a->name_en] <=> [$b->priority === null, $b->priority, $b->name_en])
            ->map(fn (Talent $t) => [
                'id' => $t->id, 'name_ja' => $t->name_ja, 'name_en' => $t->name_en, 'photo_url' => $t->photo_url,
                'group_name' => $t->group_name, 'is_favorite' => in_array($t->id, $favorites, true),
            ])->values();

        return Inertia::render('favorites/index', ['talents' => $talents]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'talent_ids' => ['present', 'array'],
            'talent_ids.*' => ['integer', 'distinct', Rule::exists(Talent::class, 'id')->where('is_secondary', false)],
        ], [
            'talent_ids.present' => 'The selection is missing.',
            'talent_ids.array' => 'The selection must be a list.',
            'talent_ids.*.integer' => 'Invalid talent.',
            'talent_ids.*.distinct' => 'A talent is selected twice.',
            'talent_ids.*.exists' => 'This talent cannot be a favorite.',
        ]);

        $request->user()->favorites()->sync($data['talent_ids']);

        return to_route('favorites.index');
    }
}
