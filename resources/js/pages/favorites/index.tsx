import { Head, router } from '@inertiajs/react';
import { Heart, Search } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';
import { index, update } from '@/routes/favorites';

type FavoriteTalent = {
    id: number;
    name_ja: string;
    name_en: string;
    photo_url: string | null;
    group_name: string | null;
    is_favorite: boolean;
};

function serverSelection(talents: FavoriteTalent[]): Set<number> {
    return new Set(talents.filter((t) => t.is_favorite).map((t) => t.id));
}

export default function Favorites({ talents }: { talents: FavoriteTalent[] }) {
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState(() => serverSelection(talents));

    // one PUT at a time; taps during a save queue the latest selection
    const saving = useRef(false);
    const queued = useRef<Set<number> | null>(null);

    const save = (ids: Set<number>) => {
        saving.current = true;
        const restore = () => {
            queued.current = null;
            setSelected(serverSelection(talents));
            toast.error('Could not save your favorites.');
        };
        router.put(
            update.url(),
            { talent_ids: [...ids] },
            {
                preserveScroll: true,
                preserveState: true,
                onError: restore,
                onHttpException: () => {
                    restore();

                    return false;
                },
                onNetworkError: () => {
                    restore();

                    return false;
                },
                onFinish: () => {
                    saving.current = false;
                    const next = queued.current;
                    queued.current = null;
                    if (next) {
                        save(next);
                    }
                },
            },
        );
    };

    const toggle = (id: number) => {
        const next = new Set(selected);
        if (next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }
        setSelected(next);
        if (saving.current) {
            queued.current = next;
        } else {
            save(next);
        }
    };

    const q = query.trim().toLowerCase();
    const shown = talents.filter(
        (t) =>
            !q ||
            t.name_en.toLowerCase().includes(q) ||
            t.name_ja.includes(q) ||
            (t.group_name ?? '').toLowerCase().includes(q),
    );
    // groups in the order of their first talent (the list comes sorted by priority)
    const groups = new Map<string, FavoriteTalent[]>();
    for (const t of shown) {
        const key = t.group_name ?? 'Other';
        groups.set(key, [...(groups.get(key) ?? []), t]);
    }

    return (
        <>
            <Head title="Favorites" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 py-6 sm:py-10">
                <header className="flex flex-col gap-2">
                    <h1 className="text-3xl font-extrabold">Your favorites</h1>
                    <p className="max-w-prose text-muted-foreground">
                        References of your favorites show up more often in
                        lessons. Every tap saves right away.
                    </p>
                </header>

                <div className="sticky top-0 z-10 -mx-4 bg-background/90 px-4 py-2 backdrop-blur">
                    <label className="relative block">
                        <span className="sr-only">Search talents</span>
                        <Search
                            className="absolute top-1/2 left-4 size-5 -translate-y-1/2 text-muted-foreground"
                            aria-hidden
                        />
                        <input
                            type="search"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search by name or group"
                            className="min-h-12 w-full rounded-2xl border-2 border-input bg-card pr-4 pl-12 text-base focus:border-primary focus:ring-4 focus:ring-ring/30 focus:outline-none"
                        />
                    </label>
                    <p
                        className="mt-2 text-sm text-muted-foreground"
                        aria-live="polite"
                    >
                        {selected.size === 0
                            ? 'No favorites yet.'
                            : `${selected.size} ${selected.size === 1 ? 'favorite' : 'favorites'} selected.`}
                    </p>
                </div>

                {[...groups].map(([group, list]) => (
                    <section key={group} className="flex flex-col gap-3">
                        <h2 className="text-lg font-bold">{group}</h2>
                        <ul className="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                            {list.map((t) => {
                                const on = selected.has(t.id);

                                return (
                                    <li key={t.id}>
                                        <button
                                            type="button"
                                            aria-pressed={on}
                                            onClick={() => toggle(t.id)}
                                            className={cn(
                                                'group flex w-full flex-col items-center gap-2 rounded-2xl border-2 p-2 pb-3 text-center transition-colors focus-visible:ring-4 focus-visible:ring-ring/40 focus-visible:outline-none',
                                                on
                                                    ? 'border-penlight bg-penlight-soft'
                                                    : 'border-transparent bg-card hover:border-border',
                                            )}
                                        >
                                            <span className="relative">
                                                {t.photo_url ? (
                                                    <img
                                                        src={t.photo_url}
                                                        alt=""
                                                        loading="lazy"
                                                        referrerPolicy="no-referrer"
                                                        className={cn(
                                                            'size-16 rounded-full bg-muted object-cover sm:size-20',
                                                            !on &&
                                                                'grayscale-[35%]',
                                                        )}
                                                    />
                                                ) : (
                                                    <span className="block size-16 rounded-full bg-muted sm:size-20" />
                                                )}
                                                {on && (
                                                    <Heart
                                                        className="absolute -right-1 -bottom-1 size-7 animate-pop rounded-full bg-background fill-penlight p-1 text-penlight"
                                                        aria-hidden
                                                    />
                                                )}
                                            </span>
                                            <span className="text-sm leading-tight font-semibold">
                                                {t.name_en}
                                            </span>
                                            <span
                                                lang="ja"
                                                className="text-xs leading-tight text-muted-foreground"
                                            >
                                                {t.name_ja}
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                ))}
                {shown.length === 0 && (
                    <p className="text-muted-foreground">
                        No talent matches “{query}”.
                    </p>
                )}
            </div>
        </>
    );
}

Favorites.layout = {
    breadcrumbs: [{ title: 'Favorites', href: index() }],
};
