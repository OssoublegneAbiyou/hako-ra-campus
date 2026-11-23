<?php

namespace App\Support;

use App\Models\Opportunity;
use App\Models\OpportunityProfile;
use Illuminate\Support\Str;

/**
 * Comparaison déterministe entre les critères publiés d'une fiche et ceux que la personne a déclarés.
 * Trois résultats par critère : « match » (correspond à vos critères), « check » (information à vérifier), « mismatch » (critère non satisfait).
 * Une information absente, d'un côté ou de l'autre, donne toujours « à vérifier » : elle ne prouve jamais l'inéligibilité.
 * Rien n'est déduit : seuls les critères saisis par la personne et ceux publiés sur la fiche sont comparés.
 */
class OpportunityMatcher
{
    public const LABELS = ['match' => 'Correspond à vos critères', 'check' => 'Information à vérifier', 'mismatch' => 'Critère non satisfait'];

    public const DISCLAIMER = 'Comparaison indicative entre ce que vous avez déclaré et ce que publie la fiche. Ce n’est pas une décision d’éligibilité : seul l’organisateur décide, sur la base de sa source officielle.';

    /**
     * @return array{overall: string, overallLabel: string, criteria: list<array{key: string, label: string, result: string, resultLabel: string, explanation: string}>}
     */
    public static function compare(Opportunity $opportunity, ?OpportunityProfile $profile): array
    {
        $criteria = [
            self::level($opportunity, $profile?->level),
            self::codes('language', 'Langue', $opportunity->languages ?? [], $profile?->languages ?? [], 'la fiche ne précise pas la langue', 'vous n’avez pas déclaré de langue',
                fn (string $code) => Labels::language($code)),
            self::fields($opportunity->fields ?? [], $profile?->fields ?? []),
            self::codes('nationality', 'Nationalité', $opportunity->eligible_nationalities ?? [], array_filter([$profile?->nationality]), 'la fiche ne précise pas de condition de nationalité', 'vous n’avez pas déclaré votre nationalité',
                fn (string $code) => Labels::country($code)),
            self::codes('residence', 'Pays de résidence', $opportunity->eligible_residences ?? [], array_filter([$profile?->residence_country]), 'la fiche ne précise pas de condition de résidence', 'vous n’avez pas déclaré votre pays de résidence',
                fn (string $code) => Labels::country($code)),
        ];
        $results = array_column($criteria, 'result');
        $overall = in_array('mismatch', $results, true) ? 'mismatch' : (in_array('check', $results, true) ? 'check' : 'match');

        return ['overall' => $overall, 'overallLabel' => ['match' => 'Correspond à vos critères déclarés', 'check' => 'Information à vérifier', 'mismatch' => 'Au moins un critère déclaré n’est pas satisfait'][$overall],
            'criteria' => $criteria];
    }

    /**
     * @return array{key: string, label: string, result: string, resultLabel: string, explanation: string}
     */
    private static function level(Opportunity $opportunity, ?string $declared): array
    {
        $published = $opportunity->levels ?? [];
        $names = implode(', ', array_map(fn (string $level) => Opportunity::LEVELS[$level] ?? $level, $published));

        return match (true) {
            $published === [] => self::row('level', 'Niveau', 'check', 'La fiche ne précise pas le niveau attendu.'),
            in_array('any', $published, true) => self::row('level', 'Niveau', 'match', 'La fiche est ouverte à tous les niveaux.'),
            $declared === null => self::row('level', 'Niveau', 'check', 'La fiche vise : '.$names.'. Vous n’avez pas déclaré votre niveau.'),
            in_array($declared, $published, true) => self::row('level', 'Niveau', 'match', 'La fiche vise : '.$names.'. Vous avez déclaré : '.Opportunity::LEVELS[$declared].'.'),
            default => self::row('level', 'Niveau', 'mismatch', 'La fiche vise : '.$names.'. Vous avez déclaré : '.(Opportunity::LEVELS[$declared] ?? $declared).'.'),
        };
    }

    /**
     * Domaines : intitulés libres. Un intitulé commun donne « correspond » ; l'absence d'intitulé commun ne prouve rien, car deux
     * intitulés différents peuvent désigner le même domaine : le résultat reste « à vérifier », jamais « non satisfait ».
     *
     * @param  list<string>  $published
     * @param  list<string>  $declared
     * @return array{key: string, label: string, result: string, resultLabel: string, explanation: string}
     */
    private static function fields(array $published, array $declared): array
    {
        $normalize = fn (string $value): string => trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($value))));
        $common = array_values(array_filter($published, fn (string $field) => in_array($normalize($field), array_map($normalize, $declared), true)));

        return match (true) {
            $published === [] => self::row('field', 'Domaine', 'check', 'La fiche ne précise pas de domaine.'),
            $declared === [] => self::row('field', 'Domaine', 'check', 'La fiche vise : '.implode(', ', $published).'. Vous n’avez pas déclaré de domaine.'),
            $common !== [] => self::row('field', 'Domaine', 'match', 'Domaine commun : '.implode(', ', $common).'.'),
            default => self::row('field', 'Domaine', 'check', 'La fiche vise : '.implode(', ', $published).'. Aucun de vos domaines n’y figure tel quel, mais les intitulés peuvent différer : vérifiez sur la source.'),
        };
    }

    /**
     * @param  list<string>  $published
     * @param  array<int, string>  $declared
     * @return array{key: string, label: string, result: string, resultLabel: string, explanation: string}
     */
    private static function codes(string $key, string $label, array $published, array $declared, string $unpublished, string $undeclared, callable $name): array
    {
        $published = array_map('strtoupper', $published);
        $declared = array_map('strtoupper', array_values($declared));
        $names = fn (array $codes): string => implode(', ', array_map(fn (string $code) => $name($key === 'language' ? strtolower($code) : $code), $codes));

        return match (true) {
            $published === [] => self::row($key, $label, 'check', ucfirst($unpublished).'.'),
            $declared === [] => self::row($key, $label, 'check', 'La fiche indique : '.$names($published).'. '.ucfirst($undeclared).'.'),
            array_intersect($published, $declared) !== [] => self::row($key, $label, 'match', 'La fiche indique : '.$names($published).'. Vous avez déclaré : '.$names($declared).'.'),
            default => self::row($key, $label, 'mismatch', 'La fiche indique : '.$names($published).'. Vous avez déclaré : '.$names($declared).'.'),
        };
    }

    /**
     * @return array{key: string, label: string, result: string, resultLabel: string, explanation: string}
     */
    private static function row(string $key, string $label, string $result, string $explanation): array
    {
        return ['key' => $key, 'label' => $label, 'result' => $result, 'resultLabel' => self::LABELS[$result], 'explanation' => $explanation];
    }
}
