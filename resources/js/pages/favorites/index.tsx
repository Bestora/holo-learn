import { Head } from '@inertiajs/react';

// Placeholder until the favorites UI exists: shows the raw props.
export default function Favorites(props: Record<string, unknown>) {
    return (
        <>
            <Head title="Favoriten" />
            <div className="p-4">
                <h1 className="mb-4 text-xl font-semibold">Favoriten</h1>
                <pre className="overflow-x-auto text-xs">{JSON.stringify(props, null, 2)}</pre>
            </div>
        </>
    );
}
