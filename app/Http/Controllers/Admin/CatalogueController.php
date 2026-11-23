<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Opportunity;
use App\Models\OpportunityReport;
use App\Models\OpportunityReportEvent;
use App\Models\OpportunityRevision;
use App\Models\User;
use App\Services\Opportunities;
use App\Services\OpportunityReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration éditoriale du catalogue, réservée au rôle d'éditeur dans son contexte plateforme (et au second facteur).
 */
class CatalogueController extends Controller
{
    public function __construct(private Opportunities $opportunities, private OpportunityReports $reports) {}

    public function index(Request $r): Response
    {
        Gate::authorize('edit-catalogue');
        $filters = $r->validate(['statut' => 'sometimes|in:'.implode(',', array_keys(Opportunity::STATUSES)), 'page' => 'sometimes|integer|min:1']);
        $list = Opportunity::query()->when(isset($filters['statut']), fn ($query) => $query->where('status', $filters['statut']))->orderByDesc('updated_at')->orderBy('id')->paginate(30)->withQueryString();

        return Inertia::render('admin/Catalogue', [
            'opportunities' => $list->getCollection()->map(fn (Opportunity $opportunity): array => ['id' => $opportunity->id, 'title' => $opportunity->title, 'organizer' => $opportunity->organizer,
                'campaign' => $opportunity->campaign, 'categoryLabel' => Opportunity::CATEGORIES[$opportunity->category], 'status' => $opportunity->status, 'statusLabel' => Opportunity::STATUSES[$opportunity->status],
                'deadline' => $this->opportunities->deadline($opportunity, false)['text'], 'updatedAt' => $opportunity->updated_at->utc()->toIso8601String()])->all(),
            'pagination' => ['page' => $list->currentPage(), 'total' => $list->lastPage(), 'previous' => $list->previousPageUrl(), 'next' => $list->nextPageUrl()],
            'filter' => $filters['statut'] ?? '', 'options' => $this->options(), 'openReports' => OpportunityReport::query()->whereIn('status', ['new', 'in_progress'])->count(),
        ]);
    }

    public function store(Request $r): RedirectResponse|JsonResponse
    {
        Gate::authorize('edit-catalogue');
        $opportunity = $this->opportunities->create($r->user(), $r->validate($this->rules()));
        if ($r->expectsJson()) {
            return response()->json(['id' => $opportunity->id, 'version' => $opportunity->lock_version, 'status' => $opportunity->status], 200, ['Cache-Control' => 'no-store, private']);
        }

        return redirect()->route('admin.catalogue.show', $opportunity->id)->with('status', 'Brouillon créé. Il n’est visible que de l’équipe éditoriale.');
    }

    public function show(Request $r, string $opportunity): Response
    {
        Gate::authorize('edit-catalogue');
        $found = Opportunity::query()->findOrFail($opportunity);
        $revisions = OpportunityRevision::query()->where('opportunity_id', $found->id)->orderByDesc('version')->limit(50)->get();
        $names = User::query()->whereIn('id', $revisions->pluck('actor_id')->push($found->verified_by)->filter()->unique())->pluck('name', 'id');
        $deadline = $this->opportunities->deadline($found);

        return Inertia::render('admin/CatalogueEdit', [
            'opportunity' => ['id' => $found->id, 'version' => $found->lock_version, 'status' => $found->status, 'statusLabel' => Opportunity::STATUSES[$found->status],
                'category' => $found->category, 'title' => $found->title, 'organizer' => $found->organizer, 'campaign' => $found->campaign, 'sourceUrl' => $found->source_url,
                'applicationUrl' => $found->application_url ?? '', 'summary' => $found->summary ?? '', 'conditions' => $found->conditions ?? '', 'countries' => $found->countries, 'modality' => $found->modality,
                'levels' => $found->levels, 'fields' => $found->fields, 'languages' => $found->languages,
                'eligibleNationalities' => $found->eligible_nationalities ?? [], 'eligibleResidences' => $found->eligible_residences ?? [], 'funding' => $found->funding, 'fees' => $found->fees, 'feesDetail' => $found->fees_detail ?? '',
                'deadline' => $deadline, 'verifiedAt' => $found->verified_at?->utc()->toIso8601String(), 'verifiedBy' => $found->verified_by ? $names[$found->verified_by] ?? null : null,
                'previousEditionId' => $found->previous_edition_id,
                'nextEditions' => Opportunity::query()->where('previous_edition_id', $found->id)->get(['id', 'campaign'])->map(fn (Opportunity $next): array => ['id' => $next->id, 'campaign' => $next->campaign])->all()],
            'revisions' => $revisions->map(fn (OpportunityRevision $revision): array => ['version' => $revision->version, 'action' => OpportunityRevision::ACTIONS[$revision->action] ?? $revision->action,
                'actor' => $revision->actor_id ? $names[$revision->actor_id] ?? 'Compte supprimé' : 'Automatique', 'note' => $revision->note, 'at' => $revision->created_at->utc()->toIso8601String(),
                'status' => Opportunity::STATUSES[$revision->snapshot['status']] ?? $revision->snapshot['status']])->all(),
            'options' => $this->options(),
        ]);
    }

