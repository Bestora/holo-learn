<?php

namespace Tests\Feature;

use App\Models\Annotation;
use App\Models\Reference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Pages of the learning UI, the privacy page, the admin list and the footer. */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_learning_pages_answer_for_signed_in_users(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('learn.index'))->assertOk();
        $this->get(route('favorites.index'))->assertOk();
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_privacy_page_is_public(): void
    {
        $this->get('/datenschutz')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('privacy'));
        $this->actingAs(User::factory()->create())->get(route('privacy'))->assertOk();
    }

    public function test_admin_list_is_for_admins_only(): void
    {
        $this->get(route('admin.rejected'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.rejected'))->assertForbidden();
    }

    public function test_admin_list_shows_rejected_annotations_with_error_codes(): void
    {
        $rejected = $this->annotated('クレイジー・オリー', 'Kureiji Ollie', 'rejected', ['kana_mismatch']);
        $this->annotated('星街すいせい', 'Hoshimachi Suisei', 'verified', null);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->get(route('admin.rejected'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/rejected')
            ->has('annotations.data', 1)
            ->where('annotations.data.0.id', $rejected->id)
            ->where('annotations.data.0.text_ja', 'クレイジー・オリー')
            ->where('annotations.data.0.alt_text', 'Kureiji Ollie')
            ->where('annotations.data.0.kind', 'name')
            ->where('annotations.data.0.reading_kana', 'くれいじおりい')
            ->where('annotations.data.0.check_errors', ['kana_mismatch'])
            ->has('annotations.links'));
    }

    public function test_footer_carries_the_required_notices(): void
    {
        // The footer is rendered by React; its source must carry the exact notice texts.
        $footer = (string) file_get_contents(resource_path('js/components/app-footer.tsx'));

        foreach ([
            'Inoffizielles Fanprojekt, nicht mit COVER Corp. verbunden.',
            'Inhalte © COVER Corp. / jeweilige Rechteinhaber.',
            'Wörterbuchdaten: JMdict, JMnedict, KANJIDIC2, KRADFILE © EDRDG (CC BY-SA 4.0).',
            'Daten: Holodex.',
            'https://github.com/Bestora/holo-learn',
            'privacy()',
        ] as $text) {
            $this->assertStringContainsString($text, $footer);
        }
    }

    /** @param ?list<string> $errors */
    private function annotated(string $text, string $alt, string $status, ?array $errors): Annotation
    {
        $reference = Reference::query()->create([
            'kind' => 'name', 'text_ja' => $text, 'text_key' => Reference::keyFor($text), 'alt_text' => $alt, 'status' => $status,
        ]);

        return Annotation::query()->create([
            'reference_id' => $reference->id, 'reading_kana' => 'くれいじおりい', 'meaning_en' => $alt,
            'words' => [], 'alt_kind' => 'romaji', 'model' => 'test', 'check_errors' => $errors,
        ]);
    }
}
