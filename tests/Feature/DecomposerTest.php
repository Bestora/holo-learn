<?php

namespace Tests\Feature;

use App\Annotations\Decomposer;
use App\Models\Item;
use App\Models\Reference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecomposerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAnnotations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDictionary();
    }

    private function item(string $kind, string $characters): Item
    {
        return Item::query()->where(['kind' => $kind, 'characters' => $characters])->sole();
    }

    public function test_verified_song_links_kana_kanji_vocab_and_radicals(): void
    {
        $reference = $this->annotated('song', '花に亡霊', 'はなにぼうれい', [['花', 'はな'], ['に', 'に'], ['亡霊', 'ぼうれい']]);

        $this->verify($reference);

        $this->assertSame(['hiragana:に@1'], $this->links($reference, 'hiragana'));
        $this->assertSame(['kanji:亡@2=ぼうれい', 'kanji:花@0=はな', 'kanji:霊@3=ぼうれい'], $this->links($reference, 'kanji'));
        $this->assertSame(['vocab:亡霊@2=ぼうれい', 'vocab:花@0=はな'], $this->links($reference, 'vocab'));
        $this->assertSame(
            ['radical:一@3', 'radical:业@3', 'radical:乚@2', 'radical:亠@2', 'radical:亻@0', 'radical:匕@0', 'radical:艹@0', 'radical:雨@3'],
            $this->links($reference, 'radical'),
        );

        $hana = $this->item('vocab', '花');
        $this->assertSame(['はな'], $hana->readings);
        $this->assertSame(['flower', 'blossom', 'beauty'], $hana->meanings);
        $kanji = $this->item('kanji', '花');
        $this->assertSame(['か', 'け', 'はな'], $kanji->readings);
        $this->assertSame(['flower'], $kanji->meanings);
    }

    public function test_radical_meanings_from_own_entry_or_parent(): void
    {
        $this->verify($this->annotated('song', '花', 'はな', [['花', 'はな']]));
        $this->verify($this->annotated('song', '今日', 'きょう', [['今日', 'きょう']]));
        $this->verify($this->annotated('name', '桃鈴ねね', 'ももすずねね', [['桃鈴', 'ももすず'], ['ねね', 'ねね']], 'Momosuzu Nene'));

        $this->assertSame(['spoon'], $this->item('radical', '匕')->meanings);
        $this->assertSame(['grass'], $this->item('radical', '艹')->meanings); // parent 艸, radical note dropped
        $this->assertSame(['person'], $this->item('radical', '亻')->meanings); // parent wins over 'radical number 9'
        $this->assertSame(['person'], $this->item('radical', '𠆢')->meanings);
        $this->assertSame(['legs'], $this->item('radical', '儿')->meanings); // radical suffix stripped
        $this->assertSame([], $this->item('radical', '乙')->meanings);
        $this->assertSame([], $this->item('radical', '乙')->readings);
    }

    public function test_vocab_readings_list_all_dictionary_readings(): void
    {
        $reference = $this->annotated('song', '今日', 'こんにち', [['今日', 'こんにち']]);

        $this->verify($reference);

        $kyou = $this->item('vocab', '今日');
        $this->assertSame(['きょう', 'こんにち'], $kyou->readings);
        $this->assertSame(['today', 'these days'], $kyou->meanings);
        $this->assertSame(['vocab:今日@0=こんにち'], $this->links($reference, 'vocab'));
    }

    public function test_word_without_dictionary_reading_links_only_kana(): void
    {
        $reference = $this->annotated('song', '宇宙のうた', 'そらのうた', [['宇宙', 'そら'], ['の', 'の'], ['うた', 'うた']]);

        $this->verify($reference);

        $this->assertSame('verified', $reference->status);
        $this->assertSame(['hiragana:う@3', 'hiragana:た@4', 'hiragana:の@2'], $this->links($reference));
    }

    public function test_name_links_kanji_but_no_vocab(): void
    {
        $reference = $this->annotated('name', '桃鈴ねね', 'ももすずねね', [['桃鈴', 'ももすず'], ['ねね', 'ねね']], 'Momosuzu Nene');

        $this->verify($reference);

        $this->assertSame('verified', $reference->status);
        $this->assertSame(['kanji:桃@0=ももすず', 'kanji:鈴@1=ももすず'], $this->links($reference, 'kanji'));
        $this->assertSame(['hiragana:ね@2', 'hiragana:ね@3'], $this->links($reference, 'hiragana'));
        $this->assertSame([], $this->links($reference, 'vocab'));
    }

    public function test_name_kanji_carry_the_reading_of_their_unit(): void
    {
        $split = $this->annotated('name', '桃鈴ねね', 'ももすずねね', [['桃', 'もも'], ['鈴', 'すず'], ['ねね', 'ねね']], 'Momosuzu Nene');
        $wrong = $this->annotated('name', '小鳥遊キアラ', 'たかなしきあら', [['小', 'たか'], ['鳥遊', 'なし'], ['キアラ', 'きあら']], 'Takanashi Kiara');

        $this->verify($split);
        $this->verify($wrong);

        $this->assertSame(['kanji:桃@0=ももすず', 'kanji:鈴@1=ももすず'], $this->links($split, 'kanji'));
        $this->assertSame('verified', $wrong->status);
        $this->assertSame(['kanji:小@0=たかなし', 'kanji:遊@2=たかなし', 'kanji:鳥@1=たかなし'], $this->links($wrong, 'kanji'));
    }

    public function test_name_with_english_style_kana_links_its_kanji(): void
    {
        $reference = $this->annotated('name', '宝鐘マリン', 'ほうしょうまりん', [['宝鐘', 'ほうしょう'], ['マリン', 'まりん']], 'Houshou Marine');

        $this->verify($reference);

        $this->assertSame(['kanji:宝@0=ほうしょう', 'kanji:鐘@1=ほうしょう'], $this->links($reference, 'kanji'));
        $this->assertSame(['katakana:マ@2', 'katakana:リ@3', 'katakana:ン@4'], $this->links($reference, 'katakana'));
    }

    public function test_rejected_name_has_no_links(): void
    {
        $reference = $this->annotated('name', '桃鈴ねね', 'とうりんねね', [['桃鈴', 'とうりん'], ['ねね', 'ねね']], 'Momosuzu Nene');

        $this->verify($reference);

        $this->assertSame('rejected', $reference->status);
        $this->assertSame([], $this->links($reference));
    }

    public function test_kana_only_title_links_only_kana(): void
    {
        $reference = $this->annotated('song', 'きゅうくらりん', 'きゅうくらりん', [['きゅうくらりん', 'きゅうくらりん']], 'Kyu-Kurarin', 'romaji');

        $this->verify($reference);

        $this->assertSame('verified', $reference->status);
        $this->assertSame(
            ['hiragana:う@2', 'hiragana:き@0', 'hiragana:く@3', 'hiragana:ら@4', 'hiragana:り@5', 'hiragana:ん@6'],
            $this->links($reference),
        );
    }

    public function test_positions_skip_latin_punctuation_and_symbols(): void
    {
        $reference = $this->annotated('song', 'Ahoy!! 我ら宝鐘海賊団☆', 'あほいわれらほうしょうかいぞくだん', [
            ['Ahoy', 'あほい'], ['我ら', 'われら'], ['宝鐘', 'ほうしょう'], ['海賊団', 'かいぞくだん'],
        ]);

        $this->verify($reference);

        $this->assertSame('verified', $reference->status);
        $this->assertSame(['hiragana:ら@8'], $this->links($reference, 'hiragana'));
        $this->assertSame(
            ['kanji:団@13=かいぞくだん', 'kanji:我@7=われら', 'kanji:海@11=かいぞくだん', 'kanji:賊@12=かいぞくだん'],
            $this->links($reference, 'kanji'),
        );
        $this->assertSame(['vocab:我ら@7=われら', 'vocab:海賊団@11=かいぞくだん'], $this->links($reference, 'vocab'));
    }

    public function test_lemma_word_links_dictionary_form(): void
    {
        $reference = $this->annotated('song', '歌った', 'うたった', [['歌った', 'うたった', '歌う', 'うたう']]);

        $this->verify($reference);

        $this->assertSame(['vocab:歌う@0=うたった'], $this->links($reference, 'vocab'));
        $this->assertSame(['kanji:歌@0=うたった'], $this->links($reference, 'kanji'));
        $this->assertSame(['hiragana:た@2'], $this->links($reference, 'hiragana'));
        $this->assertSame(['to sing'], $this->item('vocab', '歌う')->meanings);
    }

    public function test_unsafe_lemma_links_only_kana(): void
    {
        $reference = $this->annotated('song', '来た', 'きた', [['来た', 'きた', '来る', 'くる']]);

        $this->verify($reference);

        $this->assertSame(['hiragana:た@1'], $this->links($reference));
    }

    public function test_repeated_kana_get_one_row_per_position(): void
    {
        $reference = $this->annotated('song', 'ねね', 'ねね', [['ねね', 'ねね']]);

        $this->verify($reference);

        $this->assertSame(['hiragana:ね@0', 'hiragana:ね@1'], $this->links($reference));
    }

    public function test_redecompose_after_changed_annotation_leaves_no_old_rows(): void
    {
        $reference = $this->annotated('song', '花に亡霊', 'はなにぼうれい', [['花', 'はな'], ['に', 'に'], ['亡霊', 'ぼうれい']]);
        $this->verify($reference);
        $this->assertNotSame([], $this->links($reference, 'vocab'));

        $this->annotate($reference, 'はなになにか', [['花', 'はな'], ['に', 'に'], ['亡霊', 'なにか']]);
        $this->verify($reference);
        $this->assertSame('verified', $reference->status);
        $this->assertSame(['vocab:花@0=はな'], $this->links($reference, 'vocab'));
        $this->assertSame(['kanji:花@0=はな'], $this->links($reference, 'kanji'));

        $this->annotate($reference, 'はなとぼうれい', [['花', 'はな'], ['に', 'と'], ['亡霊', 'ぼうれい']]);
        $this->assertSame(['kana_mismatch'], $this->verify($reference));
        $this->assertSame('rejected', $reference->status);
        $this->assertSame([], $this->links($reference));
    }

    public function test_items_are_shared_and_never_overwritten(): void
    {
        Item::query()->create(['kind' => 'kanji', 'characters' => '花', 'readings' => ['はな'], 'meanings' => ['custom']]);

        $this->verify($this->annotated('song', '花', 'はな', [['花', 'はな']]));
        $this->verify($this->annotated('song', '花に', 'はなに', [['花', 'はな'], ['に', 'に']]));

        $this->assertSame(['custom'], $this->item('kanji', '花')->meanings);
        $this->assertSame(1, Item::query()->where(['kind' => 'vocab', 'characters' => '花'])->count());
        $this->assertSame(2, $this->item('vocab', '花')->references()->count());
    }

    public function test_unverified_reference_ends_without_rows(): void
    {
        $reference = $this->annotated('song', '花に亡霊', 'はなにぼうれい', [['花', 'はな'], ['に', 'に'], ['亡霊', 'ぼうれい']]);
        $this->verify($reference);
        $reference->update(['status' => 'annotated']);

        app(Decomposer::class)->decompose($reference);

        $this->assertSame([], $this->links($reference->refresh()));
    }

    public function test_unknown_kanji_is_skipped(): void
    {
        $reference = Reference::query()->create(['kind' => 'name', 'text_ja' => '綺々', 'text_key' => Reference::keyFor('綺々'), 'alt_text' => 'Kiki', 'status' => 'annotated']);
        $this->annotate($reference, 'きき', [['綺々', 'きき']]);

        $this->verify($reference);

        $this->assertSame('verified', $reference->status);
        $this->assertSame([], $this->links($reference));
    }
}
