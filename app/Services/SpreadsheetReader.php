<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class SpreadsheetReader
{
    public function read(string $path, string $format, int $sheet = 0, int $header = 1, string $timezone = 'Asia/Tashkent'): array
    {
        if (filesize($path) > config('imports.max_bytes')) {
            $this->invalid('Fayl 5 MB limitidan oshgan.');
        }
        if ($format === 'csv') {
            $sheets = ['CSV'];
            $table = $this->csv($path);
        } else {
            [$sheets, $table] = $this->xlsx($path, $sheet, $timezone);
        }
        if ($header === 1 && ! isset($table[1])) {
            $header = (int) array_key_first($table);
        }
        $headers = $table[$header] ?? [];
        if (isset($headers[0])) {
            $headers[0] = ltrim((string) $headers[0], "\xEF\xBB\xBF");
        }
        if ($header > 20 || $headers === [] || count($headers) > config('imports.max_columns') || count(array_unique($headers)) !== count($headers) || in_array('', $headers, true)) {
            $this->invalid('1–40 ta bo‘sh bo‘lmagan, takrorlanmagan headerni tanlang (birinchi 20 satr).');
        }
        $headers[0] = ltrim((string) $headers[0], "\xEF\xBB\xBF");
        $rows = [];
        foreach ($table as $number => $row) {
            if ($number <= $header) {
                continue;
            }
            if (count($row) > count($headers)) {
                $this->invalid('Satr '.$number.': headerdan ortiq ustunlar mavjud.');
            }
            $rows[$number] = array_pad($row, count($headers), '');
        }
        if ($rows === [] || count($rows) > config('imports.max_rows')) {
            $this->invalid('1–'.config('imports.max_rows').' ta ma’lumot satri bo‘lsin.');
        }

        return compact('sheets', 'headers', 'rows', 'header');
    }

    private function csv(string $path): array
    {
        $handle = fopen($path, 'rb');
        $table = [];
        try {
            $number = 0;
            while (($row = fgetcsv($handle, 65536, ',', '"', '')) !== false) {
                $number++;
                if ($row === [null]) {
                    continue;
                }
                $this->checkRow($row);
                if (count($table) > config('imports.max_rows') + 20) {
                    $this->invalid('Satrlar limiti oshgan.');
                }
                $table[$number] = array_map(fn ($value): string => (string) ($value ?? ''), $row);
            }
        } finally {
            fclose($handle);
        }

        return $table;
    }

    private function xlsx(string $path, int $sheet, string $timezone): array
    {
        if (! class_exists(ZipArchive::class)) {
            $this->invalid('XLSX uchun PHP ZIP/XML kengaytmalari kerak.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->invalid('XLSX arxivini ochib bo‘lmadi.');
        }
        try {
            if ($zip->numFiles > 200) {
                $this->invalid('Arxivda 200 dan ortiq element mavjud.');
            }
            $total = 0;
            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = $stat['name'];
                $total += $stat['size'];
                if (isset($seen[$name]) || str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/(?:vbaProject|embeddings|externalLinks|\.bin$)/i', $name)
                    || $stat['size'] > 8388608 || $total > 20971520 || (($stat['encryption_method'] ?? 0) !== 0)
                    || ($stat['size'] > 1048576 && $stat['size'] > max(1, $stat['comp_size']) * 200)) {
                    $this->invalid('Arxiv path, macro, encryption yoki decompression limiti xavfsiz emas.');
                }
                $seen[$name] = true;
                if (str_ends_with($name, '.rels')) {
                    $relations = $this->xml($this->entry($zip, $name));
                    foreach ($relations->query('//*[local-name()="Relationship"]') as $relation) {
                        if (strtolower($relation->getAttribute('TargetMode')) === 'external' || preg_match('~^(?:https?|ftp|file|data):~i', $relation->getAttribute('Target'))) {
                            $this->invalid('Tashqi workbook havolalari qabul qilinmaydi.');
                        }
                    }
                }
            }
            $types = $this->entry($zip, '[Content_Types].xml');
            if (! str_contains($types, 'spreadsheetml.sheet.main+xml') || stripos($types, 'macroEnabled') !== false) {
                $this->invalid('Oddiy XLSX workbook kerak; macro workbook emas.');
            }
            $workbook = $this->xml($this->entry($zip, 'xl/workbook.xml'));
            $sheetNodes = $workbook->query('//*[local-name()="sheets"]/*[local-name()="sheet"]');
            if ($sheetNodes->length === 0 || $sheetNodes->length > 10 || $sheet >= $sheetNodes->length) {
                $this->invalid('Workbook 1–10 sheet bilan bo‘lsin; tanlangan sheet mavjud emas.');
            }
            $sheets = [];
            foreach ($sheetNodes as $node) {
                $sheets[] = $node->getAttribute('name');
            }
            $relationships = $this->xml($this->entry($zip, 'xl/_rels/workbook.xml.rels'));
            $targets = [];
            foreach ($relationships->query('//*[local-name()="Relationship"]') as $relation) {
                if (strtolower($relation->getAttribute('TargetMode')) === 'external') {
                    $this->invalid('External relationship rad qilindi.');
                }
                $targets[$relation->getAttribute('Id')] = $relation->getAttribute('Target');
            }
            $id = $sheetNodes->item($sheet)->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $target = $targets[$id] ?? '';
            if (str_starts_with($target, '/xl/')) {
                $target = substr($target, 1);
            } elseif (str_starts_with($target, 'worksheets/')) {
                $target = 'xl/'.$target;
            }
            if (! preg_match('~^xl/worksheets/[a-zA-Z0-9_-]+\.xml$~', $target)) {
                $this->invalid('Sheet relationship path noto‘g‘ri.');
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $shared = $this->xml($this->entry($zip, 'xl/sharedStrings.xml'));
                foreach ($shared->query('//*[local-name()="si"]') as $si) {
                    $value = '';
                    foreach ($shared->query('.//*[local-name()="t"]', $si) as $text) {
                        $value .= $text->textContent;
                    }
                    if (count($strings) >= 20000 || mb_strlen($value) > 5000) {
                        $this->invalid('Shared strings limiti oshgan.');
                    }
                    $strings[] = $value;
                }
            }
            $dateStyles = [];
            if ($zip->locateName('xl/styles.xml') !== false) {
                $styles = $this->xml($this->entry($zip, 'xl/styles.xml'));
                $index = 0;
                foreach ($styles->query('//*[local-name()="cellXfs"]/*[local-name()="xf"]') as $style) {
                    $dateStyles[$index++] = in_array((int) $style->getAttribute('numFmtId'), range(14, 22), true);
                }
            }
            $date1904 = $workbook->query('//*[local-name()="workbookPr"]')->item(0)?->getAttribute('date1904');
            $sheetXml = $this->xml($this->entry($zip, $target));
            if ($sheetXml->query('//*[local-name()="f"]')->length > 0) {
                $this->invalid('Formula celllar qabul qilinmaydi. Ularni explicit values sifatida saqlang.');
            }
            $table = [];
            foreach ($sheetXml->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                $number = (int) $row->getAttribute('r');
                if ($number < 1 || isset($table[$number]) || count($table) >= config('imports.max_rows') + 20) {
                    $this->invalid('Satr identifikatori yoki limiti noto‘g‘ri.');
                }
                $cells = [];
                foreach ($sheetXml->query('./*[local-name()="c"]', $row) as $cell) {
                    if (! preg_match('/^([A-Z]+)(\d+)$/', $cell->getAttribute('r'), $address) || (int) $address[2] !== $number) {
                        $this->invalid('Cell manzili noto‘g‘ri.');
                    }
                    $column = 0;
                    foreach (str_split($address[1]) as $letter) {
                        $column = $column * 26 + ord($letter) - 64;
                    }
                    if ($column > config('imports.max_columns') || array_key_exists($column - 1, $cells)) {
                        $this->invalid('Cell ustuni takrorlangan yoki limitdan katta.');
                    }
                    $value = $sheetXml->query('./*[local-name()="v"]', $cell)->item(0)?->textContent ?? '';
                    $type = $cell->getAttribute('t');
                    if ($type === 's') {
                        if (! ctype_digit($value) || ! array_key_exists((int) $value, $strings)) {
                            $this->invalid('Shared string index noto‘g‘ri.');
                        }
                        $value = $strings[(int) $value];
                    } elseif ($type === 'inlineStr') {
                        $value = '';
                        foreach ($sheetXml->query('.//*[local-name()="t"]', $cell) as $text) {
                            $value .= $text->textContent;
                        }
                    } elseif ($type === '' || $type === 'n') {
                        $value = $this->decimal($value);
                        if ($value !== '' && ($dateStyles[(int) $cell->getAttribute('s')] ?? false)) {
                            $value = $this->excelDate($value, in_array($date1904, ['1', 'true'], true), $timezone);
                        }
                    } elseif (! in_array($type, ['str', 'b', 'd'], true)) {
                        $this->invalid('Cell data turi qo‘llab-quvvatlanmagan.');
                    }
                    $cells[$column - 1] = $value;
                }
                if ($cells === []) {
                    continue;
                }
                $values = array_fill(0, max(array_keys($cells)) + 1, '');
                foreach ($cells as $column => $value) {
                    $values[$column] = $value;
                }
                $this->checkRow($values);
                $table[$number] = $values;
            }
            ksort($table);

            return [$sheets, $table];
        } finally {
            $zip->close();
        }
    }

    private function decimal(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (! preg_match('/^(-?)(\d+)(?:\.(\d*))?(?:[Ee]([+-]?\d{1,2}))?$/', $value, $parts)) {
            $this->invalid('Numeric cell decimal shaklida emas.');
        }
        $fraction = $parts[3] ?? '';
        $exponent = (int) ($parts[4] ?? 0);
        if (abs($exponent) > 20 || strlen($value) > 80) {
            $this->invalid('Numeric cell precision limiti oshgan.');
        }
        $number = $parts[1].$parts[2].($fraction === '' ? '' : '.'.$fraction);
        $scale = max(0, strlen($fraction) - $exponent);
        $normalized = $exponent >= 0 ? bcmul($number, '1'.str_repeat('0', $exponent), $scale) : bcdiv($number, '1'.str_repeat('0', -$exponent), $scale);

        return str_contains($normalized, '.') ? rtrim(rtrim($normalized, '0'), '.') : $normalized;
    }

    private function excelDate(string $serial, bool $date1904, string $timezone): string
    {
        if (bccomp($serial, '0', 12) < 0 || bccomp($serial, '100000', 12) > 0 || (! $date1904 && bccomp($serial, '60', 0) === 0)) {
            $this->invalid('Excel date serial unsupported (1900-02-29 ham rad qilinadi).');
        }
        $days = bcdiv($serial, '1', 0);
        $seconds = (int) bcmul(bcsub($serial, $days, 12), '86400', 0);
        $epoch = $date1904 ? '1904-01-01' : (bccomp($serial, '60', 0) < 0 ? '1899-12-31' : '1899-12-30');

        return CarbonImmutable::parse($epoch, $timezone)->addDays((int) $days)->addSeconds($seconds)->utc()->toIso8601String();
    }

    private function xml(string $contents): DOMXPath
    {
        if (stripos($contents, '<!DOCTYPE') !== false || stripos($contents, '<!ENTITY') !== false) {
            $this->invalid('DTD/entity XML qabul qilinmaydi.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
                $this->invalid('Workbook XML noto‘g‘ri.');
            }

            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function entry(ZipArchive $zip, string $name): string
    {
        $data = $zip->getFromName($name);
        if ($data === false || strlen($data) > 8388608) {
            $this->invalid('Workbook XML qismi yo‘q yoki limitdan katta.');
        }

        return $data;
    }

    private function checkRow(array $row): void
    {
        if (count($row) > config('imports.max_columns') || ! mb_check_encoding($row, 'UTF-8')) {
            $this->invalid('Ustun limiti yoki UTF-8 noto‘g‘ri.');
        }
        foreach ($row as $value) {
            if (str_contains((string) $value, "\0") || mb_strlen((string) $value) > 5000) {
                $this->invalid('Cell 5000 belgidan oshmasin va null byte bo‘lmasin.');
            }
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
