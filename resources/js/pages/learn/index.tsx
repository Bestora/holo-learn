import { HttpResponseError } from '@inertiajs/core';
import { Head, Link, router } from '@inertiajs/react';
import { Check, Flame, Sparkles, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import {
    CardMeta,
    ReferenceText,
    RevealPanel,
} from '@/components/learn-reveal';
import { Badge } from '@/components/ui/badge';
import { errorMessage, formatDateTime, postJson } from '@/lib/http';
import { toKana } from '@/lib/kana';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { answer, index, intro, override, start } from '@/routes/learn';
import { KIND_LABEL } from '@/types/learn';
import type {
    AnswerResult,
    Card,
    LearnProps,
    Lesson,
    NothingDue,
    Summary,
} from '@/types/learn';

export default function Learn({
    lesson,
    card,
    summary,
    nothing_due,
}: LearnProps) {
    return (
        <>
            <Head title="Learn" />
            <div className="mx-auto flex w-full max-w-2xl flex-1 flex-col px-4 pt-4 pb-6 sm:pt-8">
                {summary ? (
                    <SummaryView summary={summary} />
                ) : card && lesson ? (
                    <>
                        <Progress lesson={lesson} />
                        <CardView
                            key={`${lesson.id}-${lesson.position}-${card.type}-${card.item.id}`}
                            card={litWord(card)}
                        />
                    </>
                ) : (
                    <NothingDueView due={nothing_due} />
                )}
            </div>
        </>
    );
}

Learn.layout = {
    breadcrumbs: [{ title: 'Learn', href: index() }],
};

/** A vocabulary item is the whole word: light all of it, not only its first character. */
function litWord(card: Card): Card {
    return card.item.kind === 'vocab' && card.reference
        ? {
              ...card,
              reference: { ...card.reference, highlight: card.reference.word },
          }
        : card;
}

/** "n of 10" as a row of penlights: done ones lit, the current one blinking softly. */
function Progress({ lesson }: { lesson: Lesson }) {
    const current = Math.min(lesson.position + 1, lesson.size);

    return (
        <div
            className="mb-6 flex items-center gap-3"
            aria-label={`Card ${current} of ${lesson.size}`}
        >
            <div className="flex flex-1 gap-1.5" aria-hidden>
                {Array.from({ length: lesson.size }, (_, i) => (
                    <span
                        key={i}
                        className={cn(
                            'h-2.5 flex-1 rounded-full transition-colors',
                            i < lesson.position
                                ? 'bg-sky shadow-[0_0_8px_var(--sky)]'
                                : i === lesson.position
                                  ? 'bg-sky/40'
                                  : 'bg-muted',
                        )}
                    />
                ))}
            </div>
            <span className="text-sm font-semibold whitespace-nowrap tabular-nums">
                {current} of {lesson.size}
            </span>
        </div>
    );
}

function prompt(card: Card): string {
    const { kind } = card.item;
    if (card.field === 'meaning') {
        return kind === 'radical'
            ? 'What does this radical mean?'
            : kind === 'vocab'
              ? 'What does this word mean?'
              : 'What does the glowing character mean?';
    }
    if (kind === 'hiragana' || kind === 'katakana') {
        return 'How do you read the glowing character?';
    }

    const ref = card.reference;
    const underlined = ref?.word.some((p) => !ref.highlight.includes(p));
    const what = underlined
        ? 'the underlined word'
        : kind === 'vocab'
          ? 'the glowing word'
          : 'the glowing character';

    return `How do you read ${what}${card.is_name_reading ? ' in this name' : ''}?`;
}

function CardView({ card }: { card: Card }) {
    const [result, setResult] = useState<AnswerResult | null>(null);
    const [chosen, setChosen] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [overridden, setOverridden] = useState(false);
    const [failed, setFailed] = useState<{
        message: string;
        value: string;
        retry: boolean;
    } | null>(null);
    const nextButton = useRef<HTMLButtonElement>(null);
    const isIntro = card.type === 'intro';
    const done = isIntro || result !== null;
    const correct = overridden || result?.correct;

    useEffect(() => {
        if (done) {
            nextButton.current?.focus({ preventScroll: true });
        }
    }, [done]);

    // a held key must not answer and then skip the reveal: drop auto-repeated Enter and 1–4
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.repeat && /^(Enter|[1-4])$/.test(event.key)) {
                event.preventDefault();
                event.stopPropagation();
            }
        };
        window.addEventListener('keydown', onKey, true);

        return () => window.removeEventListener('keydown', onKey, true);
    }, []);

    const submit = async (value: string) => {
        if (busy || result) {
            return;
        }
        setBusy(true);
        setChosen(value);
        setFailed(null);
        try {
            setResult(
                await postJson<AnswerResult>(answer.url(), {
                    item_id: card.item.id,
                    answer: value,
                }),
            );
        } catch (error) {
            // stay on this card: the learner decides between retrying and reloading
            setChosen(null);
            setFailed({
                message: errorMessage(error),
                value,
                // 422: the card is no longer current, retrying cannot help
                retry: !(
                    error instanceof HttpResponseError &&
                    error.response.status === 422
                ),
            });
        } finally {
            setBusy(false);
        }
    };

    const next = () => {
        if (busy) {
            return;
        }
        setBusy(true);
        const options = { onFinish: () => setBusy(false) };
        if (isIntro) {
            router.post(intro.url(), { item_id: card.item.id }, options);
        } else {
            router.reload(options);
        }
    };

    const claimCorrect = async () => {
        setBusy(true);
        try {
            await postJson(override.url());
            setOverridden(true);
        } catch (error) {
            toast.error(errorMessage(error));
        } finally {
            setBusy(false);
        }
    };

    const reveal = isIntro ? card.reveal : result?.reveal;

    return (
        <div className="flex flex-1 flex-col gap-6">
            <div className="flex flex-wrap items-center justify-center gap-2">
                {isIntro && <Badge className="bg-primary">New</Badge>}
                <Badge variant="secondary">{KIND_LABEL[card.item.kind]}</Badge>
                {card.item.is_special && (
                    <Badge className="gap-1 bg-penlight text-white">
                        <Sparkles className="size-3" aria-hidden />
                        Special
                    </Badge>
                )}
            </div>

            {/* the hero: the reference with the item lit; readings only after the answer */}
            {!done && (
                <section className="flex flex-col items-center gap-4 text-center">
                    <h1 className="text-lg font-semibold text-muted-foreground">
                        {prompt(card)}
                    </h1>
                    {card.item.kind === 'radical' && card.reference ? (
                        <>
                            {/* a radical is a part of a kanji: show it, then the kanji it sits in */}
                            <p
                                lang="ja"
                                className="penlight text-8xl leading-tight font-bold"
                            >
                                {card.item.characters}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                is part of
                            </p>
                            <ReferenceText reference={card.reference} />
                        </>
                    ) : card.reference ? (
                        <ReferenceText reference={card.reference} />
                    ) : (
                        <p
                            lang="ja"
                            className="penlight text-8xl leading-tight font-bold sm:text-9xl"
                        >
                            {card.item.characters}
                        </p>
                    )}
                    <CardMeta card={card} />
                </section>
            )}

            {result && (
                <ResultBanner
                    correct={!!correct}
                    overridden={overridden}
                    expected={
                        card.field === 'meaning' &&
                        result.reveal.item.meanings[0]
                            ? [result.reveal.item.meanings[0]]
                            : result.expected
                    }
                />
            )}

            {failed && (
                <div
                    role="alert"
                    className="flex flex-col gap-3 rounded-2xl bg-wrong-soft p-4 text-center"
                >
                    <p className="font-semibold text-wrong">{failed.message}</p>
                    <div className="flex flex-wrap justify-center gap-2">
                        {failed.retry && (
                            <button
                                type="button"
                                onClick={() => void submit(failed.value)}
                                disabled={busy}
                                className="min-h-11 rounded-xl bg-primary px-5 font-semibold text-primary-foreground disabled:opacity-60"
                            >
                                Try again
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => router.reload()}
                            className="min-h-11 rounded-xl px-5 font-semibold text-primary"
                        >
                            Reload page
                        </button>
                    </div>
                </div>
            )}

            {!done &&
                (card.mode === 'choice' && card.options ? (
                    <Choices
                        options={card.options}
                        busy={busy}
                        onPick={submit}
                    />
                ) : (
                    <TypedAnswer card={card} busy={busy} onSubmit={submit} />
                ))}

            {result && card.mode === 'choice' && card.options && (
                <Choices
                    options={card.options}
                    busy
                    onPick={() => {}}
                    chosen={chosen}
                    right={result.expected[0]}
                />
            )}

            {reveal && <RevealPanel card={card} reveal={reveal} />}

            {done && (
                <div className="sticky bottom-0 -mx-4 mt-auto flex flex-col gap-2 bg-background/90 px-4 pt-3 pb-4 backdrop-blur sm:static sm:mx-0 sm:bg-transparent sm:px-0 sm:backdrop-blur-none">
                    {result &&
                        !result.correct &&
                        result.can_override &&
                        !overridden && (
                            <button
                                type="button"
                                onClick={claimCorrect}
                                disabled={busy}
                                className="min-h-11 rounded-xl text-sm font-semibold text-primary underline-offset-4 hover:underline disabled:opacity-50"
                            >
                                I was right
                            </button>
                        )}
                    <button
                        ref={nextButton}
                        type="button"
                        onClick={next}
                        disabled={busy}
                        className="min-h-14 rounded-2xl bg-primary text-lg font-bold text-primary-foreground shadow-[0_4px_0_color-mix(in_oklab,var(--primary)_60%,black)] transition-transform focus-visible:ring-4 focus-visible:ring-ring/40 focus-visible:outline-none active:translate-y-0.5 active:shadow-none disabled:opacity-60"
                    >
                        Continue
                    </button>
                </div>
            )}
        </div>
    );
}

