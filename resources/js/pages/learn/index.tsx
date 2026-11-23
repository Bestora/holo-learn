import { Head } from '@inertiajs/react';

// Placeholder until the learning UI exists: shows the raw props.
export default function Learn(props: Record<string, unknown>) {
    return (
        <>
            <Head title="Lernen" />
            <div className="p-4">
                <h1 className="mb-4 text-xl font-semibold">Lernen</h1>
                <pre className="overflow-x-auto text-xs">{JSON.stringify(props, null, 2)}</pre>
            </div>
        </>
    );
}
