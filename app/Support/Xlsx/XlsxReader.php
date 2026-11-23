<?php

namespace App\Support\Xlsx;

use App\Exceptions\ImportRejected;
use XMLReader;
use ZipArchive;

/**
 * Lecture bornée de la première feuille d'un classeur XLSX.
 * Le contenu n'est jamais exécuté : une formule est signalée, pas calculée. Aucune entité ni ressource externe n'est chargée.
 */
class XlsxReader
{
    private const MAX_COLUMNS = 11;

    private const MAX_CELL_LENGTH = 255;

    private const MAX_SHARED_STRINGS = 200000;

    /**
     * Contrôle rapide du type réel, sans lire les données : signature ZIP, structure de classeur, absence de macros.
     */
    public function inspect(string $path): void
    {
        $zip = $this->open($path);
        $zip->close();
    }

    /**
     * @return array{sheet: string, header: array<string, string>, rows: list<array{line: int, cells: array<string, array{value: string, formula: bool, numeric: bool}>}>}
     */
    public function read(string $path, int $maxRows): array
    {
        $zip = $this->open($path);
        $sheets = $this->sheets($path);
        if ($sheets === []) {
            throw new ImportRejected('Le classeur ne contient aucune feuille.');
        }
        if (count($sheets) > (int) config('campus.imports.max_sheets')) {
            throw new ImportRejected('Le classeur contient trop de feuilles ('.count($sheets).'). Utilisez le modèle fourni.');
        }
        $target = $this->target($path, $sheets[0]['id']);
        if ($zip->locateName($target) === false) {
            throw new ImportRejected('La première feuille du classeur est introuvable.');
        }
        $strings = $zip->locateName('xl/sharedStrings.xml') !== false ? $this->sharedStrings($path) : [];
        $zip->close();

        $header = null;
        $rows = [];
        $reader = $this->xml($path, $target);
        while ($reader->read()) {
            $this->refuseDoctype($reader);
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }
            $line = (int) $reader->getAttribute('r');
            $cells = $this->cells($reader, $strings);
            if ($cells === []) {
                continue;
            }
            if ($header === null) {
                $header = array_map(fn (array $cell): string => $cell['value'], $cells);

                continue;
            }
            if (count($rows) >= $maxRows) {
                $reader->close();
                throw new ImportRejected('Le fichier dépasse '.$maxRows.' lignes de données. Scindez-le en plusieurs imports.');
            }
            $rows[] = ['line' => $line, 'cells' => $cells];
        }
        $reader->close();
        if ($header === null) {
            throw new ImportRejected('La première feuille est vide : la ligne d’en-têtes du modèle est attendue.');
        }

