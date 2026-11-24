<?php

namespace App\Http\Controllers;

use App\Models\Annotation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    /** Annotations whose reference the verifier rejected, with the error codes. */
    public function rejected(Request $request): Response
    {
        abort_unless($request->user()->is_admin, 403);

        $annotations = Annotation::query()
            ->whereHas('reference', fn ($q) => $q->where('status', 'rejected'))
            ->with('reference')
            ->orderByDesc('id')
            ->paginate(50)
            ->through(fn (Annotation $a) => [
                'id' => $a->id,
                'kind' => $a->reference->kind,
                'text_ja' => $a->reference->text_ja,
                'alt_text' => $a->reference->alt_text,
                'reading_kana' => $a->reading_kana,
                'meaning_en' => $a->meaning_en,
                'words' => $a->words,
                'check_errors' => $a->check_errors ?? [],
            ]);

        return Inertia::render('admin/rejected', ['annotations' => $annotations]);
    }
}
