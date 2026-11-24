import { Head, Link, usePage } from '@inertiajs/react';
import { AppFooter } from '@/components/app-footer';
import { dashboard, login } from '@/routes';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Japanisch lernen mit hololive" />
            <div className="flex min-h-svh flex-col bg-background">
                <header className="mx-auto flex w-full max-w-4xl items-center justify-between px-4 pt-6">
                    <span className="text-lg font-extrabold">holo-learn</span>
                    <Link
                        href={auth.user ? dashboard() : login()}
                        className="flex min-h-11 items-center rounded-xl px-4 font-semibold text-primary hover:bg-accent"
                    >
                        {auth.user ? 'Zur Startseite' : 'Anmelden'}
                    </Link>
                </header>

                <main className="mx-auto flex w-full max-w-4xl flex-1 flex-col justify-center gap-10 px-4 py-12">
                    {/* the idea in one picture: a real name, one character lit */}
                    <p
                        lang="ja"
                        aria-label="星街すいせい, das Zeichen 星 leuchtet"
                        className="text-6xl leading-tight font-bold sm:text-8xl"
                    >
                        <ruby>
                            <span className="penlight">星</span>街
                            <rt className="font-bold text-muted-foreground">
                                ほしまち
                            </rt>
                        </ruby>
                        すいせい
                    </p>
                    <div className="flex max-w-xl flex-col gap-4">
                        <h1 className="text-3xl font-extrabold sm:text-4xl">
                            Japanisch lernen mit den Namen und Songs von
                            hololive.
                        </h1>
                        <p className="text-lg text-muted-foreground">
                            Kana, Radikale, Kanji und Vokabeln, jeweils an einem
                            echten Talentnamen oder Songtitel. Nach jeder
                            Antwort siehst du die Lesung und hörst die Stelle im
                            Clip.
                        </p>
                        <p className="text-muted-foreground">
                            Anmeldung nur mit Einladung.
                        </p>
                    </div>
                    <Link
                        href={auth.user ? dashboard() : login()}
                        className="flex min-h-14 w-full items-center justify-center rounded-2xl bg-primary px-10 text-lg font-bold text-primary-foreground sm:w-fit"
                    >
                        {auth.user ? 'Weiterlernen' : 'Anmelden'}
                    </Link>
                </main>

                <AppFooter />
            </div>
        </>
    );
}
