<?php

namespace App\Support\Xlsx;

use RuntimeException;
use ZipArchive;

/**
 * Écrit un classeur XLSX minimal : feuilles de texte, sans macro, sans formule calculée, sans lien externe.
 * Une cellule est une chaîne, ou ['number' => float], ou ['formula' => string] (utilisé seulement par les tests).
 */
class XlsxWriter
{
    /**
     * @param  array<string, list<list<string|array{number?: float|int, formula?: string}|null>>>  $sheets  nom de feuille => lignes
     */
    public static function build(array $sheets): string
    {
        // Chemin neuf plutôt que tempnam() : l'archive ne remplace alors aucun fichier existant à sa fermeture.
        $path = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'campus-xlsx-'.bin2hex(random_bytes(12)).'.xlsx';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Création du classeur impossible.');
        }
        $names = array_keys($sheets);
        $overrides = $workbook = $relations = '';
        foreach ($names as $index => $name) {
            $number = $index + 1;
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$number.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbook .= '<sheet name="'.self::escape(mb_substr($name, 0, 31)).'" sheetId="'.$number.'" r:id="rId'.$number.'"/>';
            $relations .= '<Relationship Id="rId'.$number.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$number.'.xml"/>';
            $zip->addFromString('xl/worksheets/sheet'.$number.'.xml', self::sheet($sheets[$name]));
        }
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $zip->addFromString('[Content_Types].xml', $head.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$overrides.'</Types>');
        $zip->addFromString('_rels/.rels', $head.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $head.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$workbook.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $head.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relations.'</Relationships>');
        $zip->close();
        $binary = (string) file_get_contents($path);
        @unlink($path);

        return $binary;
    }

    public static function column(int $index): string
    {
        $name = '';
        for ($value = $index + 1; $value > 0; $value = intdiv($value - 1, 26)) {
            $name = chr(65 + ($value - 1) % 26).$name;
        }

        return $name;
    }

    /** @param list<list<string|array<string, mixed>|null>> $rows */
    private static function sheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="11" width="22" customWidth="1"/></cols><sheetData>';
        foreach (array_values($rows) as $rowIndex => $cells) {
            $line = $rowIndex + 1;
            $xml .= '<row r="'.$line.'">';
            foreach (array_values($cells) as $columnIndex => $cell) {
                if ($cell === null || $cell === '') {
                    continue;
                }
                $reference = self::column($columnIndex).$line;
                $xml .= match (true) {
                    is_array($cell) && isset($cell['formula']) => '<c r="'.$reference.'"><f>'.self::escape($cell['formula']).'</f><v>0</v></c>',
                    is_array($cell) && isset($cell['number']) => '<c r="'.$reference.'"><v>'.(0 + $cell['number']).'</v></c>',
                    default => '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.self::escape((string) $cell).'</t></is></c>',
                };
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars(preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
