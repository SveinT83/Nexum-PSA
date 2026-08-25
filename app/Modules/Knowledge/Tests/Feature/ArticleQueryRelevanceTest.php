<?php

namespace App\Modules\Knowledge\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\Knowledge\Article;
use App\Modules\Knowledge\Queries\ArticleQuery;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ArticleQueryRelevanceTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function ticket_relevance_ranks_the_full_eligible_scope_and_rejects_weak_or_inaccessible_matches(): void
    {
        $ticketClient = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $ticket = Ticket::factory()->create([
            'client_id' => $ticketClient->id,
            'subject' => 'Microsoft 365 lisens og e-post',
            'description' => 'Konfigurer Exchange connector og videresending fra Microsoft 365 til Plesk IMAP etter at lisensen er godkjent.',
        ]);

        $relevant = $this->article([
            'title' => 'E-post med Microsoft 365 og Plesk',
            'body_markdown' => 'Exchange connector for videresending mellom Microsoft 365 og Plesk IMAP ved lisensendringer.',
            'updated_at' => now()->subYear(),
        ]);
        $androidCalendar = $this->article([
            'title' => 'Outlook for Android: synkroniser Microsoft 365-kalender',
            'body_markdown' => 'Telefonoppsett for kalender og kontakter.',
        ]);
        $pleskIncident = $this->article([
            'title' => 'Plesk-hendelsesrapport med gjentakende POST-stopp',
            'body_markdown' => 'En hendelsesrapport om HTTP POST-stopp.',
        ]);
        $logitech = $this->article([
            'title' => 'Logitech C922 mikrofonvolum låst på 0 i Windows',
            'body_markdown' => 'Feilsøking for kamera og mikrofon.',
        ]);
        $draft = $this->article([
            'title' => 'Microsoft 365 Exchange connector Plesk IMAP',
            'body_markdown' => 'Uferdig artikkel med alle søkeordene.',
            'status' => 'draft',
        ]);
        $otherClientArticle = $this->article([
            'title' => 'Microsoft 365 Exchange connector Plesk IMAP for annen klient',
            'body_markdown' => 'Klientavgrenset artikkel med alle søkeordene.',
            'visibility' => 'client-wide',
            'client_scope_id' => $otherClient->id,
        ]);

        // These newer one-term matches reproduce the old newest-30 candidate window.
        foreach (range(1, 35) as $number) {
            $this->article([
                'title' => "Microsoft statusnotat {$number}",
                'body_markdown' => 'Generell kontostatus uten relevant løsningsveiledning.',
            ]);
        }

        $suggestions = app(ArticleQuery::class)->relevantForTicket($ticket);

        $this->assertSame([$relevant->id], $suggestions->modelKeys());
        $this->assertFalse($suggestions->contains($androidCalendar));
        $this->assertFalse($suggestions->contains($pleskIncident));
        $this->assertFalse($suggestions->contains($logitech));
        $this->assertFalse($suggestions->contains($draft));
        $this->assertFalse($suggestions->contains($otherClientArticle));
    }

    #[Test]
    public function ticket_relevance_returns_an_empty_collection_when_only_a_weak_match_exists(): void
    {
        $ticket = Ticket::factory()->create([
            'subject' => 'Skriver trenger nytt toner',
            'description' => 'Bytt toner og kontroller utskriftskvaliteten.',
        ]);

        $this->article([
            'title' => 'Skriveroversikt',
            'body_markdown' => 'Generell informasjon uten tonerprosedyre.',
        ]);

        $suggestions = app(ArticleQuery::class)->relevantForTicket($ticket);

        $this->assertTrue($suggestions->isEmpty());
    }

    #[Test]
    public function ticket_relevance_uses_article_id_as_a_stable_tie_breaker(): void
    {
        $ticket = Ticket::factory()->create([
            'subject' => 'Cloud backup restore failed',
            'description' => 'Restore cloud backup after failure.',
        ]);

        $first = $this->article([
            'title' => 'Cloud backup restore runbook A',
            'body_markdown' => 'First procedure.',
        ]);
        $this->article([
            'title' => 'Cloud backup restore runbook B',
            'body_markdown' => 'Second procedure.',
        ]);

        $suggestions = app(ArticleQuery::class)->relevantForTicket($ticket, 1);

        $this->assertSame([$first->id], $suggestions->modelKeys());
    }

    private function article(array $attributes): Article
    {
        return Article::query()->create(array_merge([
            'title' => 'Knowledge article',
            'body_markdown' => 'Knowledge body.',
            'body_html' => '<p>Knowledge body.</p>',
            'visibility' => 'internal',
            'status' => 'published',
            'owner_id' => $this->author->id,
            'created_by' => $this->author->id,
        ], $attributes));
    }
}
