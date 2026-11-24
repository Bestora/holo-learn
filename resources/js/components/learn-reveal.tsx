import { Volume2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import YoutubeClip from '@/components/youtube-clip';
import { postJson } from '@/lib/http';
import { alignWords, hasKanji } from '@/lib/kana';
import { cn } from '@/lib/utils';
import { dead } from '@/routes/occurrences';
import type {
    Card,
    Reference,
    Reveal,
    RevealWord,
    Talent,
} from '@/types/learn';

type Segment =
    | { kind: 'word'; positions: number[]; word: RevealWord }
    | { kind: 'gap'; positions: number[] };

/** Words of the reveal laid over the reference text; null when they do not spell it. */
function segments(text: string, words: RevealWord[]): Segment[] | null {
    const aligned = words.length
        ? alignWords(
              text,
              words.map((w) => w.surface),
          )
        : null;
    if (!aligned) {
        return null;
    }
    const chars = Array.from(text);
    const startOf = new Map(aligned.map((p, i) => [Math.min(...p), i]));
    const out: Segment[] = [];
    for (let pos = 0; pos < chars.length;) {
        const w = startOf.get(pos);
        if (w === undefined) {
            out.push({ kind: 'gap', positions: [pos] });
            pos++;
            continue;
        }
        const end = Math.max(...aligned[w]);
        const positions = Array.from(
            { length: end - pos + 1 },
            (_, k) => pos + k,
        );
        out.push({ kind: 'word', positions, word: words[w] });
        pos = end + 1;
    }

    return out;
}

/** Characters of the reference, the learned item lit, the asked word underlined. */
function Chars({
    chars,
    positions,
    reference,
}: {
    chars: string[];
    positions: number[];
    reference: Reference;
}) {
    // underline only when the asked word is more than the lit item (a kanji's whole word)
    const underline = reference.word.some(
        (p) => !reference.highlight.includes(p),
    );

    return positions.map((p) => (
        <span
            key={p}
            className={cn(
                reference.highlight.includes(p) && 'penlight',
                underline &&
                    reference.word.includes(p) &&
                    'underline decoration-penlight decoration-[0.07em] underline-offset-[0.22em]',
            )}
        >
            {chars[p]}
        </span>
    ));
}

/** Question side: the reference without any reading. */
export function ReferenceText({ reference }: { reference: Reference }) {
    const chars = Array.from(reference.text_ja);
    const size =
        chars.length <= 4
            ? 'text-7xl sm:text-8xl'
            : chars.length <= 10
              ? 'text-5xl sm:text-7xl'
              : chars.length <= 20
                ? 'text-4xl sm:text-5xl'
                : 'text-3xl sm:text-4xl';

    return (
        <p
            lang="ja"
            className={cn(
                'leading-tight font-bold tracking-wide text-balance',
                size,
            )}
        >
            <Chars
                chars={chars}
                positions={chars.map((_, i) => i)}
                reference={reference}
            />
        </p>
    );
}

/** Answer side: word by word with furigana over kanji words and romaji below. */
function FuriganaText({
    reference,
    words,
}: {
    reference: Reference;
    words: RevealWord[];
}) {
    const chars = Array.from(reference.text_ja);
    const segs = segments(reference.text_ja, words);
    const unchecked = words.some((w) => !w.ok);

    const wordBox = (seg: Segment, key: number) => {
        const word = seg.kind === 'word' ? seg.word : null;

        return (
            <span
                key={key}
                title={word && !word.ok ? 'unverified' : undefined}
                className={cn(
                    'inline-flex flex-col items-center',
                    word && !word.ok && 'opacity-55',
                )}
            >
                <ruby
                    lang="ja"
                    className="text-4xl leading-snug font-bold sm:text-5xl"
                >
                    <Chars
                        chars={chars}
                        positions={seg.positions}
                        reference={reference}
                    />
                    {word && hasKanji(word.surface) && (
                        <rt
                            className={cn(
                                'font-bold text-muted-foreground',
                                !word.ok && 'italic',
                            )}
                        >
                            {word.reading}
                        </rt>
                    )}
                </ruby>
                <span
                    className={cn(
                        'min-h-5 text-sm text-muted-foreground',
                        word &&
                            !word.ok &&
                            'italic underline decoration-dotted',
                    )}
                >
                    {word?.romaji ?? ' '}
                </span>
            </span>
        );
    };

    return (
        <div>
            {segs ? (
                <p className="flex flex-wrap items-end justify-center gap-x-1.5 gap-y-2">
                    {segs.map(wordBox)}
                </p>
            ) : (
                <>
                    {/* words do not line up with the text: text first, then the words */}
                    <ReferenceText reference={reference} />
                    {words.length > 0 && (
                        <p className="mt-3 flex flex-wrap items-end justify-center gap-x-3 gap-y-2">
                            {words.map((w, i) => (
                                <span
                                    key={i}
                                    title={w.ok ? undefined : 'unverified'}
                                    className={cn(
                                        'inline-flex flex-col items-center',
                                        !w.ok && 'opacity-55',
                                    )}
                                >
                                    <ruby
                                        lang="ja"
                                        className="text-2xl font-bold"
                                    >
                                        {w.surface}
                                        {hasKanji(w.surface) && (
                                            <rt>{w.reading}</rt>
                                        )}
                                    </ruby>
                                    <span className="text-sm text-muted-foreground">
                                        {w.romaji}
                                    </span>
                                </span>
                            ))}
                        </p>
                    )}
                </>
            )}
            {unchecked && (
                <p className="mt-3 text-center text-xs text-muted-foreground">
                    Faded and italic: unverified, the reading of this word is
                    not confirmed.
                </p>
            )}
        </div>
    );
}

/** A Japanese voice of the browser, if there is one. */
function useJapaneseVoice(): SpeechSynthesisVoice | null {
    const [voice, setVoice] = useState<SpeechSynthesisVoice | null>(null);

    useEffect(() => {
        if (!('speechSynthesis' in window)) {
            return;
        }
        const pick = () =>
            setVoice(
                speechSynthesis
                    .getVoices()
                    .find((v) => v.lang.replace('_', '-').startsWith('ja')) ??
                    null,
            );
        pick();
        speechSynthesis.addEventListener('voiceschanged', pick);

        return () => {
            speechSynthesis.removeEventListener('voiceschanged', pick);
            speechSynthesis.cancel();
        };
    }, []);

    return voice;
}

function TalentPhoto({
    talent,
    className,
}: {
    talent: Talent | null;
    className?: string;
}) {
    if (!talent?.photo_url) {
        return null;
    }

    return (
        <img
            src={talent.photo_url}
            alt=""
            referrerPolicy="no-referrer"
            className={cn(
                'shrink-0 rounded-full bg-muted object-cover ring-2 ring-background',
                className,
            )}
        />
    );
}

export function CardMeta({ card }: { card: Card }) {
    const ref = card.reference;
    if (!ref) {
        return null;
    }

    return (
        <div className="flex items-center justify-center gap-3 text-sm text-muted-foreground">
            <TalentPhoto talent={card.talent} className="size-10" />
            <span>
                {ref.kind === 'name' ? 'Name' : 'Song title'}{' '}
                {card.remaining_references > 0 && (
                    <>
                        , {card.remaining_references} more{' '}
                        {card.remaining_references === 1
                            ? 'reference'
                            : 'references'}
                    </>
                )}
            </span>
        </div>
    );
}

export { TalentPhoto };

/** Everything shown after the answer (and on intro cards). */
export function RevealPanel({ card, reveal }: { card: Card; reveal: Reveal }) {
    const voice = useJapaneseVoice();
    const [noClip, setNoClip] = useState(false);
    const ref = card.reference;
    const occurrence = reveal.occurrence;
    const talent =
        occurrence?.talent ?? (card.talent?.name_ja ? card.talent : null);
    const talentName = talent?.name_en ?? talent?.name_ja ?? null;
    const title = ref?.text_ja ?? card.item.characters;

    const speak = () => {
        if (!voice || !reveal.reading) {
            return;
        }
        speechSynthesis.cancel();
        const utterance = new SpeechSynthesisUtterance(reveal.reading);
        utterance.lang = 'ja-JP';
        utterance.voice = voice;
        speechSynthesis.speak(utterance);
    };

    const clipFailed = (reason: 'dead' | 'timeout') => {
        setNoClip(true);
        if (reason === 'dead' && occurrence) {
            postJson(dead.url(occurrence.id)).catch(() => {});
        }
    };

    const item = reveal.item;
    const isKana =
        card.item.kind === 'hiragana' || card.item.kind === 'katakana';

    return (
        <div className="flex animate-rise flex-col gap-6">
            {/* the learned item */}
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl bg-penlight-soft/70 p-4">
                <span
                    lang="ja"
                    className={cn(
                        'min-w-16 shrink-0 text-center font-bold whitespace-nowrap text-penlight',
                        !ref
                            ? 'text-8xl'
                            : Array.from(card.item.characters).length > 2
                              ? 'text-4xl'
                              : 'text-5xl',
                    )}
                >
                    {card.item.characters}
                </span>
                <div className="min-w-0 text-sm">
                    {item.readings.length > 0 && (
                        <p className="text-lg font-semibold">
                            <span lang={isKana ? undefined : 'ja'}>
                                {item.readings.join('、')}
                            </span>
                        </p>
                    )}
                    {item.meanings.length > 0 && (
                        <p className="text-base">{item.meanings.join(', ')}</p>
                    )}
                </div>
            </div>

            {ref && (
                <div className="flex flex-col items-center gap-3 text-center">
                    <FuriganaText reference={ref} words={reveal.words} />
                    {reveal.is_name && reveal.alt_text && (
                        <p className="text-xl font-semibold">
                            {reveal.alt_text}
                        </p>
                    )}
                    {reveal.context_reading && card.field === 'reading' && (
                        <p className="text-sm">
                            {card.is_name_reading
                                ? 'Reading in this name'
                                : 'Reading here'}
                            :{' '}
                            <span lang="ja" className="text-lg font-bold">
                                {reveal.context_reading}
                            </span>
                        </p>
                    )}
                    <dl className="grid gap-1 text-sm">
                        {reveal.meaning && !reveal.is_name && (
                            <div>
                                <dd className="text-base font-medium">
                                    {reveal.meaning}
                                </dd>
                                <dt className="text-xs text-muted-foreground">
                                    machine-translated
                                </dt>
                            </div>
                        )}
                        {reveal.alt_text && !reveal.is_name && (
                            <div className="mt-1">
                                <dd className="text-base">{reveal.alt_text}</dd>
                                <dt className="text-xs text-muted-foreground">
                                    {reveal.alt_kind === 'romaji'
                                        ? 'Spelling on Holodex'
                                        : 'Title on Holodex'}
                                </dt>
                            </div>
                        )}
                    </dl>
                </div>
            )}

            {/* an unverified word's reading is never spoken as if it were right */}
            {voice && reveal.reading && reveal.words.every((w) => w.ok) && (
                <button
                    type="button"
                    onClick={speak}
                    className="mx-auto inline-flex min-h-11 items-center gap-2 rounded-full border border-border bg-card px-5 text-sm font-semibold hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <Volume2 className="size-4" aria-hidden />
                    Read aloud
                </button>
            )}

            {occurrence?.video_id && !noClip ? (
                <YoutubeClip
                    videoId={occurrence.video_id}
                    start={occurrence.start_s}
                    end={occurrence.end_s}
                    title={title}
                    onFail={clipFailed}
                />
            ) : (
                talent && (
                    <div className="flex items-center gap-4 rounded-2xl border border-border bg-card p-4">
                        <TalentPhoto talent={talent} className="size-16" />
                        <div className="min-w-0">
                            <p className="font-semibold">{talentName}</p>
                            {ref?.kind === 'song' && (
                                <p
                                    lang="ja"
                                    className="truncate text-muted-foreground"
                                >
                                    {ref.text_ja}
                                </p>
                            )}
                            {occurrence?.video_id && (
                                <a
                                    href={`https://www.youtube.com/watch?v=${encodeURIComponent(occurrence.video_id)}${occurrence.start_s ? `&t=${occurrence.start_s}` : ''}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-sm text-primary underline underline-offset-4"
                                >
                                    Open on YouTube
                                </a>
                            )}
                        </div>
                    </div>
                )
            )}
            {occurrence?.video_id && !noClip && talentName && (
                <p className="-mt-4 text-center text-sm text-muted-foreground">
                    {talentName}
                </p>
            )}
        </div>
    );
}
