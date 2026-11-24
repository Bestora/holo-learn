import { Head, Link } from '@inertiajs/react';
import { AppFooter } from '@/components/app-footer';
import { home } from '@/routes';

export default function Privacy() {
    return (
        <>
            <Head title="Datenschutz" />
            <div className="flex min-h-svh flex-col bg-background">
                <header className="mx-auto w-full max-w-2xl px-4 pt-6">
                    <Link href={home()} className="text-lg font-extrabold">
                        holo-learn
                    </Link>
                </header>
                <main className="mx-auto w-full max-w-2xl px-4 py-8 [&_h2]:mt-8 [&_h2]:mb-2 [&_h2]:text-xl [&_h2]:font-bold [&_li]:mt-1 [&_p]:mt-2 [&_ul]:list-disc [&_ul]:pl-5">
                    <h1 className="text-3xl font-extrabold">Datenschutz</h1>
                    <p>
                        holo-learn ist ein privates, nicht kommerzielles
                        Fanprojekt für ein paar Leute. Hier steht, welche Daten
                        anfallen und wohin sie gehen.
                    </p>

                    <h2>Was gespeichert wird</h2>
                    <ul>
                        <li>
                            Konto: Name, E-Mail-Adresse, Passwort (nur als
                            Hash), auf Wunsch Passkeys und Zwei-Faktor-Daten.
                        </li>
                        <li>
                            Lernstand: welche Zeichen und Wörter du gelernt
                            hast, deine Antworten der laufenden Lektion,
                            Wiederholungstermine, Tage in Folge, Favoriten und
                            Einstellungen.
                        </li>
                        <li>
                            Ein Sitzungs-Cookie, damit du angemeldet bleibst. Es
                            gibt keine Werbe- oder Analyse-Cookies.
                        </li>
                    </ul>
                    <p>
                        Die Daten liegen auf dem Server dieses Projekts und
                        werden nicht weitergegeben. Wenn du dein Konto in den
                        Einstellungen löschst, wird auch dein Lernstand
                        gelöscht.
                    </p>

                    <h2>YouTube</h2>
                    <p>
                        Die Seite zeigt kurze Ausschnitte aus YouTube-Videos:
                        nach jeder Antwort und auf den Vorstellungskarten neuer
                        Zeichen. Erst wenn ein Ausschnitt gezeigt wird, lädt
                        dein Browser das Player-Skript von youtube.com und den
                        Player selbst von youtube-nocookie.com. Dabei erhält
                        Google unter anderem deine IP-Adresse. Auf einer
                        Fragekarte vor der Antwort wird nichts von YouTube
                        geladen.
                    </p>
                    <p>
                        Die Fotos der Talente lädt dein Browser direkt von
                        Googles Bildservern (yt3.ggpht.com), auch schon auf
                        Fragekarten. Sie werden nicht auf diesem Server
                        gespeichert.
                    </p>
                    <p>
                        Meldet der Player, dass ein Video fehlt, fragt der
                        Server bei YouTube nach, ob es das Video noch gibt.
                        Dabei werden keine Daten über dich übertragen.
                    </p>

                    <h2>Holodex</h2>
                    <p>
                        Talente, Songs und Videos stammen aus der öffentlichen
                        Holodex-API. Der Server fragt sie dort ab; dein Browser
                        hat dabei keinen Kontakt zu Holodex, und es werden keine
                        Daten über dich an Holodex geschickt.
                    </p>

                    <h2>Kein Tracking</h2>
                    <p>
                        Es gibt keine Analyse-Werkzeuge, keine Werbung und keine
                        Profilbildung.
                    </p>

                    <h2>Kontakt</h2>
                    <p>
                        Fragen und Wünsche zu deinen Daten bitte als Issue im{' '}
                        <a
                            href="https://github.com/Bestora/holo-learn"
                            className="text-primary underline underline-offset-4"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            GitHub-Repository
                        </a>
                        .
                    </p>
                </main>
                <AppFooter />
            </div>
        </>
    );
}
