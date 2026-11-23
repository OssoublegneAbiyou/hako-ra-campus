<?php

namespace Database\Seeders;

use App\Models\Opportunity;
use App\Models\OpportunityRevision;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fiches d'exemple pour le développement. Elles sont explicitement fictives : titres préfixés « [FICTIF] », organisateur fictif,
 * adresses en example.test. Ce n'est en aucun cas un catalogue d'opportunités réelles ou actuelles.
 */
class OpportunityDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])
            || ! in_array(config('database.connections.mysql.database'), ['hako_ra_campus_dev', 'hako_ra_campus_test'], true)) {
            throw new RuntimeException('Fixtures réservées aux bases locales Campus dédiées.');
        }
        $editor = User::query()->where('email', 'editeur@example.test')->firstOrFail();
        $base = ['organizer' => 'Organisme fictif de démonstration', 'campaign' => 'Démonstration', 'countries' => ['CI'], 'modality' => 'unspecified', 'levels' => ['any'], 'fields' => [],
            'languages' => ['fr'], 'conditions' => 'Conditions fictives, rédigées uniquement pour illustrer l’écran. Aucune candidature n’est possible.',
            'summary' => 'Fiche de démonstration. Cette opportunité n’existe pas.', 'created_by' => $editor->id];
        $day = now()->addDays(45)->startOfDay();
        $examples = [
            ['category' => 'scholarship', 'title' => '[FICTIF] Bourse d’exemple avec date et heure', 'funding' => 'full', 'fees' => 'free', 'status' => 'published',
                'deadline_kind' => 'instant', 'deadline_at' => now()->addDays(30)->setTime(22, 59), 'deadline_timezone' => 'Europe/Paris', 'closes_at' => now()->addDays(30)->setTime(22, 59)],
            ['category' => 'competition', 'title' => '[FICTIF] Concours d’exemple avec date seule', 'funding' => 'unspecified', 'fees' => 'paid', 'fees_detail' => 'Montant fictif', 'status' => 'published',
                'deadline_kind' => 'date', 'deadline_date' => $day->toDateString(), 'last_day_starts_at' => $day->copy()->subHours(14), 'closes_at' => $day->copy()->addDay()->addHours(12)],
            ['category' => 'internship', 'title' => '[FICTIF] Stage d’exemple à clôture continue', 'funding' => 'partial', 'fees' => 'unspecified', 'status' => 'published', 'deadline_kind' => 'rolling'],
            ['category' => 'training', 'title' => '[FICTIF] Formation d’exemple en brouillon', 'funding' => 'none', 'fees' => 'unspecified', 'status' => 'draft', 'deadline_kind' => 'unknown'],
        ];
        foreach ($examples as $index => $example) {
            $normalized = trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($example['title']))));
            if (Opportunity::query()->where('normalized_title', $normalized)->where('campaign', $base['campaign'])->exists()) {
                continue;
            }
            $source = 'https://example.test/opportunite-fictive-'.($index + 1);
            $published = $example['status'] === 'published';
            $opportunity = Opportunity::create([...$base, ...$example, 'source_url' => $source, 'normalized_title' => $normalized,
                'normalized_organizer' => 'organisme fictif de demonstration', 'source_hash' => hash('sha256', 'example.test/opportunite-fictive-'.($index + 1).'?'),
                'verified_at' => $published ? now() : null, 'verified_by' => $published ? $editor->id : null, 'published_at' => $published ? now() : null]);
            OpportunityRevision::create(['opportunity_id' => $opportunity->id, 'version' => 1, 'action' => 'created', 'actor_id' => $editor->id,
                'note' => 'Fiche fictive de démonstration', 'snapshot' => ['status' => $opportunity->status, 'title' => $opportunity->title], 'created_at' => now()]);
        }
    }
}
