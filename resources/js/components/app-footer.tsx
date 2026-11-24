import { Link } from '@inertiajs/react';
import { privacy } from '@/routes';

// Notices required on every page (see PagesTest); string literals so formatting cannot split them.
const NOTICE = [
    'Inoffizielles Fanprojekt, nicht mit COVER Corp. verbunden.',
    'Inhalte © COVER Corp. / jeweilige Rechteinhaber.',
    'Wörterbuchdaten: JMdict, JMnedict, KANJIDIC2, KRADFILE © EDRDG (CC BY-SA 4.0).',
    'Daten: Holodex.',
].join(' ');

export function AppFooter() {
    return (
        <footer className="mx-auto mt-auto w-full max-w-3xl px-4 py-6 text-center text-xs leading-relaxed text-muted-foreground">
            <p>{NOTICE}</p>
            <p className="mt-2 flex justify-center gap-4">
                <Link
                    href={privacy()}
                    className="underline underline-offset-4 hover:text-foreground"
                >
                    Datenschutz
                </Link>
                <a
                    href="https://github.com/Bestora/holo-learn"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="underline underline-offset-4 hover:text-foreground"
                >
                    GitHub
                </a>
            </p>
        </footer>
    );
}
