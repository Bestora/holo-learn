import type { SVGAttributes } from 'react';

/** The kanji 学 ("learn") as the app mark. */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 40 40"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden
        >
            <text
                x="20"
                y="31"
                textAnchor="middle"
                fontSize="30"
                fontWeight="700"
                fill="currentColor"
                style={{ fontFamily: 'var(--font-jp)' }}
            >
                学
            </text>
        </svg>
    );
}
