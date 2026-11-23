<?php

namespace App\Annotations;

use App\Models\Reference;

/**
 * Lists references that still need an annotation, as JSONL lines for an AI agent.
 */
class Exporter
{
    /**
     * References with status new (or rejected) that occur somewhere, the most important
     * talents (lowest priority) first, references of talents without priority last.
     *
     * @return array<int, string>
     */
    public function lines(int $limit, bool $retryRejected = false): array
    {
        return Reference::query()
            ->where('status', $retryRejected ? 'rejected' : 'new')
            ->has('occurrences')
            ->withMin('talents', 'priority')
            ->orderByRaw('talents_min_priority is null') // nulls last; Eloquent has no portable helper for it
            ->orderBy('talents_min_priority')
            ->orderBy('kind')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Reference $reference) => json_encode([
                'key' => "{$reference->kind}:{$reference->text_key}",
                'kind' => $reference->kind,
                'text' => $reference->text_ja,
                'alt' => $reference->alt_text,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->all();
    }
}
