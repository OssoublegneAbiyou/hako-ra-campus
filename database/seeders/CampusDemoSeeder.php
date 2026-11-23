<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Institution;
use App\Models\Membership;
use App\Models\Period;
use App\Models\PersonalSpace;
use App\Models\Subject;
use App\Models\TeachingAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class CampusDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])
            || config('database.default') !== 'mysql'
            || ! in_array(config('database.connections.mysql.database'), ['hako_ra_campus_dev', 'hako_ra_campus_test'], true)) {
            throw new RuntimeException('Fixtures réservées aux bases locales Campus dédiées.');
        }

        DB::transaction(function (): void {
            $institutions = [];
            $years = [];
            $groups = [];
            $subjects = [];
            foreach (['A', 'B'] as $letter) {
                $institutions[$letter] = Institution::firstOrCreate(['code' => 'CAMPUS-FICTIF-'.$letter], [
                    'name' => 'Établissement fictif '.$letter, 'country_code' => 'CI', 'timezone' => 'Africa/Abidjan',
                ]);
                $years[$letter] = AcademicYear::firstOrCreate([
                    'institution_id' => $institutions[$letter]->id, 'code' => '2026-2027',
                ], ['name' => 'Année fictive 2026–2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-08-31']);
                Period::firstOrCreate([
                    'institution_id' => $institutions[$letter]->id, 'academic_year_id' => $years[$letter]->id, 'code' => 'S1',
                ], ['name' => 'Semestre fictif 1', 'starts_on' => '2026-09-01', 'ends_on' => '2027-01-31', 'weight' => 1]);
                $groups[$letter] = Group::firstOrCreate([
                    'institution_id' => $institutions[$letter]->id, 'academic_year_id' => $years[$letter]->id, 'code' => $letter.'-G1',
                ], ['name' => 'Groupe fictif '.$letter.' 1']);
                $subjects[$letter] = Subject::firstOrCreate(['institution_id' => $institutions[$letter]->id, 'code' => 'METHODO'],
                    ['name' => 'Méthodologie fictive']);
            }
            Group::firstOrCreate([
                'institution_id' => $institutions['A']->id, 'academic_year_id' => $years['A']->id, 'code' => 'A-G2',
            ], ['name' => 'Groupe fictif A 2, sans enseignant affecté']);

            $users = [];
            foreach ([
                'independant' => 'Indépendant fictif', 'apprenant-double' => 'Apprenant fictif A et B',
                'enseignant-a' => 'Enseignant fictif A', 'admin-a' => 'Administrateur fictif A',
                'admin-b' => 'Administrateur fictif B', 'editeur' => 'Éditeur fictif catalogue',
                'polyvalent' => 'Apprenant fictif A et enseignant B', 'plateforme' => 'Administrateur fictif plateforme',
            ] as $key => $name) {
                $users[$key] = User::firstOrCreate(['email' => $key.'@example.test'], [
                    'name' => $name, 'password' => Hash::make(Str::random(64)), 'email_verified_at' => null,
                ]);
                PersonalSpace::firstOrCreate(['owner_id' => $users[$key]->id], [
                    'timezone' => 'Africa/Abidjan', 'preferences' => ['study_goal' => 'Objectif fictif privé de '.$key],
                ]);
            }

            foreach ([
                ['apprenant-double', 'A', 'learner', 'MAT-001'],
                ['apprenant-double', 'B', 'learner', 'MAT-001'],
                ['enseignant-a', 'A', 'teacher', null],
                ['admin-a', 'A', 'institution_admin', null],
                ['admin-b', 'B', 'institution_admin', null],
                ['polyvalent', 'A', 'learner', 'MAT-002'],
                ['polyvalent', 'B', 'teacher', null],
            ] as [$key, $letter, $role, $identifier]) {
                $membership = Membership::firstOrCreate([
                    'user_id' => $users[$key]->id, 'scope' => 'institution', 'institution_id' => $institutions[$letter]->id,
                    'academic_year_id' => $years[$letter]->id, 'role' => $role,
                ], ['status' => 'active', 'starts_at' => '2026-09-01 00:00:00', 'local_identifier' => $identifier]);
                $identity = ['membership_id' => $membership->id, 'group_id' => $groups[$letter]->id];
                $values = [
                    'user_id' => $users[$key]->id, 'institution_id' => $institutions[$letter]->id,
                    'academic_year_id' => $years[$letter]->id, 'starts_at' => '2026-09-01 00:00:00',
                ];
                if ($role === 'learner') {
                    Enrollment::firstOrCreate($identity, $values);
                } elseif ($role === 'teacher') {
                    TeachingAssignment::firstOrCreate([...$identity, 'subject_id' => $subjects[$letter]->id], $values);
                }
            }
            foreach (['editeur' => 'catalogue_editor', 'plateforme' => 'platform_admin'] as $key => $role) {
                Membership::firstOrCreate(['user_id' => $users[$key]->id, 'scope' => 'platform', 'role' => $role,
                    'institution_id' => null, 'academic_year_id' => null], ['status' => 'active', 'starts_at' => '2026-09-01 00:00:00']);
            }
        });
    }
}