function ResultBanner({
    correct,
    overridden,
    expected,
}: {
    correct: boolean;
    overridden: boolean;
    expected: string[];
}) {
    return (
        <div
            role="status"
            className={cn(
                'flex items-center gap-3 rounded-2xl p-4',
                correct
                    ? 'animate-pop bg-correct-soft text-correct'
                    : 'animate-shake bg-wrong-soft text-wrong',
            )}
        >
            <span
                className={cn(
                    'flex size-10 shrink-0 items-center justify-center rounded-full text-white',
                    correct ? 'bg-correct' : 'bg-wrong',
                )}
            >
                {correct ? (
                    <Check className="size-6" />
                ) : (
                    <X className="size-6" />
                )}
            </span>
            <div className="min-w-0">
                <p className="text-lg font-bold">
                    {overridden
                        ? 'Counted as correct'
                        : correct
                          ? 'Correct!'
                          : 'Not quite'}
                </p>
                {!correct && expected.length > 0 && (
                    <p className="text-foreground">
                        Correct answer:{' '}
                        <span lang="ja" className="font-bold">
                            {expected.slice(0, 3).join(' / ')}
                        </span>
                    </p>
                )}
            </div>
        </div>
    );
}

function Choices({
    options,
    busy,
    onPick,
    chosen = null,
    right,
}: {
    options: string[];
    busy: boolean;
    onPick: (value: string) => void;
    chosen?: string | null;
    right?: string;
}) {
    const answered = right !== undefined;

    useEffect(() => {
        if (answered) {
            return;
        }
        const onKey = (event: KeyboardEvent) => {
            const n = Number(event.key);
            if (
                !event.repeat &&
                n >= 1 &&
                n <= options.length &&
                !event.metaKey &&
                !event.ctrlKey
            ) {
                onPick(options[n - 1]);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [answered, options, onPick]);

    return (
        <div className="grid grid-cols-2 gap-3">
            {options.map((option, i) => {
                const isRight = answered && option === right;
                const isWrongPick = answered && option === chosen && !isRight;

                return (
                    <button
                        key={option}
                        type="button"
                        disabled={busy}
                        onClick={() => onPick(option)}
                        className={cn(
                            'relative flex min-h-20 items-center justify-center rounded-2xl border-2 border-border bg-card px-3 py-3 text-center text-xl font-bold shadow-[0_3px_0_var(--border)] transition-[transform,border-color] focus-visible:ring-4 focus-visible:ring-ring/40 focus-visible:outline-none enabled:hover:border-primary enabled:active:translate-y-0.5 enabled:active:shadow-none sm:text-2xl',
                            isRight &&
                                'animate-pop border-correct bg-correct-soft text-correct',
                            isWrongPick &&
                                'border-wrong bg-wrong-soft text-wrong',
                            answered &&
                                !isRight &&
                                !isWrongPick &&
                                'opacity-50',
                        )}
                    >
                        <kbd className="absolute top-1.5 left-2.5 font-sans text-xs font-semibold text-muted-foreground">
                            {i + 1}
                        </kbd>
                        <span
                            lang={/[぀-ヿ一-鿿]/.test(option) ? 'ja' : undefined}
                        >
                            {option}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

function TypedAnswer({
    card,
    busy,
    onSubmit,
}: {
    card: Card;
    busy: boolean;
    onSubmit: (value: string) => void;
}) {
    const [value, setValue] = useState('');
    // kanji and vocab readings: romaji turns into kana while typing; kana items are answered in romaji
    const asKana =
        card.field === 'reading' &&
        card.item.kind !== 'hiragana' &&
        card.item.kind !== 'katakana';

    const send = (event: FormEvent) => {
        event.preventDefault();
        const answer = asKana ? toKana(value, true) : value;
        if (answer.trim() !== '') {
            onSubmit(answer.trim());
        }
    };

    return (
        <form onSubmit={send} className="flex flex-col gap-3">
            <label htmlFor="answer" className="sr-only">
                Answer
            </label>
            <input
                id="answer"
                autoFocus
                autoComplete="off"
                autoCapitalize="off"
                autoCorrect="off"
                spellCheck={false}
                enterKeyHint="send"
                lang={asKana ? 'ja' : 'en'}
                value={value}
                disabled={busy}
                onChange={(e) =>
                    setValue(asKana ? toKana(e.target.value) : e.target.value)
                }
                placeholder={
                    asKana
                        ? 'Type the reading, e.g. hana → はな'
                        : card.field === 'reading'
                          ? 'Reading in romaji, e.g. ne'
                          : 'Meaning in English'
                }
                className="min-h-16 w-full rounded-2xl border-2 border-input bg-card px-5 text-center text-2xl font-bold placeholder:text-base placeholder:font-normal placeholder:text-muted-foreground focus:border-primary focus:ring-4 focus:ring-ring/30 focus:outline-none"
            />
            <button
                type="submit"
                disabled={busy || value.trim() === ''}
                className="min-h-14 rounded-2xl bg-primary text-lg font-bold text-primary-foreground disabled:opacity-40"
            >
                Check
            </button>
        </form>
    );
}

function SummaryView({ summary }: { summary: Summary }) {
    const total = summary.correct + summary.wrong;

    return (
        <section className="flex flex-1 animate-rise flex-col items-center gap-8 text-center">
            <div>
                <p
                    lang="ja"
                    className="penlight text-6xl font-bold whitespace-nowrap sm:text-7xl"
                >
                    おつかれ！
                </p>
                <h1 className="mt-3 text-2xl font-bold">Lesson complete</h1>
            </div>

            <dl className="grid w-full grid-cols-3 gap-3">
                <Stat
                    label="correct"
                    value={summary.correct}
                    className="text-correct"
                />
                <Stat
                    label="wrong"
                    value={summary.wrong}
                    className="text-wrong"
                />
                <Stat
                    label="new"
                    value={summary.new_items}
                    className="text-primary"
                />
            </dl>

            <p className="flex items-center gap-2 text-lg">
                <Flame className="size-6 text-penlight" aria-hidden />
                <span>
                    <strong>{summary.streak_days}</strong> day streak
                </span>
            </p>
            {total > 0 && (
                <p className="text-muted-foreground">
                    {Math.round((summary.correct / total) * 100)}% of your
                    answers were correct.
                </p>
            )}

            <div className="mt-auto flex w-full flex-col gap-3">
                <Link
                    href={start()}
                    as="button"
                    className="min-h-14 rounded-2xl bg-primary text-lg font-bold text-primary-foreground"
                >
                    Next lesson
                </Link>
                <Link
                    href={dashboard()}
                    className="flex min-h-11 items-center justify-center rounded-xl font-semibold text-primary"
                >
                    Back home
                </Link>
            </div>
        </section>
    );
}

function Stat({
    label,
    value,
    className,
}: {
    label: string;
    value: number;
    className?: string;
}) {
    return (
        <div className="rounded-2xl bg-card p-4">
            <dd
                className={cn(
                    'text-4xl font-extrabold tabular-nums',
                    className,
                )}
            >
                {value}
            </dd>
            <dt className="text-sm text-muted-foreground">{label}</dt>
        </div>
    );
}

function NothingDueView({ due }: { due: NothingDue | null }) {
    return (
        <section className="flex flex-1 flex-col items-center justify-center gap-6 text-center">
            <p lang="ja" className="text-6xl font-bold text-primary">
                またね
            </p>
            <h1 className="text-2xl font-bold">Nothing to learn right now</h1>
            <div className="space-y-1 text-muted-foreground">
                {due?.next_due_at && (
                    <p>
                        Next review:{' '}
                        <strong className="text-foreground">
                            {formatDateTime(due.next_due_at)}
                        </strong>
                    </p>
                )}
                {due?.new_items_at && (
                    <p>
                        New characters from:{' '}
                        <strong className="text-foreground">
                            {formatDateTime(due.new_items_at)}
                        </strong>
                    </p>
                )}
                {!due?.next_due_at && !due?.new_items_at && (
                    <p>Come back later.</p>
                )}
            </div>
            <Link
                href={dashboard()}
                className="flex min-h-12 items-center rounded-2xl bg-primary px-6 font-bold text-primary-foreground"
            >
                Back home
            </Link>
        </section>
    );
}