    public function update(Request $r, string $opportunity): RedirectResponse|JsonResponse
    {
        Gate::authorize('edit-catalogue');
        $data = $r->validate(['expected_version' => 'required|integer|min:1', 'attest' => 'sometimes|boolean', ...$this->rules()]);
        $result = $this->opportunities->update($r->user(), $opportunity, $data);

        return $this->answer($r, $result['opportunity'], $result['replayed'] ? 'Aucun changement à enregistrer.' : 'Fiche enregistrée. La révision est conservée.');
    }

    public function transition(Request $r, string $opportunity): RedirectResponse|JsonResponse
    {
        Gate::authorize('edit-catalogue');
        $data = $r->validate(['action' => 'required|in:submit,return,verify,reverify,suspend,resume,archive', 'expected_version' => 'required|integer|min:1', 'attest' => 'sometimes|boolean', 'note' => 'nullable|string|max:500']);
        $changed = $this->opportunities->transition($r->user(), $opportunity, $data['action'], (int) $data['expected_version'], (bool) ($data['attest'] ?? false), $data['note'] ?? null);

        return $this->answer($r, $changed, 'État de la fiche : '.Opportunity::STATUSES[$changed->status].'.');
    }

    public function edition(Request $r, string $opportunity): RedirectResponse|JsonResponse
    {
        Gate::authorize('edit-catalogue');
        $data = $r->validate(['campaign' => 'required|string|max:60']);
        $edition = $this->opportunities->newEdition($r->user(), $opportunity, $data['campaign']);

        return $this->answer($r, $edition, 'Nouvelle édition créée en brouillon. Les dates et la vérification sont à établir de nouveau.');
    }

    public function reports(Request $r): Response
    {
        Gate::authorize('edit-catalogue');
        $filters = $r->validate(['statut' => 'sometimes|in:'.implode(',', array_keys(OpportunityReport::STATUSES)), 'page' => 'sometimes|integer|min:1']);
        $list = OpportunityReport::query()->when(isset($filters['statut']), fn ($query) => $query->where('status', $filters['statut']), fn ($query) => $query->whereIn('status', ['new', 'in_progress']))
            ->with('opportunity:id,title,status,campaign')->orderBy('created_at')->orderBy('id')->paginate(30)->withQueryString();
        $events = OpportunityReportEvent::query()->whereIn('opportunity_report_id', $list->getCollection()->pluck('id'))->orderBy('id')->get()->groupBy('opportunity_report_id');
        $names = User::query()->whereIn('id', $list->getCollection()->pluck('assignee_id')->merge($events->flatten()->where('action', '!=', 'created')->pluck('actor_id'))->filter()->unique())->pluck('name', 'id');

        return Inertia::render('admin/CatalogueReports', [
            // L'identité du signalant n'est jamais transmise à l'écran, pas même à l'équipe éditoriale.
            'reports' => $list->getCollection()->map(fn (OpportunityReport $report): array => ['id' => $report->id, 'version' => $report->lock_version, 'reason' => OpportunityReport::REASONS[$report->reason],
                'message' => $report->message, 'status' => $report->status, 'statusLabel' => OpportunityReport::STATUSES[$report->status], 'createdAt' => $report->created_at->utc()->toIso8601String(),
                'assignee' => $report->assignee_id ? $names[$report->assignee_id] ?? null : null, 'decision' => $report->decision ? OpportunityReport::DECISIONS[$report->decision] : null,
                'mine' => $report->reporter_id === $r->user()->id,
                'opportunity' => ['id' => $report->opportunity->id, 'title' => $report->opportunity->title, 'campaign' => $report->opportunity->campaign, 'statusLabel' => Opportunity::STATUSES[$report->opportunity->status]],
                'history' => $events->get($report->id, collect())->map(fn (OpportunityReportEvent $event): array => ['action' => $event->action, 'actor' => $event->action === 'created' ? 'Signalant' : ($names[$event->actor_id] ?? 'Éditeur'),
                    'comment' => $event->internal_comment, 'to' => $event->to_status ? OpportunityReport::STATUSES[$event->to_status] : null, 'at' => $event->created_at->utc()->toIso8601String()])->values()->all()])->all(),
            'pagination' => ['page' => $list->currentPage(), 'total' => $list->lastPage(), 'previous' => $list->previousPageUrl(), 'next' => $list->nextPageUrl()],
            'filter' => $filters['statut'] ?? '', 'statuses' => OpportunityReport::STATUSES, 'decisions' => OpportunityReport::DECISIONS,
        ]);
    }

