<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

abstract class CampusReference extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'scope_key', 'current_slot', 'current_local_identifier'];

    protected array $immutableContext = [];

    protected static function booted(): void
    {
        static::saving(function (CampusReference $model): void {
            foreach ($model->immutableContext as $field) {
                $model->ensure(! $model->exists || ! $model->isDirty($field), $field, 'Le rattachement ne peut pas être déplacé. Clôturez ou archivez la référence.');
            }
            Validator::make($model->attributesToArray(), $model->validationRules())->validate();
            $model->validateContext();
        });
    }

    protected function validationRules(): array
    {
        return [];
    }

    protected function validateContext(): void {}

    protected function ensure(bool $condition, string $field, string $message = 'Le rattachement est incompatible ou indisponible.'): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    protected function validateYear(): AcademicYear
    {
        $year = AcademicYear::query()->where('institution_id', $this->institution_id)->find($this->academic_year_id);
        $this->ensure($year !== null, 'academic_year_id');
        if (! $this->exists) {
            $this->ensure($year->archived_at === null && $year->institution->archived_at === null, 'academic_year_id');
        }

        return $year;
    }

    protected function validateInstitution(): void
    {
        $this->ensure(Institution::query()->when(! $this->exists, fn ($query) => $query->whereNull('archived_at'))->whereKey($this->institution_id)->exists(), 'institution_id');
    }
}
