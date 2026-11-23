<?php

namespace App\Services;

use App\Models\CourseOccurrence;
use App\Models\CourseSeries;
use App\Models\Enrollment;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\PersonalSpace;
use App\Models\PublishedOccurrence;
use App\Support\PrivilegedAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ScheduleAccess
{
    public function __construct(private ActiveContext $context, private ContextVisibility $visibility) {}

    public function acting(Request $r, bool $write = false): ?Membership
    {
        $m = $this->context->current($r);
        // Un contexte plateforme n'a pas de planning propre : la personne y retrouve son planning personnel.
        if (! $m || $m->scope !== 'institution') {
            return null;
        }
        if ($m->role === 'institution_admin') {
            abort_unless(PrivilegedAccess::available(), 403);
        }
        if ($write) {
            abort_unless($m->role === 'institution_admin' && PrivilegedAccess::available(), 403);
        }

        return $this->visibility->institutionMembership($r->user(), $m->id);
    }

    public function lock(Request $r): ?Membership
    {
        $m = $this->acting($r, true);
        if ($m) {
            Institution::query()->lockForUpdate()->findOrFail($m->institution_id);
            Membership::query()->lockForUpdate()->findOrFail($m->id);

            return $this->visibility->institutionMembership($r->user(), $m->id);
        }
        $space = PersonalSpace::query()->where('owner_id', $r->user()->id)->lockForUpdate()->firstOrFail();
        $this->visibility->personalSpace($r->user(), $space->id);

        return null;
    }

    public function occurrences(Request $r): Builder
    {
        $m = $this->acting($r);
        if (! $m) {
            return CourseOccurrence::query()->where('scope', 'personal')->where('owner_id', $r->user()->id);
        }
        $q = CourseOccurrence::query()->where('scope', 'institution')->where('institution_id', $m->institution_id)->where('academic_year_id', $m->academic_year_id);
        if ($m->role === 'learner') {
            return $q->whereRaw('1=0');
        }
        if ($m->role === 'teacher') {
            $ids = $this->visibility->groups($r->user(), $m->id)->pluck('id');
            $q->where('teacher_membership_id', $m->id)->whereHas('groups', fn ($b) => $b->whereIn('groups.id', $ids));
        }

        return $q;
    }

    /**
     * Planning publié visible par le contexte actif : l'administration voit son année, l'enseignant ses cours,
     * l'apprenant les cours de ses groupes effectifs. Les lignes retirées de la publication ne sont pas listées.
     */
    public function published(Request $r): Builder
    {
        $m = $this->acting($r);
        abort_unless($m !== null, 404);
        $q = PublishedOccurrence::query()->where('institution_id', $m->institution_id)->where('academic_year_id', $m->academic_year_id)
            ->whereIn('status', ['active', 'cancelled']);
        if ($m->role === 'teacher') {
            $q->where('teacher_membership_id', $m->id);
        } elseif ($m->role !== 'institution_admin') {
            $ids = $this->visibility->groups($r->user(), $m->id)->pluck('id');
            $q->whereHas('groups', fn ($b) => $b->whereIn('groups.id', $ids));
        }

        return $q;
    }

    /**
     * Comptes actuellement autorisés à être informés d'un changement : apprenants effectivement inscrits
     * dans les groupes concernés et enseignants désignés, appartenance et compte actifs au moment de l'appel.
     *
     * @param  list<int>  $groupIds
     * @param  list<int>  $teacherMembershipIds
     * @return Collection<int, int>
     */
    public function recipients(int $institutionId, int $academicYearId, array $groupIds, array $teacherMembershipIds): Collection
    {
        $usable = fn ($query) => $query->effective()->where('institution_id', $institutionId)->where('academic_year_id', $academicYearId)
            ->whereHas('user', fn ($user) => $user->where('status', 'active'));
        $learners = Enrollment::query()->effective()->whereIn('group_id', $groupIds)->where('institution_id', $institutionId)
            ->where('academic_year_id', $academicYearId)->whereHas('membership', $usable)->pluck('user_id');
        $teachers = $usable(Membership::query()->where('role', 'teacher')->whereIn('id', $teacherMembershipIds))->pluck('user_id');

        return $learners->merge($teachers)->unique()->sort()->values();
    }

    public function series(Request $r): Builder
    {
        $m = $this->acting($r);
        if (! $m) {
            return CourseSeries::query()->where('scope', 'personal')->where('owner_id', $r->user()->id);
        }
        $q = CourseSeries::query()->where('scope', 'institution')->where('institution_id', $m->institution_id)->where('academic_year_id', $m->academic_year_id);
        if ($m->role !== 'institution_admin') {
            $q->whereHas('occurrences', fn ($b) => $b->whereIn('id', $this->occurrences($r)->select('course_occurrences.id')));
        }

        return $q;
    }
}