    public function handleReport(Request $r, int $report): RedirectResponse|JsonResponse
    {
        Gate::authorize('edit-catalogue');
        $data = $r->validate(['action' => 'required|in:assign,comment,decide', 'expected_version' => 'required|integer|min:1',
            'comment' => 'nullable|string|max:1000|required_if:action,comment', 'decision' => 'required_if:action,decide|in:'.implode(',', array_keys(OpportunityReport::DECISIONS))]);
        $handled = match ($data['action']) {
            'assign' => $this->reports->assign($r->user(), $report, (int) $data['expected_version']),
            'comment' => $this->reports->comment($r->user(), $report, (int) $data['expected_version'], (string) $data['comment']),
            default => $this->reports->decide($r->user(), $report, (int) $data['expected_version'], $data['decision'], $data['comment'] ?? null),
        };
        if ($r->expectsJson()) {
            return response()->json(['id' => $handled->id, 'version' => $handled->lock_version, 'status' => $handled->status, 'decision' => $handled->decision], 200, ['Cache-Control' => 'no-store, private']);
        }

        return back()->with('status', 'Signalement mis à jour : '.OpportunityReport::STATUSES[$handled->status].'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $in = fn (array $values): string => 'in:'.implode(',', array_keys($values));

        return [
            'category' => 'required|'.$in(Opportunity::CATEGORIES), 'title' => 'required|string|max:200', 'organizer' => 'required|string|max:200', 'campaign' => 'required|string|max:60',
            'source_url' => 'required|string|max:2000', 'application_url' => 'nullable|string|max:2000', 'summary' => 'nullable|string|max:2000', 'conditions' => 'nullable|string|max:5000',
            'countries' => 'present|array|max:30', 'countries.*' => ['string', 'regex:/^[A-Za-z]{2}$/'], 'modality' => 'required|'.$in(Opportunity::MODALITIES),
            'levels' => 'present|array|max:6', 'levels.*' => $in(Opportunity::LEVELS), 'fields' => 'present|array|max:10', 'fields.*' => 'string|max:60',
            'languages' => 'present|array|max:10', 'languages.*' => ['string', 'regex:/^[A-Za-z]{2}$/'],
            'eligible_nationalities' => 'sometimes|array|max:60', 'eligible_nationalities.*' => ['string', 'regex:/^[A-Za-z]{2}$/'],
            'eligible_residences' => 'sometimes|array|max:60', 'eligible_residences.*' => ['string', 'regex:/^[A-Za-z]{2}$/'], 'funding' => 'required|'.$in(Opportunity::FUNDING), 'fees' => 'required|'.$in(Opportunity::FEES),
            'fees_detail' => 'nullable|string|max:300', 'deadline_kind' => 'required|'.$in(Opportunity::DEADLINES),
            // Instant : heure locale ET fuseau obligatoires, aucun fuseau par défaut. Date seule : la date, sans heure.
            'deadline_local' => 'nullable|required_if:deadline_kind,instant|date_format:Y-m-d\TH:i', 'deadline_timezone' => 'nullable|required_if:deadline_kind,instant|timezone:all',
            'deadline_date' => 'nullable|required_if:deadline_kind,date|date_format:Y-m-d', 'confirm_same_source' => 'sometimes|boolean',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return ['categories' => Opportunity::CATEGORIES, 'modalities' => Opportunity::MODALITIES, 'levels' => Opportunity::LEVELS, 'funding' => Opportunity::FUNDING, 'fees' => Opportunity::FEES,
            'deadlines' => Opportunity::DEADLINES, 'timezones' => timezone_identifiers_list(), 'dateRule' => Opportunities::DATE_RULE, 'minConditions' => Opportunities::MIN_CONDITIONS];
    }

    private function answer(Request $r, Opportunity $opportunity, string $message): RedirectResponse|JsonResponse
    {
        if ($r->expectsJson()) {
            return response()->json(['id' => $opportunity->id, 'version' => $opportunity->lock_version, 'status' => $opportunity->status], 200, ['Cache-Control' => 'no-store, private']);
        }

        return redirect()->route('admin.catalogue.show', $opportunity->id)->with('status', $message);
    }
}
