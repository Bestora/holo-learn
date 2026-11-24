import { Head, Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { rejected } from '@/routes/admin';

type Row = {
    id: number;
    kind: 'name' | 'song';
    text_ja: string;
    alt_text: string | null;
    reading_kana: string;
    meaning_en: string;
    words: { surface: string; reading: string; ok?: boolean }[];
    check_errors: string[];
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};

export default function Rejected({
    annotations,
}: {
    annotations: Paginated<Row>;
}) {
    return (
        <>
            <Head title="Rejected annotations" />
            <div className="flex w-full flex-col gap-4 px-4 py-6">
                <header>
                    <h1 className="text-2xl font-extrabold">
                        Rejected annotations
                    </h1>
                    <p className="text-muted-foreground">
                        {annotations.total} references that failed the check.
                    </p>
                </header>

                <div className="overflow-x-auto rounded-2xl border border-border bg-card">
                    <table className="w-full min-w-[44rem] text-left text-sm">
                        <thead className="border-b border-border text-muted-foreground">
                            <tr>
                                <th className="p-3 font-semibold">Text</th>
                                <th className="p-3 font-semibold">
                                    Alt (Holodex)
                                </th>
                                <th className="p-3 font-semibold">Words</th>
                                <th className="p-3 font-semibold">Errors</th>
                            </tr>
                        </thead>
                        <tbody>
                            {annotations.data.map((row) => (
                                <tr
                                    key={row.id}
                                    className="border-b border-border/60 align-top last:border-0"
                                >
                                    <td className="p-3">
                                        <p
                                            lang="ja"
                                            className="text-base font-bold"
                                        >
                                            {row.text_ja}
                                        </p>
                                        <p
                                            lang="ja"
                                            className="text-muted-foreground"
                                        >
                                            {row.reading_kana}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {row.kind === 'name'
                                                ? 'Name'
                                                : 'Song'}
                                        </p>
                                    </td>
                                    <td className="p-3">
                                        {row.alt_text ?? '–'}
                                        <p className="text-xs text-muted-foreground">
                                            {row.meaning_en}
                                        </p>
                                    </td>
                                    <td className="p-3" lang="ja">
                                        {row.words.map((w, i) => (
                                            <span
                                                key={i}
                                                className={cn(
                                                    'mr-2 inline-block',
                                                    w.ok === false &&
                                                        'text-wrong',
                                                )}
                                            >
                                                {w.surface}
                                                <span className="text-muted-foreground">
                                                    （{w.reading}）
                                                </span>
                                            </span>
                                        ))}
                                    </td>
                                    <td className="p-3">
                                        {row.check_errors.map((code) => (
                                            <code
                                                key={code}
                                                className="mr-1 mb-1 inline-block rounded bg-wrong-soft px-1.5 py-0.5 text-xs text-wrong"
                                            >
                                                {code}
                                            </code>
                                        ))}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {annotations.links.length > 3 && (
                    <nav className="flex flex-wrap gap-1" aria-label="Pages">
                        {annotations.links.map((link, i) =>
                            link.url ? (
                                <Link
                                    key={i}
                                    href={link.url}
                                    preserveScroll
                                    className={cn(
                                        'min-w-10 rounded-lg px-3 py-2 text-center text-sm',
                                        link.active
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-card hover:bg-accent',
                                    )}
                                >
                                    {pageLabel(link.label)}
                                </Link>
                            ) : (
                                <span
                                    key={i}
                                    className="min-w-10 px-3 py-2 text-center text-sm text-muted-foreground"
                                >
                                    {pageLabel(link.label)}
                                </span>
                            ),
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}

// the paginator sends "&laquo; Previous" / "Next &raquo;" labels
function pageLabel(label: string): string {
    if (label.includes('Previous') || label.includes('laquo')) {
        return 'Previous';
    }
    if (label.includes('Next') || label.includes('raquo')) {
        return 'Next';
    }

    return label;
}

Rejected.layout = {
    breadcrumbs: [{ title: 'Rejected', href: rejected() }],
};
