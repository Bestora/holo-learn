export function AppFooter() {
    return (
        <footer className="mt-auto flex flex-wrap justify-center gap-x-4 gap-y-1 p-4 text-center text-xs text-muted-foreground">
            <a
                href="https://holodex.net"
                target="_blank"
                rel="noopener noreferrer"
                className="underline underline-offset-4 hover:text-foreground"
            >
                Daten von Holodex
            </a>
            <span>Wörterbuchdaten: EDRDG</span>
            <span>Fan-Projekt, nicht mit COVER Corp. verbunden</span>
        </footer>
    );
}
