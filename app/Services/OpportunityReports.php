<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Opportunity;
use App\Models\OpportunityReport;
use App\Models\OpportunityReportEvent;
use App\Models\User;
use App\Support\PrivilegedAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Signalements de fiches et file éditoriale. Un signalement ne dépublie jamais rien par lui-même : seule une décision
 * d'un éditeur habilité, tracée, peut suspendre ou archiver une fiche. L'identité du signalant n'est jamais restituée.
 */
class OpportunityReports
{
    public const DAILY_LIMIT = 5;

    public function __construct(private ContextVisibility $visibility, private Opportunities $opportunities) {}

    /**
     * Signale une fiche publiée. Un seul signalement ouvert par personne et par fiche ; nombre de signalements par jour limité.
     */
    public function submit(User $reporter, string $opportunityId, string $reason, ?string $message): OpportunityReport
    {
        return DB::transaction(function () use ($reporter, $opportunityId, $reason, $message): OpportunityReport {
            User::query()->whereKey($reporter->id)->lockForUpdate()->firstOrFail();
            $opportunity = $this->opportunities->published()->findOrFail($opportunityId);
            $existing = OpportunityReport::query()->where('opportunity_id', $opportunity->id)->where('reporter_id', $reporter->id)->whereIn('status', ['new', 'in_progress'])->first();
            if ($existing) {
                return $existing;
            }
            if (OpportunityReport::query()->where('reporter_id', $reporter->id)->where('created_at', '>', now()->subDay())->count() >= self::DAILY_LIMIT) {
                throw new TooManyRequestsHttpException(3600, 'Trop de signalements aujourd’hui.');
            }
            $message = mb_substr(trim((string) preg_replace('/\p{C}+/u', ' ', strip_tags((string) $message))), 0, 500);
            if ($reason === 'other' && mb_strlen($message) < 10) {
                throw ValidationException::withMessages(['message' => 'Précisez le problème en quelques mots (10 caractères au moins).']);
            }
            $report = OpportunityReport::create(['opportunity_id' => $opportunity->id, 'reporter_id' => $reporter->id, 'reason' => $reason, 'message' => $message === '' ? null : $message]);
            $this->trace($report, $reporter, 'created', null, 'new');

            return $report;
        }, 3);
    }

    /**
     * État du dernier signalement de cette personne sur cette fiche, pour elle seule : ni responsable, ni commentaire interne.
     *
     * @return array{status: string, statusLabel: string, decisionLabel: string|null}|null
     */
    public function mine(User $reporter, string $opportunityId): ?array
    {
        $report = OpportunityReport::query()->where('opportunity_id', $opportunityId)->where('reporter_id', $reporter->id)->orderByDesc('id')->first();

        return $report ? ['status' => $report->status, 'statusLabel' => OpportunityReport::STATUSES[$report->status],
            'decisionLabel' => $report->decision ? OpportunityReport::DECISIONS[$report->decision] : null] : null;
    }

    /**
     * L'éditeur prend le signalement en charge : il en devient le responsable.
     */
    public function assign(User $editor, int $reportId, int $expectedVersion): OpportunityReport
    {
        return $this->change($editor, $reportId, $expectedVersion, function (OpportunityReport $report) use ($editor): void {
            $this->assertOpen($report);
            $from = $report->status;
            $report->fill(['assignee_id' => $editor->id, 'status' => 'in_progress']);
            $this->trace($report, $editor, 'assigned', $from, 'in_progress');
        });
    }

    public function comment(User $editor, int $reportId, int $expectedVersion, string $comment): OpportunityReport
    {
        return $this->change($editor, $reportId, $expectedVersion, fn (OpportunityReport $report) => $this->trace($report, $editor, 'commented', $report->status, $report->status, $comment));
    }

    /**
     * Décision sur un signalement. « Suspendue » ou « archivée » applique l'action à la fiche, dans la même transaction.
     * Le signalant ne traite jamais son propre signalement.
     */
    public function decide(User $editor, int $reportId, int $expectedVersion, string $decision, ?string $comment): OpportunityReport
    {
        return $this->change($editor, $reportId, $expectedVersion, function (OpportunityReport $report) use ($editor, $decision, $comment): void {
            $this->assertOpen($report);
            $opportunity = Opportunity::query()->findOrFail($report->opportunity_id);
            if ($decision === 'suspended' && $opportunity->status === 'published') {
                $this->opportunities->transition($editor, $opportunity->id, 'suspend', $opportunity->lock_version, false, 'Suspension décidée après signalement n° '.$report->id);
            } elseif ($decision === 'suspended' && $opportunity->status !== 'suspended') {
                throw ValidationException::withMessages(['decision' => 'Cette fiche n’est pas publiée : elle ne peut pas être suspendue.']);
            } elseif ($decision === 'archived' && $opportunity->status !== 'archived') {
                $this->opportunities->transition($editor, $opportunity->id, 'archive', $opportunity->lock_version);
            }
            $from = $report->status;
            $to = $decision === 'no_action' ? 'rejected' : 'resolved';
            $report->fill(['status' => $to, 'open_slot' => null, 'decision' => $decision, 'decided_by' => $editor->id, 'decided_at' => now(), 'assignee_id' => $report->assignee_id ?? $editor->id]);
            $this->trace($report, $editor, 'decided', $from, $to, $comment);
            // Preuve minimale du traitement dans le journal : qui, quand, quelle décision. Ni le motif du signalant ni le commentaire interne.
            AuditLog::record($editor, 'opportunity.report.decided', $report, null, null, ['opportunity_id' => $report->opportunity_id, 'decision' => $decision]);
        });
    }

    private function change(User $editor, int $reportId, int $expectedVersion, callable $apply): OpportunityReport
    {
        abort_unless(PrivilegedAccess::available() && $this->visibility->canEditCatalogue($editor), 403);

        return DB::transaction(function () use ($editor, $reportId, $expectedVersion, $apply): OpportunityReport {
            $report = OpportunityReport::query()->lockForUpdate()->findOrFail($reportId);
            // Séparation des rôles : un éditeur qui a lui-même signalé la fiche laisse un collègue traiter son signalement.
            abort_if($report->reporter_id === $editor->id, 403, 'Vous avez émis ce signalement : un autre éditeur doit le traiter.');
            if ($expectedVersion !== $report->lock_version) {
                throw new ConflictHttpException('Ce signalement a été modifié ailleurs. Rechargez la file avant de continuer.');
            }
            $apply($report);
            $report->fill(['lock_version' => $report->lock_version + 1])->save();

            return $report;
        }, 3);
    }

    private function assertOpen(OpportunityReport $report): void
    {
        if (! in_array($report->status, ['new', 'in_progress'], true)) {
            throw ValidationException::withMessages(['reference' => 'Ce signalement est déjà traité.']);
        }
    }

    private function trace(OpportunityReport $report, User $actor, string $action, ?string $from, ?string $to, ?string $comment = null): void
    {
        $comment = $comment === null ? null : mb_substr(trim((string) preg_replace('/[^\P{C}\n]+/u', ' ', strip_tags($comment))), 0, 1000);
        OpportunityReportEvent::create(['opportunity_report_id' => $report->id, 'actor_id' => $actor->id, 'action' => $action, 'from_status' => $from, 'to_status' => $to,
            'internal_comment' => $comment === null || $comment === '' ? null : $comment, 'created_at' => now()]);
    }
}
