import { useEffect, useRef } from 'react';

// Minimal typing of the YouTube IFrame Player API we use.
type YTPlayer = { destroy(): void };
type YTNamespace = {
    Player: new (
        el: HTMLElement,
        options: {
            host?: string;
            videoId: string;
            width?: string;
            height?: string;
            playerVars?: Record<string, number | string>;
            events?: { onError?: (event: { data: number }) => void };
        },
    ) => YTPlayer;
};

declare global {
    interface Window {
        YT?: YTNamespace;
        onYouTubeIframeAPIReady?: () => void;
    }
}

let apiPromise: Promise<YTNamespace> | null = null;

function loadApi(): Promise<YTNamespace> {
    apiPromise ??= new Promise((resolve) => {
        if (window.YT?.Player) {
            resolve(window.YT);
            return;
        }
        const previous = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = () => {
            previous?.();
            resolve(window.YT!);
        };
        const script = document.createElement('script');
        script.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(script);
    });

    return apiPromise;
}

/** Codes for a removed, private or not embeddable video. */
const DEAD_CODES = [100, 101, 150];

/**
 * Plays `videoId` from `start` to `end` seconds. Mount it only after the answer: the sound would
 * give the answer away. Calls `onDead` when YouTube reports the video gone or not embeddable.
 */
export default function YoutubeClip({
    videoId,
    start,
    end,
    title,
    onDead,
}: {
    videoId: string;
    start: number | null;
    end: number | null;
    title: string;
    onDead: () => void;
}) {
    const target = useRef<HTMLDivElement>(null);
    const deadRef = useRef(onDead);

    useEffect(() => {
        deadRef.current = onDead;
    });

    useEffect(() => {
        const host = target.current;
        let player: YTPlayer | null = null;
        let cancelled = false;

        void loadApi().then((YT) => {
            if (cancelled || !host) {
                return;
            }
            // the API replaces this element with its iframe; React never owns it
            const el = document.createElement('div');
            host.appendChild(el);
            const vars: Record<string, number> = {
                autoplay: 1,
                playsinline: 1,
                rel: 0,
            };
            if (start !== null) {
                vars.start = start;
            }
            if (end !== null) {
                vars.end = end;
            }
            player = new YT.Player(el, {
                host: 'https://www.youtube-nocookie.com',
                videoId,
                width: '100%',
                height: '100%',
                playerVars: vars,
                events: {
                    onError: (event) => {
                        if (DEAD_CODES.includes(event.data)) {
                            deadRef.current();
                        }
                    },
                },
            });
        });

        return () => {
            cancelled = true;
            player?.destroy();
            host?.replaceChildren();
        };
    }, [videoId, start, end]);

    return (
        <div
            ref={target}
            className="aspect-video w-full overflow-hidden rounded-2xl bg-black [&>iframe]:size-full"
            title={title}
        />
    );
}
