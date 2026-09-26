<?php

declare(strict_types=1);

namespace App\Services\WebChat;

final class SimpleTextPdf
{
    public static function render(string $title, string $body): string
    {
        $plain = self::plain($title)."\n\n".self::plain($body);
        $lines = self::wrap($plain);
        $pages = array_chunk($lines === [] ? [''] : $lines, 48);
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $fontId = 3 + (count($pages) * 2);
        $kids = [];
        $nextId = 3;

        foreach ($pages as $pageLines) {
            $pageId = $nextId;
            $contentId = $nextId + 1;
            $nextId += 2;
            $kids[] = $pageId.' 0 R';
            $stream = self::pageStream($pageLines);
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents '.$contentId.' 0 R /Resources << /Font << /F1 '.$fontId.' 0 R >> >> >>';
            $objects[$contentId] = '<< /Length '.strlen($stream).' >> stream'."\n".$stream."\n".'endstream';
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' >>';
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $bodyObj) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$bodyObj."\nendobj\n";
        }

        $xref = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 ".($maxId + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer << /Size ".($maxId + 1).' /Root 1 0 R >>'."\n";
        $pdf .= "startxref\n".$xref."\n%%EOF\n";

        return $pdf;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function pageStream(array $lines): string
    {
        $out = "BT\n/F1 11 Tf\n72 720 Td\n";
        $first = true;
        foreach ($lines as $line) {
            if (! $first) {
                $out .= "0 -16 Td\n";
            }
            $first = false;
            $out .= '('.self::escape($line).") Tj\n";
        }

        return $out."ET";
    }

    /**
     * @return list<string>
     */
    private static function wrap(string $text): array
    {
        $out = [];
        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [''] as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                $out[] = '';
                continue;
            }
            $width = 0;
            $current = '';
            foreach (preg_split('/\s+/', $paragraph) ?: [] as $word) {
                $add = ($current === '' ? 0 : 1) + strlen($word);
                if ($width + $add > 86 && $current !== '') {
                    $out[] = $current;
                    $current = $word;
                    $width = strlen($word);
                    continue;
                }
                $current = $current === '' ? $word : $current.' '.$word;
                $width += $add;
            }
            if ($current !== '') {
                $out[] = $current;
            }
        }

        return $out;
    }

    private static function plain(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[#*_`>]+/u', '', $text) ?? $text;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text) ?? $text;
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
