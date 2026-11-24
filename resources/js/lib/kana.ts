// Romaji → hiragana while typing (IME-like), and word alignment for the reveal.
// ponytail: small Hepburn/Kunrei table, no IME edge cases beyond these; the server also accepts romaji.

const TABLE: Record<string, string> = {
    a: 'あ',
    i: 'い',
    u: 'う',
    e: 'え',
    o: 'お',
    ka: 'か',
    ki: 'き',
    ku: 'く',
    ke: 'け',
    ko: 'こ',
    ga: 'が',
    gi: 'ぎ',
    gu: 'ぐ',
    ge: 'げ',
    go: 'ご',
    sa: 'さ',
    shi: 'し',
    si: 'し',
    su: 'す',
    se: 'せ',
    so: 'そ',
    za: 'ざ',
    ji: 'じ',
    zi: 'じ',
    zu: 'ず',
    ze: 'ぜ',
    zo: 'ぞ',
    ta: 'た',
    chi: 'ち',
    ti: 'ち',
    tsu: 'つ',
    tu: 'つ',
    te: 'て',
    to: 'と',
    da: 'だ',
    di: 'ぢ',
    du: 'づ',
    de: 'で',
    do: 'ど',
    na: 'な',
    ni: 'に',
    nu: 'ぬ',
    ne: 'ね',
    no: 'の',
    ha: 'は',
    hi: 'ひ',
    fu: 'ふ',
    hu: 'ふ',
    he: 'へ',
    ho: 'ほ',
    ba: 'ば',
    bi: 'び',
    bu: 'ぶ',
    be: 'べ',
    bo: 'ぼ',
    pa: 'ぱ',
    pi: 'ぴ',
    pu: 'ぷ',
    pe: 'ぺ',
    po: 'ぽ',
    ma: 'ま',
    mi: 'み',
    mu: 'む',
    me: 'め',
    mo: 'も',
    ya: 'や',
    yu: 'ゆ',
    yo: 'よ',
    ra: 'ら',
    ri: 'り',
    ru: 'る',
    re: 'れ',
    ro: 'ろ',
    wa: 'わ',
    wo: 'を',
    wi: 'うぃ',
    we: 'うぇ',
    kya: 'きゃ',
    kyu: 'きゅ',
    kyo: 'きょ',
    gya: 'ぎゃ',
    gyu: 'ぎゅ',
    gyo: 'ぎょ',
    sha: 'しゃ',
    shu: 'しゅ',
    sho: 'しょ',
    she: 'しぇ',
    sya: 'しゃ',
    syu: 'しゅ',
    syo: 'しょ',
    ja: 'じゃ',
    ju: 'じゅ',
    jo: 'じょ',
    je: 'じぇ',
    jya: 'じゃ',
    jyu: 'じゅ',
    jyo: 'じょ',
    zya: 'じゃ',
    zyu: 'じゅ',
    zyo: 'じょ',
    cha: 'ちゃ',
    chu: 'ちゅ',
    cho: 'ちょ',
    che: 'ちぇ',
    tya: 'ちゃ',
    tyu: 'ちゅ',
    tyo: 'ちょ',
    nya: 'にゃ',
    nyu: 'にゅ',
    nyo: 'にょ',
    hya: 'ひゃ',
    hyu: 'ひゅ',
    hyo: 'ひょ',
    bya: 'びゃ',
    byu: 'びゅ',
    byo: 'びょ',
    pya: 'ぴゃ',
    pyu: 'ぴゅ',
    pyo: 'ぴょ',
    mya: 'みゃ',
    myu: 'みゅ',
    myo: 'みょ',
    rya: 'りゃ',
    ryu: 'りゅ',
    ryo: 'りょ',
    fa: 'ふぁ',
    fi: 'ふぃ',
    fe: 'ふぇ',
    fo: 'ふぉ',
    thi: 'てぃ',
    dhi: 'でぃ',
    tsa: 'つぁ',
    xa: 'ぁ',
    xi: 'ぃ',
    xu: 'ぅ',
    xe: 'ぇ',
    xo: 'ぉ',
    la: 'ぁ',
    li: 'ぃ',
    lu: 'ぅ',
    le: 'ぇ',
    lo: 'ぉ',
    xya: 'ゃ',
    xyu: 'ゅ',
    xyo: 'ょ',
    lya: 'ゃ',
    lyu: 'ゅ',
    lyo: 'ょ',
    xtu: 'っ',
    ltu: 'っ',
    xtsu: 'っ',
};

/**
 * Converts the romaji in `input` to hiragana; kana already in it stays. An unfinished syllable
 * stays Latin until it is complete. `final` also turns a trailing "n" into ん.
 */
export function toKana(input: string, final = false): string {
    const s = input.toLowerCase();
    let out = '';
    let i = 0;

    while (i < s.length) {
        const c = s[i];
        const next = s[i + 1];

        if (c === '-') {
            out += 'ー';
            i++;
            continue;
        }
        if (!/[a-z]/.test(c)) {
            out += c;
            i++;
            continue;
        }
        if (c === 'n') {
            if (next === undefined) {
                out += final ? 'ん' : 'n';
                i++;
                continue;
            }
            if (next === "'" || (next === 'n' && i + 2 === s.length)) {
                out += 'ん';
                i += 2;
                continue;
            }
            if (!/[aiueoy]/.test(next)) {
                out += 'ん';
                i++;
                continue;
            }
        }
        if (
            (next === c && /[bcdfghjkmpqrstvwxz]/.test(c)) ||
            (c === 't' && next === 'c')
        ) {
            out += 'っ';
            i++;
            continue;
        }

        const len = [4, 3, 2, 1].find((l) => TABLE[s.slice(i, i + l)]);
        if (len) {
            out += TABLE[s.slice(i, i + len)];
            i += len;
        } else {
            out += c;
            i++;
        }
    }

    return out;
}

export function hasKanji(text: string): boolean {
    return /[㐀-䶿一-鿿豈-﫿々〆ヶ]/u.test(text);
}

/** Letters, marks and digits only, NFKC, lowercased: as App\Annotations\Verifier::normalize. */
function normalize(text: string): string {
    return text
        .normalize('NFKC')
        .toLowerCase()
        .replace(/[^\p{L}\p{M}\p{N}]+/gu, '');
}

/**
 * Character positions (code points of `text`) each word covers, as Verifier::align does on the
 * server; null when the words do not spell the text.
 */
export function alignWords(
    text: string,
    surfaces: string[],
): number[][] | null {
    const target: [string, number][] = [];
    Array.from(text).forEach((char, position) => {
        for (const c of Array.from(normalize(char))) {
            target.push([c, position]);
        }
    });

    let t = 0;
    const aligned: number[][] = [];
    for (const surface of surfaces) {
        const positions: number[] = [];
        for (const c of Array.from(normalize(surface))) {
            if (target[t]?.[0] !== c) {
                return null;
            }
            positions.push(target[t++][1]);
        }
        aligned.push([...new Set(positions)]);
    }

    return t === target.length ? aligned : null;
}
