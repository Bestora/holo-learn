<?php

namespace Tests\Feature;

use App\Annotations\Verifier;
use App\Models\Reference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VerifierTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAnnotations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDictionary();
    }

    private function hanaNiBourei(string $reading = 'はなにぼうれい'): Reference
    {
        return $this->annotated('song', '花に亡霊', $reading, [['花', 'はな'], ['に', 'に'], ['亡霊', 'ぼうれい']]);
    }

    public function test_correct_annotation_is_verified_and_marks_words(): void
    {
        $reference = $this->hanaNiBourei();

        $this->assertSame([], $this->verify($reference));

        $this->assertSame('verified', $reference->status);
        $this->assertNull($reference->annotation()->sole()->check_errors);
        $this->assertSame([true, true, true], $this->okFlags($reference));
        $this->assertSame(
            [['surface' => '花', 'reading' => 'はな', 'unit' => null, 'ok' => true], ['surface' => 'に', 'reading' => 'に', 'unit' => null, 'ok' => true], ['surface' => '亡霊', 'reading' => 'ぼうれい', 'unit' => null, 'ok' => true]],
            $reference->annotation()->sole()->words,
        );
    }

    public function test_special_reading_from_dictionary_is_ok(): void
    {
        $reference = $this->annotated('song', '今日', 'きょう', [['今日', 'きょう']]);

        $this->assertSame([], $this->verify($reference));
        $this->assertSame([true], $this->okFlags($reference));
    }

    public function test_every_dictionary_reading_is_acceptable(): void
    {
        $this->assertSame([], $this->verify($a = $this->annotated('song', '明日', 'あす', [['明日', 'あす']])));
        $this->assertSame([], $this->verify($b = $this->annotated('song', '明日へ', 'あしたへ', [['明日', 'あした'], ['へ', 'へ']])));
        $this->assertSame([true], $this->okFlags($a));
        $this->assertSame([true, true], $this->okFlags($b));
    }

    public function test_unknown_reading_is_verified_but_word_not_ok(): void
    {
        $reference = $this->annotated('song', '宇宙', 'そら', [['宇宙', 'そら']]);

        $this->assertSame([], $this->verify($reference));
        $this->assertSame('verified', $reference->status);
        $this->assertSame([false], $this->okFlags($reference));
    }

    public function test_reading_with_non_kana_is_rejected(): void
    {
        $reference = $this->hanaNiBourei('はなにボウレイx');

        $errors = $this->verify($reference);

        $this->assertContains('reading_not_kana', $errors);
        $this->assertSame('rejected', $reference->status);
        $this->assertSame($errors, $reference->annotation()->sole()->check_errors);
    }

    public function test_word_reading_with_non_kana_is_rejected(): void
    {
        $reference = $this->annotated('song', '花に亡霊', 'はなにぼうれい', [['花', 'はな'], ['に', 'に'], ['亡霊', '亡霊']]);

        $this->assertContains('reading_not_kana', $this->verify($reference));
    }

    public function test_surface_and_reading_mismatch(): void
    {
        $missing = $this->annotated('song', '花に亡霊', 'はなに', [['花', 'はな'], ['に', 'に']]);
        $this->assertSame(['surface_mismatch'], $this->verify($missing));

        $reading = $this->annotated('song', '花の亡霊', 'はなのぼうれい', [['花', 'はな'], ['の', 'の'], ['亡霊', 'ぼーれい']]);
        $this->assertSame(['reading_mismatch'], $this->verify($reading));
    }

    public function test_kana_word_must_read_as_itself(): void
    {
        $reference = $this->annotated('song', '花を', 'はなと', [['花', 'はな'], ['を', 'と']]);

        $this->assertSame(['kana_mismatch'], $this->verify($reference));
        $this->assertSame('rejected', $reference->status);
    }

    public function test_particles_and_long_vowel_mark_in_kana_words(): void
    {
        $reference = $this->annotated('song', '今日は花へ ラーメンを', 'きょうわはなえらあめんお', [
            ['今日', 'きょう'], ['は', 'わ'], ['花', 'はな'], ['へ', 'え'], ['ラーメン', 'らあめん'], ['を', 'お'],
        ]);

        $this->assertSame([], $this->verify($reference));
        $this->assertSame([true, true, true, true, true, true], $this->okFlags($reference));
    }

    public function test_latin_words_in_a_title_are_allowed_but_not_ok(): void
    {
        $reference = $this->annotated('song', 'Ahoy!! 我ら宝鐘海賊団☆', 'あほいわれらほうしょうかいぞくだん', [
            ['Ahoy', 'あほい'], ['我ら', 'われら'], ['宝鐘', 'ほうしょう'], ['海賊団', 'かいぞくだん'],
        ]);

        $this->assertSame([], $this->verify($reference));
        $this->assertSame([false, true, false, true], $this->okFlags($reference));
    }

    public function test_lemma_accepts_conjugated_form(): void
    {
        $reference = $this->annotated('song', '歌った', 'うたった', [['歌った', 'うたった', '歌う', 'うたう']]);

        $this->assertSame([], $this->verify($reference));
        $this->assertSame([true], $this->okFlags($reference));
    }

    public function test_lemma_with_changing_stem_is_not_ok(): void
    {
        $reference = $this->annotated('song', '来た', 'きた', [['来た', 'きた', '来る', 'くる']]);

        $this->assertSame([], $this->verify($reference));
        $this->assertSame([false], $this->okFlags($reference));
    }

    public function test_lemma_must_share_kanji_and_exist(): void
    {
        $otherKanji = $this->annotated('song', '唄った', 'うたった', [['唄った', 'うたった', '歌う', 'うたう']]);
        $unknownLemma = $this->annotated('song', '歌いて', 'うたいて', [['歌いて', 'うたいて', '歌く', 'うたく']]);

        $this->verify($otherKanji);
        $this->verify($unknownLemma);

        $this->assertSame([false], $this->okFlags($otherKanji));
        $this->assertSame([false], $this->okFlags($unknownLemma));
    }

    public function test_name_rows_do_not_count_for_songs(): void
    {
        $reference = $this->annotated('song', '小鳥遊', 'たかなし', [['小鳥遊', 'たかなし']]);

        $this->assertSame([], $this->verify($reference));
        $this->assertSame([false], $this->okFlags($reference));
    }

    public function test_name_with_romaji_match(): void
    {
        $reference = $this->annotated('name', '桃鈴ねね', 'ももすずねね', [['桃鈴', 'ももすず'], ['ねね', 'ねね']], 'Momosuzu Nene');

        $this->assertSame([], $this->verify($reference));
        $this->assertSame([true, true], $this->okFlags($reference));
    }

    public function test_name_reading_against_romaji_is_rejected_even_if_in_dictionary(): void
    {
        $reference = $this->annotated('name', '桃鈴ねね', 'とうりんねね', [['桃鈴', 'とうりん'], ['ねね', 'ねね']], 'Momosuzu Nene');

        $this->assertSame(['romaji_mismatch'], $this->verify($reference));
        $this->assertSame('rejected', $reference->status);
    }

    public function test_name_romaji_ignores_name_order_and_english_style_kana(): void
    {
        foreach (['Houshou Marine', 'Marine Houshou'] as $alt) {
            Reference::query()->delete();
            $reference = $this->annotated('name', '宝鐘マリン', 'ほうしょうまりん', [['宝鐘', 'ほうしょう'], ['マリン', 'まりん']], $alt, 'translation');

            $this->assertSame([], $this->verify($reference), $alt);
            $this->assertSame([true, true], $this->okFlags($reference));
        }
    }

    public function test_name_with_swapped_readings_is_rejected(): void
    {
        $reference = $this->annotated('name', '桃鈴ねね', 'すずももねね', [['桃', 'すず'], ['鈴', 'もも'], ['ねね', 'ねね']], 'Momosuzu Nene');

        $this->assertSame(['romaji_mismatch'], $this->verify($reference));
    }

    public function test_name_units_group_consecutive_kanji_words(): void
    {
        $reference = $this->annotated('name', '小鳥遊キアラ', 'たかなしきあら', [['小', 'たか'], ['鳥遊', 'なし'], ['キアラ', 'きあら']], 'Takanashi Kiara');

        $this->assertSame([], $this->verify($reference));
        $this->assertSame([0, 0, null], array_column($reference->annotation()->sole()->words, 'unit'));
        $this->assertSame([true, true, true], $this->okFlags($reference));
    }

    public function test_song_words_have_no_unit(): void
    {
        $reference = $this->annotated('song', '花に亡霊', 'はなにぼうれい', [['花', 'はな'], ['に', 'に'], ['亡霊', 'ぼうれい']]);

        $this->verify($reference);

        $words = $reference->annotation()->sole()->words;
        $this->assertSame([null, null, null], array_map(fn ($w) => array_key_exists('unit', $w) ? $w['unit'] : 'missing', $words));
    }

    public function test_name_romaji_completes_final_consonant_and_drops_apostrophe(): void
    {
        $reference = $this->annotated('name', '一伊那尓栖', 'にのまえいなにす', [['一', 'にのまえ'], ['伊那尓栖', 'いなにす']], "Ninomae Ina'nis");
        $this->assertSame([], $this->verify($reference));

        Reference::query()->delete();
        $oneWord = $this->annotated('name', '一伊那尓栖', 'にのまえいなにす', [['一伊那尓栖', 'にのまえいなにす']], "Ninomae Ina'nis");
        $this->assertSame([], $this->verify($oneWord));
    }

    public function test_name_romaji_given_name_first(): void
    {
        $reference = $this->annotated('name', '火威青', 'ひおどしあお', [['火威', 'ひおどし'], ['青', 'あお']], 'Ao Hiodoshi');

        $this->assertSame([], $this->verify($reference));
    }

    public function test_name_units_must_appear_in_order(): void
    {
        $reference = $this->annotated('name', '花の亡霊', 'はなのぼうれい', [['花', 'はな'], ['の', 'の'], ['亡霊', 'ぼうれい']], 'Hana Bourei Hana');
        $this->assertSame([], $this->verify($reference));

        $overlap = $this->annotated('name', '花の花', 'はなのはな', [['花', 'はな'], ['の', 'の'], ['花', 'はな']], 'Hana');
        $this->assertSame(['romaji_mismatch'], $this->verify($overlap));
    }

    public function test_historic_kana_in_name(): void
    {
        $reference = $this->annotated('name', '沙花叉クロヱ', 'さかまたくろえ', [['沙花叉', 'さかまた'], ['クロヱ', 'くろえ']], 'Sakamata Chloe');

        $this->assertSame([], $this->verify($reference));
        $this->assertSame([], $this->verify($this->annotated('song', 'ヰゐ', 'いい', [['ヰゐ', 'いい']])));
    }

    public function test_name_with_kanji_but_no_romaji_is_rejected(): void
    {
        $reference = $this->annotated('name', '小鳥遊キアラ', 'たかなしきあら', [['小鳥遊', 'たかなし'], ['キアラ', 'きあら']]);

        $this->assertSame(['romaji_mismatch'], $this->verify($reference));
    }

    public function test_kana_only_name_needs_no_romaji(): void
    {
        $reference = $this->annotated('name', 'ラプラス・ダークネス', 'らぷらすだーくねす', [['ラプラス', 'らぷらす'], ['ダークネス', 'だーくねす']], 'La+ Darknesss');

        $this->assertSame([], $this->verify($reference));
    }

    public function test_song_romaji_is_checked_only_for_alt_kind_romaji(): void
    {
        $ok = $this->annotated('song', 'きゅうくらりん', 'きゅうくらりん', [['きゅうくらりん', 'きゅうくらりん']], 'Kyu-Kurarin', 'romaji');
        $bad = $this->annotated('song', '花に亡霊', 'はなにぼうれい', [['花', 'はな'], ['に', 'に'], ['亡霊', 'ぼうれい']], 'Hana ni Yuurei', 'romaji');
        $translation = $this->annotated('song', '亡霊', 'ぼうれい', [['亡霊', 'ぼうれい']], 'Ghost', 'translation');

        $this->assertSame([], $this->verify($ok));
        $this->assertSame(['romaji_mismatch'], $this->verify($bad));
        $this->assertSame([], $this->verify($translation));
    }

    public function test_missing_annotation_changes_nothing(): void
    {
        $reference = Reference::query()->create(['kind' => 'song', 'text_ja' => '花', 'text_key' => Reference::keyFor('花')]);

        $this->assertSame(['no_annotation'], app(Verifier::class)->verify($reference));
        $this->assertSame('new', $reference->refresh()->status);
    }

    public function test_query_count_is_bounded(): void
    {
        $reference = $this->annotated('song', '今日は花に亡霊を歌った', 'きょうわはなにぼうれいおうたった', [
            ['今日', 'きょう'], ['は', 'わ'], ['花', 'はな'], ['に', 'に'], ['亡霊', 'ぼうれい'], ['を', 'お'], ['歌った', 'うたった', '歌う', 'うたう'],
        ]);
        $reference = Reference::query()->findOrFail($reference->id);

        DB::enableQueryLog();
        app(Verifier::class)->verify($reference);

        $this->assertLessThanOrEqual(6, count(DB::getQueryLog()));
    }
}
