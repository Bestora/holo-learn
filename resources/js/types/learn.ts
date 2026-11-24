// Props of GET /learn and the POST /learn/answer response (see task-9b-report §2, §8.5, §9.15).

export type ItemKind = 'hiragana' | 'katakana' | 'radical' | 'kanji' | 'vocab';

export type Talent = {
    name_ja?: string;
    name_en?: string | null;
    photo_url: string | null;
};

export type RevealWord = {
    surface: string;
    reading: string;
    romaji: string;
    ok: boolean;
};

export type Occurrence = {
    id: number;
    video_id: string | null;
    start_s: number | null;
    end_s: number | null;
    talent: Talent | null;
};

export type Reveal = {
    item: {
        readings: string[];
        meanings: string[];
        level: number;
        is_special: boolean;
    };
    reading: string | null;
    romaji: string | null;
    meaning: string | null;
    alt_text: string | null;
    alt_kind: 'romaji' | 'translation' | 'none' | null;
    is_name: boolean;
    words: RevealWord[];
    context_reading: string | null;
    occurrence: Occurrence | null;
};

export type Reference = {
    id: number;
    kind: 'name' | 'song';
    text_ja: string;
    highlight: number[];
    word: number[];
};

export type Card = {
    type: 'intro' | 'quiz';
    item: {
        id: number;
        kind: ItemKind;
        characters: string;
        is_special: boolean;
    };
    reference: Reference | null;
    talent: Talent | null;
    field: 'reading' | 'meaning';
    mode: 'choice' | 'typed';
    options: string[] | null;
    remaining_references: number;
    is_name_reading: boolean;
    reveal?: Reveal;
};

export type Lesson = {
    id: number;
    size: number;
    position: number;
    correct: number;
    wrong: number;
};

export type Summary = {
    correct: number;
    wrong: number;
    new_items: number;
    streak_days: number;
};

export type NothingDue = {
    next_due_at: string | null;
    new_items_at: string | null;
};

export type LearnProps = {
    lesson: Lesson | null;
    card: Card | null;
    summary: Summary | null;
    nothing_due: NothingDue | null;
};

export type AnswerResult = {
    correct: boolean;
    expected: string[];
    reveal: Reveal;
    next_due_at: string | null;
    can_override: boolean;
};

export const KIND_LABEL: Record<ItemKind, string> = {
    hiragana: 'Hiragana',
    katakana: 'Katakana',
    radical: 'Radical',
    kanji: 'Kanji',
    vocab: 'Vocabulary',
};
