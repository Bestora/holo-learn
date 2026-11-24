import { Head, Link } from '@inertiajs/react';
import { AppFooter } from '@/components/app-footer';
import { home } from '@/routes';

export default function Privacy() {
    return (
        <>
            <Head title="Privacy" />
            <div className="flex min-h-svh flex-col bg-background">
                <header className="mx-auto w-full max-w-2xl px-4 pt-6">
                    <Link href={home()} className="text-lg font-extrabold">
                        holo-learn
                    </Link>
                </header>
                <main className="mx-auto w-full max-w-2xl px-4 py-8 [&_h2]:mt-8 [&_h2]:mb-2 [&_h2]:text-xl [&_h2]:font-bold [&_li]:mt-1 [&_p]:mt-2 [&_ul]:list-disc [&_ul]:pl-5">
                    <h1 className="text-3xl font-extrabold">Privacy</h1>
                    <p>
                        holo-learn is a private, non-commercial fan project for
                        a few people. This page lists what data comes up and
                        where it goes.
                    </p>

                    <h2>What is stored</h2>
                    <ul>
                        <li>
                            Account: name, email address, password (as a hash
                            only), and passkeys or two-factor data if you set
                            them up.
                        </li>
                        <li>
                            Learning progress: which characters and words you
                            have learned, your answers in the current lesson,
                            review dates, your streak, favorites and settings.
                        </li>
                        <li>
                            A session cookie that keeps you signed in. There are
                            no advertising or analytics cookies.
                        </li>
                    </ul>
                    <p>
                        The data stays on this project&apos;s server and is not
                        passed on. Deleting your account in the settings also
                        deletes your learning progress.
                    </p>

                    <h2>YouTube</h2>
                    <p>
                        The app shows short clips from YouTube videos: after
                        each answer and on the introduction cards of new
                        characters. Only when a clip is shown does your browser
                        load the player script from youtube.com and the player
                        itself from youtube-nocookie.com. Google receives your
                        IP address, among other things. Nothing from YouTube is
                        loaded on a question card before you answer.
                    </p>
                    <p>
                        Your browser loads talent photos directly from
                        Google&apos;s image servers (yt3.ggpht.com), on question
                        cards too. They are not stored on this server.
                    </p>
                    <p>
                        If the player reports a missing video, the server asks
                        YouTube whether the video still exists. No data about
                        you is sent.
                    </p>

                    <h2>Holodex</h2>
                    <p>
                        Talents, songs and videos come from the public Holodex
                        API. The server fetches them; your browser never
                        contacts Holodex, and no data about you is sent there.
                    </p>

                    <h2>No tracking</h2>
                    <p>
                        There are no analytics tools, no ads and no profiling.
                    </p>

                    <h2>Contact</h2>
                    <p>
                        For questions or requests about your data, please open
                        an issue in the{' '}
                        <a
                            href="https://github.com/Bestora/holo-learn"
                            className="text-primary underline underline-offset-4"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            GitHub repository
                        </a>
                        .
                    </p>
                </main>
                <AppFooter />
            </div>
        </>
    );
}
