import { Head, Link, usePage } from '@inertiajs/react';
import { AppFooter } from '@/components/app-footer';
import { dashboard, login } from '@/routes';

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Learn Japanese with hololive" />
            <div className="flex min-h-svh flex-col bg-background">
                <header className="mx-auto flex w-full max-w-4xl items-center justify-between px-4 pt-6">
                    <span className="text-lg font-extrabold">holo-learn</span>
                    <Link
                        href={auth.user ? dashboard() : login()}
                        className="flex min-h-11 items-center rounded-xl px-4 font-semibold text-primary hover:bg-accent"
                    >
                        {auth.user ? 'Home' : 'Log in'}
                    </Link>
                </header>

                <main className="mx-auto flex w-full max-w-4xl flex-1 flex-col justify-center gap-10 px-4 py-12">
                    {/* the idea in one picture: a real name, one character lit */}
                    <p
                        lang="ja"
                        aria-label="星街すいせい, with the character 星 glowing"
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
                            Learn Japanese from the names and songs of hololive.
                        </h1>
                        <p className="text-lg text-muted-foreground">
                            Kana, radicals, kanji and vocabulary, each shown in
                            a real talent name or song title. After every answer
                            you see the reading and hear the moment in the clip.
                        </p>
                        <p className="text-muted-foreground">
                            Sign-up is by invitation only.
                        </p>
                    </div>
                    <Link
                        href={auth.user ? dashboard() : login()}
                        className="flex min-h-14 w-full items-center justify-center rounded-2xl bg-primary px-10 text-lg font-bold text-primary-foreground sm:w-fit"
                    >
                        {auth.user ? 'Keep learning' : 'Log in'}
                    </Link>
                </main>

                <AppFooter />
            </div>
        </>
    );
}
