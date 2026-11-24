import { Head, Link } from '@inertiajs/react';
import { Flame } from 'lucide-react';
import { dashboard } from '@/routes';
import { start } from '@/routes/learn';
import type { ItemKind } from '@/types/learn';
import { KIND_LABEL } from '@/types/learn';

type Stats = {
    streak_days: number;
    due_count: number;
    learned: Record<ItemKind, number>;
    available: Record<ItemKind, number>;
};

// one typical character per kind, as the row's emblem
const KINDS: [ItemKind, string][] = [
    ['hiragana', 'あ'],
    ['katakana', 'ア'],
    ['radical', '亻'],
    ['kanji', '星'],
    ['vocab', '歌'],
];

export default function Dashboard({ stats }: { stats: Stats }) {
    return (
        <>
            <Head title="Home" />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 py-6 sm:py-10">
                <section className="relative overflow-hidden rounded-3xl bg-primary p-6 text-primary-foreground sm:p-10">
                    <span
                        lang="ja"
                        aria-hidden
                        className="pointer-events-none absolute -top-6 -right-2 text-[9rem] leading-none font-bold opacity-15 select-none sm:text-[12rem]"
                    >
                        学
                    </span>
                    <div className="relative flex flex-col gap-6">
                        <div className="flex flex-wrap items-end gap-x-8 gap-y-3">
                            <p className="flex items-center gap-2">
                                <Flame
                                    className="size-8 text-penlight"
                                    aria-hidden
                                />
                                <span className="text-5xl font-extrabold tabular-nums">
                                    {stats.streak_days}
                                </span>
                                <span className="text-lg leading-tight">
                                    day
                                    <br />
                                    streak
                                </span>
                            </p>
                            <p className="text-lg">
                                {stats.due_count > 0 ? (
                                    <>
                                        <strong className="text-2xl tabular-nums">
                                            {stats.due_count}
                                        </strong>{' '}
                                        {stats.due_count === 1
                                            ? 'review waiting'
                                            : 'reviews waiting'}
                                    </>
                                ) : (
                                    'No reviews due'
                                )}
                            </p>
                        </div>
                        <Link
                            href={start()}
                            as="button"
                            className="min-h-16 w-full rounded-2xl bg-background text-xl font-extrabold text-foreground shadow-[0_5px_0_color-mix(in_oklab,var(--primary)_55%,black)] transition-transform focus-visible:ring-4 focus-visible:ring-penlight focus-visible:outline-none active:translate-y-1 active:shadow-none sm:w-auto sm:px-12"
                        >
                            Start lesson
                        </Link>
                    </div>
                </section>

                <section
                    aria-labelledby="progress-heading"
                    className="flex flex-col gap-3"
                >
                    <h2 id="progress-heading" className="text-xl font-bold">
                        Your progress
                    </h2>
                    <ul className="flex flex-col gap-2">
                        {KINDS.map(([kind, glyph]) => {
                            const learned = stats.learned[kind] ?? 0;
                            const total = Math.max(
                                stats.available[kind] ?? 0,
                                learned,
                            );
                            const share = total ? (learned / total) * 100 : 0;

                            return (
                                <li
                                    key={kind}
                                    className="flex items-center gap-4 rounded-2xl bg-card px-4 py-3"
                                >
                                    <span
                                        lang="ja"
                                        aria-hidden
                                        className="w-12 text-center text-4xl font-bold text-primary"
                                    >
                                        {glyph}
                                    </span>
                                    <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                                        <div className="flex items-baseline justify-between gap-2">
                                            <span className="font-semibold">
                                                {KIND_LABEL[kind]}
                                            </span>
                                            <span className="text-sm text-muted-foreground tabular-nums">
                                                {learned} of {total} learned
                                            </span>
                                        </div>
                                        <div
                                            className="h-2.5 overflow-hidden rounded-full bg-muted"
                                            role="progressbar"
                                            aria-label={`${KIND_LABEL[kind]}: ${learned} of ${total}`}
                                            aria-valuenow={learned}
                                            aria-valuemin={0}
                                            aria-valuemax={total}
                                        >
                                            <div
                                                className="h-full rounded-full bg-sky"
                                                style={{ width: `${share}%` }}
                                            />
                                        </div>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                    <p className="text-sm text-muted-foreground">
                        “Of” counts everything that already has enough
                        references.
                    </p>
                </section>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Home', href: dashboard() }],
};
