<?php

namespace Tests\Feature;

use App\Annotations\Verifier;
use App\Models\DictWord;
use App\Models\Reference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $reference = $this->annotated('name', '宝鐘マリン', 'ほうしょうまりん', [['宝鐘', 'ほうしょう'], ['マリン', 'まりん']], 'Houshou Marine', 'translation');
        $this->assertSame([], $this->verify($reference));
        $this->assertSame([true, true], $this->okFlags($reference));

        // reversed romaji order is no longer accepted
        $reversed = $this->annotated('name', '宝鐘マリンx', 'ほうしょうまりん', [['宝鐘', 'ほうしょう'], ['マリン', 'まりん'], ['x', 'えっくす']], 'Marine Houshou');
        $this->assertContains('romaji_mismatch', $this->verify($reversed));
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

    public function test_name_romaji_given_name_first_is_rejected(): void
    {
        $reference = $this->annotated('name', '火威青', 'ひおどしあお', [['火威', 'ひおどし'], ['青', 'あお']], 'Ao Hiodoshi');

        $this->assertSame(['romaji_mismatch'], $this->verify($reference));
    }

    public function test_name_units_must_appear_in_order(): void
    {
        $reference = $this->annotated('name', '花の亡霊', 'はなのぼうれい', [['花', 'はな'], ['の', 'の'], ['亡霊', 'ぼうれい']], 'Hana no Bourei');
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

    /** @return array<string, array{string, string, list<array{0: string, 1: string}>, list<string>}> */
    public static function nameProbes(): array
    {
        $rejected = ['romaji_mismatch'];

        return [
            'missing part, one word' => ['水宮枢', 'Mizumiya Su', [['水宮枢', 'みずみや']], $rejected],
            'family name dropped' => ['輪堂千速', 'Rindo Chihaya', [['輪堂千速', 'ちはや']], $rejected],
            'reading in reverse order' => ['火威青', 'Hiodoshi Ao', [['火威青', 'あおひおどし']], $rejected],
            'parts swapped across words' => ['水宮枢', 'Mizumiya Su', [['水宮', 'す'], ['枢', 'みずみや']], $rejected],
            'partial part' => ['宝鐘マリン', 'Houshou Marine', [['宝鐘', 'しょう'], ['マリン', 'まりん']], $rejected],
            'prefix of a part' => ['湊あくあ', 'Minato Aqua', [['湊', 'みな'], ['あくあ', 'あくあ']], $rejected],
            'part with dropped kana' => ['綺々羅々ヴィヴィ', 'Kikirara Vivi', [['綺々羅々', 'きらら'], ['ヴィヴィ', 'ゔぃゔぃ']], $rejected],
            'swapped readings' => ['桃鈴ねね', 'Momosuzu Nene', [['桃', 'すず'], ['鈴', 'もも'], ['ねね', 'ねね']], $rejected],
            'momosuzu' => ['桃鈴ねね', 'Momosuzu Nene', [['桃鈴', 'ももすず'], ['ねね', 'ねね']], []],
            'houshou' => ['宝鐘マリン', 'Houshou Marine', [['宝鐘', 'ほうしょう'], ['マリン', 'まりん']], []],
            'takanashi' => ['小鳥遊キアラ', 'Takanashi Kiara', [['小鳥遊', 'たかなし'], ['キアラ', 'きあら']], []],
            'takanashi wrong inner split' => ['小鳥遊キアラ', 'Takanashi Kiara', [['小', 'たか'], ['鳥遊', 'なし'], ['キアラ', 'きあら']], []],
            'ina two words' => ['一伊那尓栖', "Ninomae Ina'nis", [['一', 'にのまえ'], ['伊那尓栖', 'いなにす']], []],
            'ina one word' => ['一伊那尓栖', "Ninomae Ina'nis", [['一伊那尓栖', 'にのまえいなにす']], []],
            'chloe' => ['沙花叉クロヱ', 'Sakamata Chloe', [['沙花叉', 'さかまた'], ['クロヱ', 'くろえ']], []],
            'sana one word' => ['九十九佐命', 'Tsukumo Sana', [['九十九佐命', 'つくもさな']], []],
            'sana two words' => ['九十九佐命', 'Tsukumo Sana', [['九十九', 'つくも'], ['佐命', 'さな']], []],
            'ririka' => ['一条莉々華', 'Ichijou Ririka', [['一条莉々華', 'いちじょうりりか']], []],
            'ao' => ['火威青', 'Hiodoshi Ao', [['火威', 'ひおどし'], ['青', 'あお']], []],
            'calliope' => ['森カリオペ', 'Mori Calliope', [['森', 'もり'], ['カリオペ', 'かりおぺ']], []],
            'suisei' => ['星街すいせい', 'Hoshimachi Suisei', [['星街', 'ほしまち'], ['すいせい', 'すいせい']], []],
            'houshou missing u' => ['宝鐘マリン', 'Houshou Marine', [['宝鐘', 'ほしょう'], ['マリン', 'まりん']], $rejected],
            'houshou with o' => ['宝鐘マリン', 'Houshou Marine', [['宝鐘', 'ほうしょお'], ['マリン', 'まりん']], $rejected],
            'ookami short' => ['大神ミオ', 'Ookami Mio', [['大神', 'おかみ'], ['ミオ', 'みお']], $rejected],
            'ookami with u' => ['大神ミオ', 'Ookami Mio', [['大神', 'おうかみ'], ['ミオ', 'みお']], $rejected],
            'tokoyami with extra o' => ['常闇トワ', 'Tokoyami Towa', [['常闇', 'とおこやみ'], ['トワ', 'とわ']], $rejected],
            'ina without i, one word' => ['一伊那尓栖', "Ninomae Ina'nis", [['一伊那尓栖', 'にのまえなにす']], $rejected],
            'ina without i, two words' => ['一伊那尓栖', "Ninomae Ina'nis", [['一', 'にのまえ'], ['伊那尓栖', 'なにす']], $rejected],
            'ookami with added u' => ['大神ミオ', 'Ookami Mio', [['大神', 'おおうかみ'], ['ミオ', 'みお']], $rejected],
            'houshou with added u' => ['宝鐘マリン', 'Houshou Marine', [['宝鐘', 'ほううしょう'], ['マリン', 'まりん']], $rejected],
            'houshou with final added u' => ['宝鐘マリン', 'Houshou Marine', [['宝鐘', 'ほうしょうう'], ['マリン', 'まりん']], $rejected],
            'ichijou with added u' => ['一条莉々華', 'Ichijou Ririka', [['一条莉々華', 'いちじょううりりか']], $rejected],
            'kiryu with two added u' => ['桐生ココ', 'Kiryu Coco', [['桐生', 'きりゅうう'], ['ココ', 'ここ']], $rejected],
            'rindo' => ['輪堂千速', 'Rindo Chihaya', [['輪堂千速', 'りんどうちはや']], []],
            'su' => ['水宮枢', 'Mizumiya Su', [['水宮枢', 'みずみやすう']], []],
            'juufuutei' => ['儒烏風亭らでん', 'Juufuutei Raden', [['儒烏風亭', 'じゅうふうてい'], ['らでん', 'らでん']], []],
            // ponytail: known ceiling, like しろがねい below
            'tsukumo with spurious u' => ['九十九佐命', 'Tsukumo Sana', [['九十九佐命', 'つくもうさな']], []],
            'kiryu with long vowel' => ['桐生ココ', 'Kiryu Coco', [['桐生', 'きりゅう'], ['ココ', 'ここ']], []],
            'oozora' => ['大空スバル', 'Oozora Subaru', [['大空', 'おおぞら'], ['スバル', 'すばる']], []],
            'suzuna' => ['鈴鳴つづり', 'Suzuna Tsuzuri', [['鈴鳴', 'すずな'], ['つづり', 'つづり']], []],
            // ponytail: known ceiling: a spurious long vowel where the romaji omits one passes
            'spurious long vowel' => ['白銀ノエル', 'Shirogane Noel', [['白銀', 'しろがねい'], ['ノエル', 'のえる']], []],
            'no kanji' => ['ラプラス・ダークネス', 'La+ Darknesss', [['ラプラス', 'らぷらす'], ['・ダークネス', 'だーくねす']], []],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $words
     * @param  list<string>  $errors
     */
    #[DataProvider('nameProbes')]
    public function test_name_probes(string $text, string $alt, array $words, array $errors): void
    {
        $reference = $this->annotated('name', $text, implode('', array_column($words, 1)), $words, $alt);

        $this->assertSame($errors, $this->verify($reference));
    }

    public function test_wrong_inner_split_carries_unit_reading(): void
    {
        $reference = $this->annotated('name', '小鳥遊キアラ', 'たかなしきあら', [['小', 'たか'], ['鳥遊', 'なし'], ['キアラ', 'きあら']], 'Takanashi Kiara');

        $this->verify($reference);

        $this->assertSame(['kanji:小@0=たかなし', 'kanji:遊@2=たかなし', 'kanji:鳥@1=たかなし'], $this->links($reference, 'kanji'));
    }

    /** @return array<string, array{string, list<array{0: string, 1: string, 2?: string, 3?: string}>, bool}> */
    public static function lemmaProbes(): array
    {
        return [
            'lemma reading as surface reading' => ['歌った', [['歌った', 'うたう', '歌う', 'うたう']], false],
            'stem with extra kana' => ['行った', [['行った', 'いきった', '行く', 'いく']], false],
            'adjective lemma reading' => ['女々しくて', [['女々しくて', 'めめしい', '女々しい', 'めめしい']], false],
            'past tense' => ['歌った', [['歌った', 'うたった', '歌う', 'うたう']], true],
            'sound change' => ['行った', [['行った', 'いった', '行く', 'いく']], true],
            'adjective te form' => ['女々しくて', [['女々しくて', 'めめしくて', '女々しい', 'めめしい']], true],
            'dictionary path with okurigana' => ['駆ける', [['駆ける', 'かける']], true],
            'irregular verb' => ['来た', [['来た', 'きた', '来る', 'くる']], false],
            // ponytail: known miss: a wrong lemma with the same kanji and stem is accepted (出す is right)
            'wrong lemma with same stem' => ['出した', [['出した', 'でした', '出る', 'でる']], true],
        ];
    }

    /** @param list<array{0: string, 1: string, 2?: string, 3?: string}> $words */
    #[DataProvider('lemmaProbes')]
    public function test_lemma_probes(string $text, array $words, bool $ok): void
    {
        $reference = $this->annotated('song', $text, implode('', array_column($words, 1)), $words);

        $this->assertSame([], $this->verify($reference));
        $this->assertSame([$ok], $this->okFlags($reference));
    }

    public function test_dictionary_reading_must_fit_the_kana_skeleton(): void
    {
        DictWord::query()->forceCreate(['spelling' => '駆ける', 'reading' => 'かけ', 'glosses' => [['broken row']], 'is_name' => false]);
        $reference = $this->annotated('song', '駆ける', 'かけ', [['駆ける', 'かけ']]);

        $this->assertSame([], $this->verify($reference));
        $this->assertSame([false], $this->okFlags($reference));
    }

    /** @return array<string, array{string, string, list<array{0: string, 1: string}>, string, string, list<string>}> */
    public static function songRomajiProbes(): array
    {
        return [
            'romaji claimed as translation' => ['女々しくて', 'Memeshikute', [['女々しくて', 'めめしい']], 'translation', 'めめしい', ['romaji_mismatch']],
            // accepted loss: an English translation that converts completely is checked as romaji
            'english translation that converts' => ['嘘ミーム', 'Fake Meme', [['嘘', 'うそ'], ['ミーム', 'みーむ']], 'translation', 'うそみーむ', ['romaji_mismatch']],
            'romaji with long vowel mark' => ['嘘ミーム', 'Uso Miimu', [['嘘', 'うそ'], ['ミーム', 'みーむ']], 'romaji', 'うそみーむ', []],
            'bars in a kana title' => ['ゴーゴー幽霊船', 'Go Go Yuureisen', [['ゴーゴー', 'ごーごー'], ['幽霊船', 'ゆうれいせん']], 'romaji', 'ごーごーゆうれいせん', []],
            'bar at the end' => ['ぽっぴっぽー', 'Poppippo', [['ぽっぴっぽー', 'ぽっぴっぽー']], 'romaji', 'ぽっぴっぽー', []],
            'particle wa' => ['世界は恋に落ちている', 'Sekai wa Koi ni Ochiteiru', [['世界', 'せかい'], ['は', 'は'], ['恋', 'こい'], ['に', 'に'], ['落ちている', 'おちている']], 'romaji', 'せかいはこいにおちている', []],
            'particle e' => ['未来へ', 'Mirai e', [['未来', 'みらい'], ['へ', 'へ']], 'romaji', 'みらいへ', []],
            'kanji word read ha is no particle' => ['花', 'wa', [['花', 'は']], 'romaji', 'は', ['romaji_mismatch']],
            'small vowel' => ['うっせぇわ', 'Usseewa', [['うっせぇわ', 'うっせぇわ']], 'romaji', 'うっせぇわ', []],
            'homura as honoo' => ['炎', 'homura', [['炎', 'ほのお']], 'translation', 'ほのお', ['romaji_mismatch']],
            'homura' => ['炎', 'homura', [['炎', 'ほむら']], 'translation', 'ほむら', []],
            'kanade as sou' => ['奏', 'Kanade', [['奏', 'そう']], 'translation', 'そう', ['romaji_mismatch']],
            'ashita as asu' => ['明日', 'Ashita', [['明日', 'あす']], 'translation', 'あす', ['romaji_mismatch']],
            'ashita' => ['明日', 'Ashita', [['明日', 'あした']], 'translation', 'あした', []],
            'sora as kara' => ['空', 'Sora', [['空', 'から']], 'translation', 'から', ['romaji_mismatch']],
            'kyou as konnichi' => ['今日', 'Kyou', [['今日', 'こんにち']], 'translation', 'こんにち', ['romaji_mismatch']],
            'english title' => ['花に亡霊', 'Ghost In A Flower', [['花', 'はな'], ['に', 'に'], ['亡霊', 'ぼうれい']], 'translation', 'はなにぼうれい', []],
            'romaji claimed as none' => ['きゅうくらりん', 'Kyu-Kurarin', [['きゅうくらりん', 'きゅうくらりん']], 'none', 'きゅうくらりん', []],
            // no kanji word: kana_mismatch alone guards the readings, no romaji check
            'romaji claimed as none, wrong' => ['きゅうくらりん', 'Kyu-Kurarin', [['きゅうくらりん', 'きゅうくらりい']], 'none', 'きゅうくらりい', ['kana_mismatch']],
            // Latin words repeated in the alt are removed from alt and reading before the comparison
            'latin word in title' => ['Ahoy!! 我ら宝鐘海賊団☆', 'Ahoy!! Warera Houshou Kaizokudan☆', [['Ahoy', 'あほい'], ['我ら', 'われら'], ['宝鐘', 'ほうしょう'], ['海賊団', 'かいぞくだん']], 'romaji', 'あほいわれらほうしょうかいぞくだん', []],
            'latin word in title, wrong' => ['Ahoy!! 我ら宝鐘海賊団☆', 'Ahoy!! Warera Houshou Kaizokudan☆', [['Ahoy', 'あほい'], ['我ら', 'われら'], ['宝鐘', 'ほしょう'], ['海賊団', 'かいぞくだん']], 'romaji', 'あほいわれらほしょうかいぞくだん', ['romaji_mismatch']],
            'latin letter in title' => ['少女S', 'Shoujyo S', [['少女', 'しょうじょ'], ['S', 'えす']], 'romaji', 'しょうじょえす', []],
            'latin letter in title, wrong' => ['少女S', 'Shoujyo S', [['少女', 'しょじょ'], ['S', 'えす']], 'romaji', 'しょじょえす', ['romaji_mismatch']],
            // Latin words are positional and never compared: absent from the alt, they take no token
            'latin letter missing from romaji' => ['少女S', 'Shoujyo', [['少女', 'しょうじょ'], ['S', 'えす']], 'romaji', 'しょうじょえす', []],
            'latin letter only in title' => ['S', 'S', [['S', 'えす']], 'romaji', 'えす', []],
            // "love" is removed from the alt, which stays English: no check, as before
            'latin word not repeated' => ['LOVE受信、ヒクツ発信', "I'm difficult, but love me", [['LOVE', 'らぶ'], ['受信', 'じゅしん'], ['ヒクツ', 'ひくつ'], ['発信', 'はっしん']], 'translation', 'らぶじゅしんひくつはっしん', []],
            'latin word not repeated, wrong (unchecked)' => ['LOVE受信、ヒクツ発信', "I'm difficult, but love me", [['LOVE', 'らぶ'], ['受信', 'じゅうしん'], ['ヒクツ', 'ひくつ'], ['発信', 'はっしん']], 'translation', 'らぶじゅうしんひくつはっしん', []],
            'l in romaji' => ['バラライカ', 'Balalaika', [['バラライカ', 'ばららいか']], 'romaji', 'ばららいか', []],
            // a katakana word may take its token unchecked: kana_mismatch is what rejects it
            'l in romaji, wrong' => ['バラライカ', 'Balalaika', [['バラライカ', 'ばらいか']], 'romaji', 'ばらいか', ['kana_mismatch']],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $words
     * @param  list<string>  $errors
     */
    #[DataProvider('songRomajiProbes')]
    public function test_song_romaji_probes(string $text, string $alt, array $words, string $altKind, string $reading, array $errors): void
    {
        $reference = $this->annotated('song', $text, $reading, $words, $alt, $altKind);

        $this->assertSame($errors, $this->verify($reference));
    }

    /** @return array<string, array{string, string, list<array{0: string, 1: string}>, list<string>, 4?: string}> */
    public static function alignmentProbes(): array
    {
        $rejected = ['romaji_mismatch'];

        return [
            // katakana words may stand for their English spelling (one token)
            'hatsukoi cider' => ['初恋サイダー', 'Hatsukoi Cider', [['初恋', 'はつこい'], ['サイダー', 'さいだー']], []],
            'renai circulation' => ['恋愛サーキュレーション', 'Renai Circulation', [['恋愛', 'れんあい'], ['サーキュレーション', 'さーきゅれーしょん']], []],
            'pride kakumei' => ['プライド革命', 'Pride Kakumei', [['プライド', 'ぷらいど'], ['革命', 'かくめい']], []],
            'zettai idol' => ['絶対アイドル辞めないで', 'Zettai idol yamenaide', [['絶対', 'ぜったい'], ['アイドル', 'あいどる'], ['辞めないで', 'やめないで']], []],
            'moonlight densetsu' => ['ムーンライト伝説', 'Moonlight Densetsu', [['ムーンライト', 'むーんらいと'], ['伝説', 'でんせつ']], []],
            'pokemon master' => ['めざせポケモンマスター', 'Mezase Pokemon Master', [['めざせ', 'めざせ'], ['ポケモン', 'ぽけもん'], ['マスター', 'ますたー']], []],
            'katakana words, one token each' => ['ヨドバシカメラの歌', 'Yodobashi Camera no Uta', [['ヨドバシ', 'よどばし'], ['カメラ', 'かめら'], ['の', 'の'], ['歌', 'うた']], []],
            // a wildcard takes exactly one token; Camera is no romaji
            'katakana word over two tokens' => ['ヨドバシカメラの歌', 'Yodobashi Camera no Uta', [['ヨドバシカメラ', 'よどばしかめら'], ['の', 'の'], ['歌', 'うた']], $rejected],
            'latin word at the end' => ['花咲く☆最強レジェンドDays', 'Hanasaku Saikyou Legend DAYS', [['花咲く', 'はなさく'], ['最強', 'さいきょう'], ['レジェンド', 'れじぇんど'], ['Days', 'でいず']], []],
            'latin word absent from alt' => ['コングラッCHU☆マーチ', 'Congrachu☆march', [['コングラッ', 'こんぐらっ'], ['CHU', 'ちゅう'], ['マーチ', 'まーち']], []],
            'tetoris' => ['テトリス', 'Tetoris', [['テトリス', 'てとりす']], []],
            'bibbidiba' => ['ビビデバ', 'Bibbidiba', [['ビビデバ', 'びびでば']], []],
            'moechakkafire' => ['モエチャッカファイア', 'Moechakkafire', [['モエチャッカファイア', 'もえちゃっかふぁいあ']], []],
            'particle inside a run' => ['世界は恋に落ちている', 'Sekai wa Koi ni Ochiteiru', [['世界', 'せかい'], ['は', 'は'], ['恋', 'こい'], ['に', 'に'], ['落ちている', 'おちている']], []],
            'eight words in one token' => ['ねねねねねねねね!大爆走', 'Nenenenenenenene! Dai Bakusou', [...array_fill(0, 8, ['ね', 'ね']), ['大爆走', 'だいばくそう']], []],
            'latin word glued at the end' => ['会いにKiTE!', 'Ainikite', [['会い', 'あい'], ['に', 'に'], ['KiTE', 'きて']], []],
            'latin word glued at the start' => ['YUME日和', 'YUMEbiyori', [['YUME', 'ゆめ'], ['日和', 'びより']], []],
            'latin word glued, kanji word wrong' => ['YUME日和', 'YUMEbiyori', [['YUME', 'ゆめ'], ['日和', 'ひより']], $rejected],
            'glued split cannot drop letters' => ['日和YUME', 'BiyoriYUMEx', [['日和', 'びより'], ['YUME', 'ゆめ']], $rejected],
            'token-final n stays n' => ['金色', 'Kin Iro', [['金色', 'きんいろ']], []],
            // ponytail: known ceiling: romaji n before a vowel is ambiguous, so ん+vowel matches na-row romaji
            'n before vowel is ambiguous' => ['奏', 'Kanade', [['奏', 'かんあで']], []],
            // a wildcard takes one token only, so it cannot eat the first token of a split kanji word
            'wildcard takes part of a split kanji word' => ['サイダー初恋', 'Saida Hatsu Koi', [['サイダー', 'さいだー'], ['初恋', 'こい']], $rejected],
            'split kanji word after a wildcard' => ['サイダー初恋', 'Cider Hatsu Koi', [['サイダー', 'さいだー'], ['初恋', 'はつこい']], []],
            // titles without a kanji word skip the romaji check
            'kana-only title over three tokens' => ['ウォーアイニー', 'Woh I Nee', [['ウォーアイニー', 'うぉーあいにー']], []],
            'kana-only title, converting translation' => ['ロッタラ ロッタラ', 'Lotta Love, Lotta Love', [['ロッタラ', 'ろったら'], ['ロッタラ', 'ろったら']], [], 'translation'],
            'translation is no romaji' => ['花に亡霊', 'Ghost In A Flower', [['花', 'はな'], ['に', 'に'], ['亡霊', 'ぼうれい']], [], 'translation'],
            'english words that do not convert' => ['#あくあ色ぱれっと', 'Aqua Iro Palette', [['あくあ', 'あくあ'], ['色', 'いろ'], ['ぱれっと', 'ぱれっと']], [], 'translation'],
            'english word between romaji' => ['腐れ外道とチョコレゐト', 'Kusare-Gedou and Chocolate', [['腐れ外道', 'くされげどう'], ['と', 'と'], ['チョコレゐト', 'ちょこれゐと']], [], 'translation'],
            // accepted loss: How takes the wildcard, so "to Sekai Seifuku" has the shape of romaji for 世界征服
            'english prefix of romaji' => ['ハウトゥー世界征服', 'How to Sekai Seifuku', [['ハウトゥー', 'はうとぅー'], ['世界征服', 'せかいせいふく']], $rejected, 'translation'],
            // a run is one word against 1–6 tokens or several words against one token
            'reading shifted across words and tokens' => ['宝鐘海賊団', 'Houshou Kaizokudan', [['宝鐘', 'ほうしょうかい'], ['海賊団', 'ぞくだん']], $rejected],
            'reading not shifted' => ['宝鐘海賊団', 'Houshou Kaizokudan', [['宝鐘', 'ほうしょう'], ['海賊団', 'かいぞくだん']], []],
            'particle shifted across words and tokens' => ['明日晴れ', 'Ashita Hare', [['明日', 'あしたは'], ['晴れ', 'れ']], $rejected],
            'particle not shifted' => ['明日晴れ', 'Ashita Hare', [['明日', 'あした'], ['晴れ', 'はれ']], []],
            'several words in one token' => ['世界は恋に落ちている', 'Sekai wa Koi ni Ochiteiru', [['世界', 'せかい'], ['は', 'は'], ['恋', 'こい'], ['に', 'に'], ['落ち', 'おち'], ['て', 'て'], ['いる', 'いる']], []],
            'two words in one token' => ['花咲く', 'Hanasaku', [['花', 'はな'], ['咲く', 'さく']], []],
            // ponytail: remaining ceiling: a reading shifted between words inside one token is undetectable
            'reading shifted inside one token' => ['花咲く', 'Hanasaku', [['花', 'はなさ'], ['咲く', 'く']], []],
            // a middle dot does not stop a katakana word from being a one-token wildcard
            'katakana word with middle dot' => ['ハコス・ベールズの歌', 'Baelz no Uta', [['ハコス・ベールズ', 'はこすべーるず'], ['の', 'の'], ['歌', 'うた']], []],
            // a kanji word must always be matched by romaji tokens
            'kakumei wrong' => ['プライド革命', 'Pride Kakumei', [['プライド', 'ぷらいど'], ['革命', 'かくべい']], $rejected],
            'hatsukoi wrong' => ['初恋サイダー', 'Hatsukoi Cider', [['初恋', 'しょれん'], ['サイダー', 'さいだー']], $rejected],
            'kanji word without romaji' => ['ふわふわ時間', 'Fuwafuwa Time', [['ふわふわ', 'ふわふわ'], ['時間', 'じかん']], $rejected],
            'ora as orera' => ['俺ら東京さ行ぐだ', 'Ora Tōkyō sa Iguda', [['俺ら', 'おれら'], ['東京', 'とうきょう'], ['さ', 'さ'], ['行ぐだ', 'いぐだ']], $rejected],
            'katakana word cannot swallow a kanji word' => ['プライド革命', 'Pride Kakumei', [['プライド', 'ぷらいど'], ['革命', 'かく']], $rejected],
            'katakana wildcard cannot take the kanji token' => ['革命プライド', 'Kakumei Pride', [['革命', 'ぷらいど'], ['プライド', 'ぷらいど']], $rejected],
            'typo in alt' => ['夜行性ハイズ', 'Yakousie Haizu', [['夜行性', 'やこうせい'], ['ハイズ', 'はいず']], $rejected],
            'token left over' => ['初恋サイダー', 'Remix Hatsukoi Cider', [['初恋', 'はつこい'], ['サイダー', 'さいだー']], $rejected],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $words
     * @param  list<string>  $errors
     */
    #[DataProvider('alignmentProbes')]
    public function test_song_romaji_alignment(string $text, string $alt, array $words, array $errors, string $altKind = 'romaji'): void
    {
        $reference = $this->annotated('song', $text, implode('', array_column($words, 1)), $words, $alt, $altKind);

        $this->assertSame($errors, $this->verify($reference));
    }

    /** @return array<string, array{string, string, list<array{0: string, 1: string}>, list<string>, string}> */
    public static function mixedAltProbes(): array
    {
        $titles = [
            'genjyou' => ['現状ディストラクション', 'Genjyou Destruction', [['現状', 'げんじょう', 'げんしょう'], ['ディストラクション', 'でぃすとらくしょん']]],
            'hatsukoi' => ['初恋サイダー', 'Hatsukoi Cider', [['初恋', 'はつこい', 'しょれん'], ['サイダー', 'さいだー']]],
            'kakumei' => ['プライド革命', 'Pride Kakumei', [['プライド', 'ぷらいど'], ['革命', 'かくめい', 'かくべい']]],
            'uta' => ['ヨドバシカメラの歌', 'Yodobashi Camera no Uta', [['ヨドバシ', 'よどばし'], ['カメラ', 'かめら'], ['の', 'の'], ['歌', 'うた', 'か']]],
            'hanasaku' => ['花咲く☆最強レジェンドDays', 'Hanasaku Saikyou Legend DAYS', [['花咲く', 'はなさく', 'はなざく'], ['最強', 'さいきょう'], ['レジェンド', 'れじぇんど'], ['Days', 'でいず']]],
            'tokyo' => ['東京サマーセッション', 'Tokyo Summer Session', [['東京', 'とうきょう', 'ひがしきょう'], ['サマー', 'さまー'], ['セッション', 'せっしょん']]],
            'yamato' => ['大和撫子エデュケイション', 'Yamato Nadeshiko Education', [['大和撫子', 'やまとなでしこ', 'だいわなでしこ'], ['エデュケイション', 'えでゅけいしょん']]],
        ];
        $probes = [];
        foreach ($titles as $name => [$text, $alt, $words]) {
            $right = array_map(fn (array $w) => [$w[0], $w[1]], $words);
            $wrong = array_map(fn (array $w) => [$w[0], $w[2] ?? $w[1]], $words);
            foreach (['translation', 'none', 'romaji'] as $kind) {
                $probes["$name, $kind"] = [$text, $alt, $right, [], $kind];
                $probes["$name wrong, $kind"] = [$text, $alt, $wrong, ['romaji_mismatch'], $kind];
            }
        }

        return $probes;
    }

    /**
     * The alt_kind claim does not decide whether a mixed romaji/English alt is checked.
     *
     * @param  list<array{0: string, 1: string}>  $words
     * @param  list<string>  $errors
     */
    #[DataProvider('mixedAltProbes')]
    public function test_mixed_alt_is_checked_whatever_alt_kind_claims(string $text, string $alt, array $words, array $errors, string $altKind): void
    {
        $reference = $this->annotated('song', $text, implode('', array_column($words, 1)), $words, $alt, $altKind);

        $this->assertSame($errors, $this->verify($reference));
    }

    /** @return array<string, array{string, string, list<array{0: string, 1: string}>, list<string>, string}> */
    public static function shapeProbes(): array
    {
        // wrong readings in alts that only have the shape of a romanization (wildcards, mixed words, subtitle)
        $wrong = [
            'k word over two tokens' => ['ヨドバシカメラの歌', 'Yodobashi Camera no Uta', [['ヨドバシカメラ', 'よどばしかめら'], ['の', 'の'], ['歌', 'うき']]],
            'all back' => ['強風オールバック', 'Kyoufuu All Back', [['強風', 'きょうふき'], ['オールバック', 'おーるばっく']]],
            'mixed word over two tokens' => ['前向きロケット団!', 'Maemuki Rocket Dan', [['前向き', 'まえむけ'], ['ロケット団', 'ろけっとだん']]],
            'trailing subtitle' => ['金曜日のおはよう', 'Kinyoubi no Ohayou -love story-', [['金曜日', 'きんようき'], ['の', 'の'], ['おはよう', 'おはよう']]],
            'merged hatsukoi' => ['初恋サイダー', 'Hatsukoi Cider', [['初恋サイダー', 'はつこきさいだー']]],
            'merged kakumei' => ['プライド革命', 'Pride Kakumei', [['プライド革命', 'ぷらいどかくめき']]],
            'merged genjyou' => ['現状ディストラクション', 'Genjyou Destruction', [['現状ディストラクション', 'げんしょうでぃすとらくしょん']]],
            'one k word over two tokens' => ['東京サマーセッション', 'Tokyo Summer Session', [['東京', 'とうきょき'], ['サマーセッション', 'さまーせっしょん']]],
            'merged latin letter' => ['少女S', 'Shoujyo S', [['少女S', 'しょうじきえす']]],
            'merged particle and katakana' => ['創聖のアクエリオン', 'Sousei No Aquarion', [['創聖', 'そうせき'], ['のアクエリオン', 'のあくえりおん']]],
            'merged latin and kanji' => ['Ahoy!! 我ら宝鐘海賊団☆', 'Ahoy!! Warera Houshou Kaizokudan☆', [['Ahoy我ら', 'あほいわれら'], ['宝鐘', 'ほうしょき'], ['海賊団', 'かいぞくだん']]],
        ];
        $probes = [];
        foreach ($wrong as $name => [$text, $alt, $words]) {
            foreach (['translation', 'none', 'romaji'] as $kind) {
                $probes["$name, $kind"] = [$text, $alt, $words, ['romaji_mismatch'], $kind];
            }
        }
        // English alts stay unchecked (the four without a stored annotation are annotated here)
        $english = [
            'ghost in a flower' => ['花に亡霊', 'Ghost In A Flower', [['花', 'はな'], ['に', 'に'], ['亡霊', 'ぼうれい']]],
            'aqua iro palette' => ['#あくあ色ぱれっと', 'Aqua Iro Palette', [['あくあ', 'あくあ'], ['色', 'いろ'], ['ぱれっと', 'ぱれっと']]],
            'kusare gedou' => ['腐れ外道とチョコレゐト', 'Kusare-Gedou and Chocolate', [['腐れ外道', 'くされげどう'], ['と', 'と'], ['チョコレゐト', 'ちょこれゐと']]],
            'as you like it' => ['お気に召すまま', 'As You Like It', [['お', 'お'], ['気', 'き'], ['に', 'に'], ['召す', 'めす'], ['まま', 'まま']]],
            'hated by life' => ['命に嫌われている。', 'Hated By Life.', [['命', 'いのち'], ['に', 'に'], ['嫌われて', 'きらわれて'], ['いる', 'いる']]],
            'bring it on' => ['劣等上等', 'Bring It On', [['劣等', 'れっとう'], ['上等', 'じょうとう']]],
            'love trial' => ['恋愛裁判', 'Love Trial', [['恋愛', 'れんあい'], ['裁判', 'さいばん']]],
            // a shape run needs a plausible kana length: `A` (1 kana) cannot stand for ざんこくなてんしの
            'cruel angel' => ['残酷な天使のテーゼ', "A Cruel Angel's Thesis", [['残酷な', 'ざんこくな'], ['天使', 'てんし'], ['の', 'の'], ['テーゼ', 'てーぜ']]],
        ];
        foreach ($english as $name => [$text, $alt, $words]) {
            $probes["$name, translation"] = [$text, $alt, $words, [], 'translation'];
        }
        // accepted false rejection: Rain converts, カプチーノ takes "with Cappuccino" in the shape alignment
        $probes['rain with cappuccino, translation'] = ['雨とカプチーノ', 'Rain with Cappuccino', [['雨', 'あめ'], ['と', 'と'], ['カプチーノ', 'かぷちーの']], ['romaji_mismatch'], 'translation'];

        return $probes;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $words
     * @param  list<string>  $errors
     */
    #[DataProvider('shapeProbes')]
    public function test_shape_of_a_romanization_rejects_wrong_readings(string $text, string $alt, array $words, array $errors, string $altKind): void
    {
        $reference = $this->annotated('song', $text, implode('', array_column($words, 1)), $words, $alt, $altKind);

        $this->assertSame($errors, $this->verify($reference));
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
