<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\Membership;
use App\Services\AcademicAdministration;
use App\Services\ContextVisibility;
use App\Support\PrivilegedAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AcademicReferenceController extends Controller
{
    public function index(Request $r, AcademicAdministration $service): Response
    {
        $filters = $r->validate(['kind' => 'sometimes|in:years,periods,groups,subjects,types,enrollments,assignments', 'q' => 'nullable|string|max:100', 'page' => 'sometimes|integer|min:1', 'member_q' => 'nullable|string|max:100', 'option_q' => 'nullable|string|max:100']);
        $kind = $filters['kind'] ?? 'subjects';
        $acting = $service->acting($r);
        $editable = $acting === null || ($acting->role === 'institution_admin' && PrivilegedAccess::available());
        $q = $service->query($r, $kind);
        if (! empty($filters['q'])) {
            if (in_array($kind, ['enrollments', 'assignments'], true)) {
                $q->whereHas('user', fn ($b) => $b->where('name', 'like', '%'.$filters['q'].'%'));
            } else {
                $q->where(fn ($b) => $b->where('name', 'like', '%'.$filters['q'].'%')->orWhere('code', 'like', '%'.$filters['q'].'%'));
            }
        }
        if (in_array($kind, ['enrollments', 'assignments'], true)) {
            $q->with(['user:id,name', 'group:id,name']);
        }
        $rows = $q->orderByDesc('id')->paginate(20)->withQueryString();
        $options = [];
        foreach (['groups', 'subjects', 'periods', 'types'] as $key) {
            if (! $acting && $key === 'groups') {
                continue;
            }
            $options[$key] = $service->query($r, $key)->whereNull('archived_at')->when(! empty($filters['option_q']), fn ($b) => $b->where(fn ($search) => $search->where('name', 'like', '%'.$filters['option_q'].'%')->orWhere('code', 'like', '%'.$filters['option_q'].'%')))->orderBy('name')->limit(200)->get(['id', 'name', 'code']);
        }
        $members = $acting && $editable ? Membership::query()->effective()->where('institution_id', $acting->institution_id)->where('academic_year_id', $acting->academic_year_id)
            ->whereIn('role', ['learner', 'teacher'])->whereHas('user', fn ($b) => $b->where('name', 'like', '%'.($filters['member_q'] ?? '').'%'))->with('user:id,name')->orderBy('id')->limit(200)->get()
            ->map(fn ($m) => ['id' => $m->id, 'name' => $m->user->name.' — '.Membership::ROLE_LABELS[$m->role], 'role' => $m->role])->all() : [];

        return Inertia::render('AcademicReferences', [
            'optionSearch' => $filters['option_q'] ?? '', 'totalPages' => $rows->lastPage(), 'kind' => $kind, 'search' => $filters['q'] ?? '', 'memberSearch' => $filters['member_q'] ?? '',
            'institutional' => $acting !== null, 'editable' => $editable,
            'timezone' => $acting?->institution->timezone ?? config('app.display_timezone'),
            'institutionVersion' => $acting?->institution->lock_version,
            'year' => $acting?->academicYear->name,
            'rows' => collect($rows->items())->map(fn ($m) => [
                'id' => $m->id, 'code' => $m->code ?? '', 'name' => $m->name ?? ($m->user->name.' — '.$m->group->name),
                'starts_on' => $m->starts_on?->format('Y-m-d') ?? '', 'ends_on' => $m->ends_on?->format('Y-m-d') ?? '',
                'starts_at' => $m->starts_at?->toIso8601String(), 'ends_at' => $m->ends_at?->toIso8601String(), 'closed_at' => $m->closed_at?->toIso8601String(),
                'timezone' => $m->timezone, 'archived' => $m->archived_at !== null, 'status' => $m->status, 'lock_version' => $m->lock_version,
                'parent_period_id' => $m->parent_period_id, 'period_type_id' => $m->period_type_id, 'weight' => $m->weight ?? 1,
                'membership_id' => $m->membership_id, 'group_id' => $m->group_id,
            ])->all(),
            'options' => $options, 'members' => $members, 'currentPage' => $rows->currentPage(), 'previousHref' => $rows->previousPageUrl(), 'nextHref' => $rows->nextPageUrl(),
        ]);
    }

    public function store(Request $r, AcademicAdministration $service, string $kind): RedirectResponse
    {
        $service->save($r, $kind);

        return back()->with('status', 'Référence enregistrée. Pour une nouvelle année, sélectionnez son contexte dans l’en-tête.');
    }

    public function update(Request $r, AcademicAdministration $service, string $kind, int $reference): RedirectResponse
    {
        $service->save($r, $kind, $reference);

        return back()->with('status', 'Modification enregistrée. L’historique est conservé.');
    }

    public function timezone(Request $r, AcademicAdministration $service): RedirectResponse
    {
        $acting = $service->acting($r, true);
        abort_unless($acting, 403);
        $d = $r->validate(['timezone' => 'required|timezone', 'lock_version' => 'required|integer|min:1']);
        DB::transaction(function () use ($acting, $d, $r): void {
            $institution = Institution::query()->lockForUpdate()->findOrFail($acting->institution_id);
            Membership::query()->lockForUpdate()->findOrFail($acting->id);
            app(ContextVisibility::class)->institutionMembership($r->user(), $acting->id);
            abort_unless((int) $institution->lock_version === (int) $d['lock_version'], 409);
            $institution->forceFill(['timezone' => $d['timezone'], 'lock_version' => $institution->lock_version + 1])->save();
            AuditLog::record($r->user(), 'academic.timezone.updated', $institution, $institution->id);
        });

        return back()->with('status', 'Fuseau enregistré. Les instants historiques UTC restent inchangés.');
    }
}
