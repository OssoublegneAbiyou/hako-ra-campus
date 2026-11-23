<?php

namespace App\Support;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * Calcul des moyennes indicatives, en fractions exactes : aucun arrondi n'intervient avant l'affichage.
 * Seules les notes chiffrées (« graded ») sont retenues ; absence, dispense, non rendu, à venir et note manquante
 * ne valent jamais zéro et ne comptent ni au numérateur ni au dénominateur.
 */
class GradeCalculator
{
    public const RETAINED = 'graded';

    /**
     * Note ramenée sur 20 : 20 × valeur / barème.
     */
    public static function normalized(string $value, string $scale): BigRational
    {
        return BigRational::of($value)->multipliedBy(20)->dividedBy(BigRational::of($scale));
    }

    /**
     * Moyenne d'une matière : somme(note sur 20 × coefficient) / somme(coefficients des notes retenues).
     *
     * @param  iterable<array{status: string, value: string|null, scale: string, coefficient: string}>  $grades
     * @return array{average: BigRational|null, retained: int, total: int}
     */
    public static function subject(iterable $grades): array
    {
        $weighted = BigRational::zero();
        $weights = BigRational::zero();
        $retained = 0;
        $total = 0;
        foreach ($grades as $grade) {
            $total++;
            if ($grade['status'] !== self::RETAINED || $grade['value'] === null) {
                continue;
            }
            $coefficient = BigRational::of($grade['coefficient']);
            $weighted = $weighted->plus(self::normalized($grade['value'], $grade['scale'])->multipliedBy($coefficient));
            $weights = $weights->plus($coefficient);
            $retained++;
        }

        return ['average' => $retained > 0 ? $weighted->dividedBy($weights) : null, 'retained' => $retained, 'total' => $total];
    }

    /**
     * Moyenne générale : somme(moyenne de matière non arrondie × coefficient de matière) / somme(coefficients des matières
     * disposant d'une moyenne). Une matière sans note retenue est ignorée. Si une matière disposant d'une moyenne n'a pas
     * de coefficient défini, aucune moyenne générale n'est calculée.
     *
     * @param  iterable<array{average: BigRational|null, coefficient: string|null}>  $subjects
     * @return array{average: BigRational|null, counted: int, total: int, missingCoefficients: int}
     */
    public static function general(iterable $subjects): array
    {
        $weighted = BigRational::zero();
        $weights = BigRational::zero();
        $counted = 0;
        $total = 0;
        $missing = 0;
        foreach ($subjects as $subject) {
            $total++;
            if ($subject['average'] === null) {
                continue;
            }
            if ($subject['coefficient'] === null) {
                $missing++;

                continue;
            }
            $coefficient = BigRational::of($subject['coefficient']);
            $weighted = $weighted->plus($subject['average']->multipliedBy($coefficient));
            $weights = $weights->plus($coefficient);
            $counted++;
        }

        return ['average' => $counted > 0 && $missing === 0 ? $weighted->dividedBy($weights) : null, 'counted' => $counted, 'total' => $total, 'missingCoefficients' => $missing];
    }

    /**
     * Arrondi d'affichage seulement : deux décimales, virgule décimale.
     */
    public static function display(?BigRational $number, int $decimals = 2): ?string
    {
        return $number === null ? null : str_replace('.', ',', (string) $number->toScale($decimals, RoundingMode::HalfUp));
    }

    /**
     * Matière à surveiller : moyenne indicative strictement inférieure au seuil sur 20. Simple repère, sans autre interprétation.
     */
    public static function belowThreshold(?BigRational $average, string $threshold): bool
    {
        return $average !== null && $average->isLessThan(BigRational::of($threshold));
    }

    /**
     * Décimal saisi (virgule ou point) ramené à une écriture canonique à trois décimales.
     */
    public static function decimal(string $input): string
    {
        [$integer, $fraction] = array_pad(explode('.', str_replace(',', '.', trim($input)), 2), 2, '');
        $integer = ltrim($integer, '0');

        return ($integer === '' ? '0' : $integer).'.'.str_pad(substr($fraction, 0, 3), 3, '0');
    }
}