        return ['sheet' => $sheets[0]['name'], 'header' => $header, 'rows' => $rows];
    }

    private function open(string $path): ZipArchive
    {
        $handle = @fopen($path, 'rb');
        $signature = $handle ? fread($handle, 4) : '';
        if ($handle) {
            fclose($handle);
        }
        $zip = new ZipArchive;
        if ($signature !== "PK\x03\x04" || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportRejected('Ce fichier n’est pas un classeur Excel .xlsx valide.');
        }
        if ($zip->numFiles > (int) config('campus.imports.max_archive_entries')) {
            throw new ImportRejected('Le classeur contient trop d’éléments internes.');
        }
        $total = 0;
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = (string) $stat['name'];
            $total += (int) $stat['size'];
            $names[] = $name;
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                throw new ImportRejected('Le classeur contient un chemin interne non autorisé.');
            }
            if (preg_match('/vbaProject|\.bin$|activeX|embeddings|externalLinks/i', $name) === 1) {
                throw new ImportRejected('Le classeur contient des macros, des objets intégrés ou des liens externes : ce type de fichier est refusé.');
            }
            if (($stat['encryption_method'] ?? 0) !== 0) {
                throw new ImportRejected('Les classeurs chiffrés ne sont pas acceptés.');
            }
        }
        if ($total > (int) config('campus.imports.max_uncompressed_bytes')) {
            throw new ImportRejected('Le classeur est trop volumineux une fois décompressé.');
        }
        $types = in_array('[Content_Types].xml', $names, true) ? (string) $zip->getFromName('[Content_Types].xml', 200000) : '';
        if (! in_array('xl/workbook.xml', $names, true) || ! str_contains($types, 'spreadsheetml.sheet.main+xml') || stripos($types, 'macroEnabled') !== false) {
            throw new ImportRejected('Ce fichier n’est pas un classeur Excel .xlsx sans macros. Enregistrez-le au format .xlsx.');
        }

        return $zip;
    }

    /** @return list<array{name: string, id: string}> */
    private function sheets(string $path): array
    {
        $sheets = [];
        $reader = $this->xml($path, 'xl/workbook.xml');
        while ($reader->read()) {
            $this->refuseDoctype($reader);
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'sheet') {
                $sheets[] = ['name' => (string) $reader->getAttribute('name'), 'id' => (string) $reader->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships')];
            }
        }
        $reader->close();

        return $sheets;
    }

    private function target(string $path, string $relationId): string
    {
        $target = null;
        $reader = $this->xml($path, 'xl/_rels/workbook.xml.rels');
        while ($reader->read()) {
            $this->refuseDoctype($reader);
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'Relationship' && $reader->getAttribute('Id') === $relationId) {
                $target = (string) $reader->getAttribute('Target');
            }
        }
        $reader->close();
        if ($target === null || preg_match('#^(/xl/)?worksheets/[A-Za-z0-9_.-]+\.xml$#', $target) !== 1) {
            throw new ImportRejected('La structure du classeur est inattendue.');
        }

        return 'xl/'.preg_replace('#^/xl/#', '', $target);
    }

    /** @return list<string> */
    private function sharedStrings(string $path): array
    {
        $strings = [];
        $reader = $this->xml($path, 'xl/sharedStrings.xml');
        $current = null;
        while ($reader->read()) {
            $this->refuseDoctype($reader);
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $current = '';
                if ($reader->isEmptyElement) {
                    $strings[] = '';
                    $current = null;
                }
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't' && $current !== null && ! $reader->isEmptyElement) {
                $current .= $reader->readString();
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si' && $current !== null) {
                $strings[] = mb_substr($current, 0, self::MAX_CELL_LENGTH + 1);
                $current = null;
                if (count($strings) > self::MAX_SHARED_STRINGS) {
                    $reader->close();
                    throw new ImportRejected('Le classeur contient trop de textes distincts.');
                }
            }
        }
        $reader->close();

        return $strings;
    }

    /**
     * @param  list<string>  $strings
     * @return array<string, array{value: string, formula: bool, numeric: bool}>
     */
    private function cells(XMLReader $reader, array $strings): array
    {
        $cells = [];
        if ($reader->isEmptyElement) {
            return $cells;
        }
        $depth = $reader->depth;
        $column = $type = null;
        $value = '';
        $formula = false;
        while ($reader->read() && ! ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth)) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c') {
                $column = preg_replace('/\d+$/', '', (string) $reader->getAttribute('r'));
                $type = $reader->getAttribute('t');
                $value = '';
                $formula = false;
                if (! $reader->isEmptyElement) {
                    continue;
                }
                $column = null;
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'f') {
                $formula = true;
            } elseif ($reader->nodeType === XMLReader::ELEMENT && in_array($reader->localName, ['v', 't'], true) && ! $reader->isEmptyElement) {
                $value .= $reader->readString();
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'c' && $column !== null) {
                $index = $this->columnIndex($column);
                $text = $type === 's' ? ($strings[(int) $value] ?? '') : $value;
                $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
                if ($index < self::MAX_COLUMNS && ($text !== '' || $formula)) {
                    $cells[$column] = [
                        'value' => mb_substr($text, 0, self::MAX_CELL_LENGTH),
                        'formula' => $formula,
                        'numeric' => ! $formula && ($type === null || $type === 'n') && is_numeric($text),
                    ];
                }
                $column = null;
            }
        }

        return $cells;
    }

    private function columnIndex(string $column): int
    {
        $index = 0;
        foreach (str_split(strtoupper($column)) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private function xml(string $path, string $entry): XMLReader
    {
        $reader = new XMLReader;
        if (@$reader->open('zip://'.$path.'#'.$entry, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT) !== true) {
            throw new ImportRejected('Le classeur est illisible ou endommagé.');
        }

        return $reader;
    }

    private function refuseDoctype(XMLReader $reader): void
    {
        if ($reader->nodeType === XMLReader::DOC_TYPE) {
            $reader->close();
            throw new ImportRejected('Le classeur contient une déclaration de document interdite.');
        }
    }
}
