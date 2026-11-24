<?php

namespace App\Services;

use App\Models\AcademicPeriodType;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\Period;
use App\Models\PersonalPeriod;
use App\Models\PersonalSpace;
use App\Models\PersonalSubject;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Support\PrivilegedAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AcademicAdministration
{
    public const MODELS = ['years' => AcademicYear::class, 'periods' => Period::class, 'groups' => Group::class, 'subjects' => Subject::class, 'types' => AcademicPeriodType::class, 'enrollments' => Enrollment::class, 'assignments' => TeachingAssignment::class];

    public function __construct(private ActiveContext $context, private ContextVisibility $visibility) {}

    public function acting(Request $request, bool $write = false): ?Membership
    {
        $actor = $this->context->current($request);
        // Un contexte plateforme n'a pas de référentiel propre : la personne y retrouve ses références personnelles.
        if ($actor === null || $actor->scope !== 'institution') {
            return null;
        }
        abort_if($actor->role === 'institution_admin' && ! PrivilegedAccess::available(), 403);
        if ($write) {
            abort_unless($actor->role === 'institution_admin' && PrivilegedAccess::available(), 403);
        }

        return $actor;
    }

    public function query(Request $r, string $kind): Builder
    {
        $acting = $this->acting($r);
        abort_unless(isset(self::MODELS[$kind]), 404);
        if (! $acting) {
            $model = match ($kind) {
                'periods' => PersonalPeriod::class,'subjects' => PersonalSubject::class,'types' => AcademicPeriodType::class,default => null
            };
            abort_unless($model !== null, 403);

            return $model::query()->where('owner_id', $r->user()->id);
        }
        $q = self::MODELS[$kind]::query()->where('institution_id', $acting->institution_id);
        if ($kind === 'years') {
            $q->whereKey($acting->academic_year_id);
        } elseif (! in_array($kind, ['subjects', 'types'], true)) {
            $q->where('academic_year_id', $acting->academic_year_id);
        }
        if ($acting->role !== 'institution_admin') {
            abort_unless(in_array($kind, ['groups', 'subjects', 'periods', 'types'], true), 403);
            $visible = $this->visibility->groups($r->user(), $acting->id)->pluck('id');
            if ($kind === 'groups') {
                $q->whereIn('id', $visible);
            }
            if ($kind === 'subjects') {
                $ids = TeachingAssignment::query()->effective()->whereIn('group_id', $visible)
                    ->when($acting->role === 'teacher', fn ($b) => $b->where('membership_id', $acting->id))->pluck('subject_id');
                $q->whereIn('id', $ids);
            }
        }

        return $q;
    }

    public function save(Request $r, string $kind, ?int $id = null): Model
    {
        $acting = $this->acting($r, true);
        abort_unless(isset(self::MODELS[$kind]), 404);
        try {
            return DB::transaction(function () use ($r, $kind, $id, $acting): Model {
                if ($acting) {
                    Institution::query()->lockForUpdate()->findOrFail($acting->institution_id);
                    Membership::query()->lockForUpdate()->findOrFail($acting->id);
                    $acting = $this->visibility->institutionMembership($r->user(), $acting->id);
                    abort_unless($acting->role === 'institution_admin', 403);
                    $year = AcademicYear::query()->findOrFail($acting->academic_year_id);
                    abort_if($year->archived_at !== null, 403);
                } else {
                    $space = PersonalSpace::query()->where('owner_id', $r->user()->id)->lockForUpdate()->firstOrFail();
                    $this->visibility->personalSpace($r->user(), $space->id);
                }
                $q = $this->query($r, $kind);
                $model = $id ? $q->lockForUpdate()->findOrFail($id) : $q->getModel()->newInstance();
                if ($id) {
                    $r->validate(['lock_version' => 'required|integer|min:1']);
                    abort_unless((int) $r->input('lock_version') === (int) $model->lock_version, 409, 'La référence a changé. Rechargez la page.');
                    abort_if($model->archived_at !== null, 409, 'Cette référence est archivée.');
                }
                $action = $r->input('action', 'save');
                if ($action === 'archive') {
                    abort_unless($id && ! in_array($kind, ['enrollments', 'assignments'], true), 422);
                    if ($kind === 'periods') {
                        $child = $this->query($r, 'periods')->where('parent_period_id', $id)->whereNull('archived_at')->exists();
                        $this->valid(! $child, 'action', 'Archivez d’abord les sous-périodes.');
                    }
                    $model->archived_at = now();
                } elseif ($action === 'close') {
                    abort_unless($id && in_array($kind, ['enrollments', 'assignments'], true), 422);
                    abort_if($model->status !== 'active', 409);
                    $model->status = 'closed';
                    $model->closed_at = now();
                    if ($model->starts_at->lt(now())) {
                        $model->ends_at = now();
                    }
                } else {
                    $this->valid($action === 'save', 'action', 'Action inconnue.');
                    if (in_array($kind, ['enrollments', 'assignments'], true)) {
                        $this->assignment($r, $model, $kind, $acting, $id);
                    } else {
                        // Le code n'est pas demandé à la création : il est tiré du nom. Il reste modifiable ensuite.
                        $generated = ! $id && trim((string) $r->input('code')) === '';
                        $data = $r->validate(['code' => ($generated ? 'nullable' : 'required').'|string|max:40', 'name' => 'required|string|max:255']);
                        if ($generated) {
                            $data['code'] = $this->codeFromName(clone $q, $data['name']);
                        }
                        $this->valid(! (clone $q)->where('code', $data['code'])->when($id, fn ($b) => $b->where('id', '!=', $id))->exists(), 'code', 'Ce code est déjà utilisé dans ce périmètre.');
                        $model->fill($data);
                        if (! $id) {
                            if ($acting) {
                                $model->institution_id = $acting->institution_id;
                                if (in_array($kind, ['periods', 'groups'], true)) {
                                    $model->academic_year_id = $acting->academic_year_id;
                                }
                            } else {
                                $model->owner_id = $r->user()->id;
                            }
                        }
                        if (in_array($kind, ['years', 'periods'], true)) {
                            $this->dates($r, $model, $kind, $acting);
                        }
                    }
                }
                if ($id) {
                    $model->lock_version++;
                }
                $model->save();
                if (! $id && $kind === 'years') {
                    $this->newYear($r, $acting, $model);
                }
                AuditLog::record($r->user(), 'academic.'.$kind.'.'.$action, $model, $acting?->institution_id);

                return $model;
            }, 3);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw ValidationException::withMessages(['code' => 'Cette référence ou affectation existe déjà.']);
            }
            throw $e;
        }
    }

    /**
     * Code d'une référence tiré de son nom : trois lettres par mot significatif, trois mots au plus (« Histoire-géographie » donne HIS-GEO),
     * quatre lettres pour un seul mot (« Mathématiques » donne MATH) ; un mot qui contient un chiffre est gardé tel quel (« Trimestre 1 » donne TRI-1,
     * « 2026-2027 » reste 2026-2027). Un numéro est ajouté si le code existe déjà dans le périmètre.
     */
    private function codeFromName(Builder $scope, string $name): string
    {
        $ignored = ['DE', 'DU', 'DES', 'D', 'LA', 'LE', 'LES', 'L', 'ET', 'EN', 'A', 'AU', 'AUX'];
        $words = array_values(array_filter(preg_split('/[^A-Z0-9]+/', Str::upper(Str::ascii($name))) ?: [], fn (string $word): bool => $word !== '' && ! in_array($word, $ignored, true)));
        $short = fn (string $word, int $letters): string => preg_match('/\d/', $word) === 1 ? substr($word, 0, 8) : substr($word, 0, $letters);
        $base = match (count($words)) {
            0 => 'REF',
            1 => $short($words[0], 4),
            default => implode('-', array_map(fn (string $word): string => $short($word, 3), array_slice($words, 0, 3))),
        };
        $taken = $scope->where('code', 'like', $base.'%')->pluck('code')->map(fn (string $code): string => Str::upper($code))->flip();
        $code = $base;
        for ($number = 2; isset($taken[$code]); $number++) {
            $code = $base.'-'.$number;
        }

        return $code;
    }

    private function valid(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function dates(Request $r, Model $model, string $kind, ?Membership $acting): void
    {
        $d = $r->validate(['starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on']);
        $model->fill($d);
        if ($kind === 'years') {
            $overlap = AcademicYear::query()->where('institution_id', $acting->institution_id)->whereNull('archived_at')
                ->where('id', '!=', $model->id ?? 0)->where('starts_on', '<=', $d['ends_on'])->where('ends_on', '>=', $d['starts_on'])->exists();
            $this->valid(! $overlap, 'starts_on', 'Les années actives ne doivent pas se chevaucher.');

            return;
        }
        $extra = $r->validate(['parent_period_id' => 'nullable|integer', 'period_type_id' => 'nullable|integer', 'weight' => 'required|numeric|gt:0', 'timezone' => 'sometimes|required|timezone']);
        $model->fill(array_intersect_key($extra, array_flip(['parent_period_id', 'period_type_id', 'weight'])));
        if (! $acting) {
            $model->timezone = $extra['timezone'] ?? config('app.display_timezone');
        }
        if ($model->period_type_id) {
            $this->query($r, 'types')->whereNull('archived_at')->findOrFail($model->period_type_id);
        }
        $periods = $this->query($r, 'periods');
        if ($model->parent_period_id) {
            $parent = (clone $periods)->whereNull('archived_at')->findOrFail($model->parent_period_id);
            $this->valid($acting !== null || $parent->timezone === $model->timezone, 'timezone', 'Une sous-période personnelle utilise le fuseau de son parent.');
            $this->valid($parent->id !== $model->id && $d['starts_on'] >= $parent->starts_on->format('Y-m-d') && $d['ends_on'] <= $parent->ends_on->format('Y-m-d'), 'starts_on', 'La sous-période doit rester dans son parent explicite.');
        }
        $overlap = (clone $periods)->whereNull('archived_at')->where('id', '!=', $model->id ?? 0)
            ->where('parent_period_id', $model->parent_period_id)->where('starts_on', '<=', $d['ends_on'])->where('ends_on', '>=', $d['starts_on'])->exists();
        $this->valid(! $overlap, 'starts_on', 'Deux périodes de même parent ne doivent pas se chevaucher.');
        if ($model->exists) {
            $outside = (clone $periods)->where('parent_period_id', $model->id)->where(fn ($b) => $b->where('starts_on', '<', $d['starts_on'])->orWhere('ends_on', '>', $d['ends_on']))->exists();
            $this->valid(! $outside, 'ends_on', 'Les sous-périodes doivent rester dans leur parent.');
        }
    }

    private function assignment(Request $r, Model $model, string $kind, Membership $acting, ?int $id): void
    {
        abort_if($id !== null, 422, 'Clôturez la relation pour en créer une nouvelle.');
        $d = $r->validate(['membership_id' => 'required|integer', 'group_id' => 'required|integer', 'subject_id' => ($kind === 'assignments' ? 'required' : 'nullable').'|integer',
            'starts_at' => 'required|date_format:Y-m-d\TH:i', 'ends_at' => 'nullable|date_format:Y-m-d\TH:i|after:starts_at', 'replace_enrollment_id' => 'nullable|integer']);
        $member = Membership::query()->effective()->where('institution_id', $acting->institution_id)->where('academic_year_id', $acting->academic_year_id)
            ->where('role', $kind === 'enrollments' ? 'learner' : 'teacher')->lockForUpdate()->findOrFail($d['membership_id']);
        $this->query($r, 'groups')->whereNull('archived_at')->findOrFail($d['group_id']);
        if ($kind === 'assignments') {
            $this->query($r, 'subjects')->whereNull('archived_at')->findOrFail($d['subject_id']);
        }
        $tz = $acting->institution->timezone;
        $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $d['starts_at'], $tz)->utc();
        $end = empty($d['ends_at']) ? null : CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $d['ends_at'], $tz)->utc();
        $year = $acting->academicYear;
        $this->valid($start->setTimezone($tz)->toDateString() >= $year->starts_on->toDateString() && $start->setTimezone($tz)->toDateString() <= $year->ends_on->toDateString(), 'starts_at', 'L’entrée doit appartenir à l’année académique.');
        $this->valid(! $end || ($end->setTimezone($tz)->toDateString() <= $year->ends_on->toDateString() && (! $member->ends_at || $end->lte($member->ends_at))), 'ends_at', 'La sortie dépasse les bornes autorisées.');
        if (! empty($d['replace_enrollment_id'])) {
            abort_unless($kind === 'enrollments', 422);
            $previous = $this->query($r, 'enrollments')->where('membership_id', $member->id)->where('status', 'active')->lockForUpdate()->findOrFail($d['replace_enrollment_id']);
            $this->valid($previous->group_id !== (int) $d['group_id'] && $start->gte($previous->starts_at) && $start->lte(now()), 'group_id', 'Le changement doit viser un autre groupe et être déjà effectif.');
            $previous->forceFill(['status' => 'closed', 'ends_at' => $start, 'closed_at' => now(), 'lock_version' => $previous->lock_version + 1])->save();
        }
        $overlap = $this->query($r, $kind)->where('membership_id', $member->id)->where('status', 'active')
            ->when($kind === 'assignments', fn ($b) => $b->where('group_id', $d['group_id'])->where('subject_id', $d['subject_id']))
            ->when($end, fn ($b) => $b->where('starts_at', '<', $end))->where(fn ($b) => $b->whereNull('ends_at')->orWhere('ends_at', '>', $start))->exists();
        $this->valid(! $overlap, 'membership_id', 'Une relation active existe déjà sur cette période. Clôturez-la ou utilisez le changement de groupe.');
        $model->fill(['membership_id' => $member->id, 'user_id' => $member->user_id, 'institution_id' => $acting->institution_id, 'academic_year_id' => $acting->academic_year_id, 'group_id' => $d['group_id'], 'starts_at' => $start, 'ends_at' => $end]);
        if ($kind === 'assignments') {
            $model->subject_id = $d['subject_id'];
        }
    }

    private function newYear(Request $r, Membership $acting, AcademicYear $target): void
    {
        $d = $r->validate(['copy_groups' => 'array|max:200', 'copy_groups.*' => 'integer|distinct', 'copy_periods' => 'array|max:200', 'copy_periods.*' => 'integer|distinct']);
        foreach ($d['copy_groups'] ?? [] as $id) {
            $old = $this->query($r, 'groups')->whereNull('archived_at')->findOrFail($id);
            Group::create(['institution_id' => $acting->institution_id, 'academic_year_id' => $target->id, 'code' => $old->code, 'name' => $old->name]);
        }
        $selected = array_map('intval', $d['copy_periods'] ?? []);
        $map = [];
        $pending = $this->query($r, 'periods')->whereNull('archived_at')->whereIn('id', $selected)->get();
        $this->valid($pending->count() === count($selected), 'copy_periods', 'Sélection de périodes indisponible.');
        foreach ($pending as $old) {
            $this->valid(! $old->parent_period_id || in_array($old->parent_period_id, $selected, true), 'copy_periods', 'Sélectionnez aussi chaque période parente.');
        }
        $offset = $acting->academicYear->starts_on->diffInDays($target->starts_on, false);
        while ($pending->isNotEmpty()) {
            $before = $pending->count();
            foreach ($pending as $key => $old) {
                if ($old->parent_period_id && ! isset($map[$old->parent_period_id])) {
                    continue;
                }
                $new = Period::create(['institution_id' => $acting->institution_id, 'academic_year_id' => $target->id, 'code' => $old->code, 'name' => $old->name,
                    'starts_on' => $old->starts_on->addDays($offset), 'ends_on' => $old->ends_on->addDays($offset), 'weight' => $old->weight,
                    'period_type_id' => $old->period_type_id, 'parent_period_id' => $old->parent_period_id ? $map[$old->parent_period_id] : null]);
                $map[$old->id] = $new->id;
                $pending->forget($key);
            }
            $this->valid($pending->count() < $before, 'copy_periods', 'La hiérarchie de périodes contient un cycle.');
        }
        $continuation = Membership::create(['user_id' => $r->user()->id, 'scope' => 'institution', 'institution_id' => $acting->institution_id, 'academic_year_id' => $target->id, 'role' => 'institution_admin', 'starts_at' => now()]);
        AuditLog::record($r->user(), 'membership.year_continued', $continuation, $acting->institution_id, null, ['role' => 'institution_admin', 'source_membership_id' => $acting->id]);
    }
}
