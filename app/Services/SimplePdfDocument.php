<?php

namespace App\Services;

/**
 * Story 13.4: minimal PDF writer for tabular report exports.
 */
final class SimplePdfDocument
{
    /**
     * @param  list<string>  $lines
     */
    public static function fromLines(array $lines, string $title = 'Report'): string
    {
        $streamLines = [
            'BT',
            '/F1 12 Tf',
            '50 750 Td',
            '(' . self::escape($title) . ') Tj',
            '/F1 9 Tf',
            '0 -18 Td',
        ];

        foreach ($lines as $line) {
            $streamLines[] = '(' . self::escape($line) . ') Tj';
            $streamLines[] = '0 -14 Td';
        }

        $streamLines[] = 'ET';
        $stream = implode("\n", $streamLines);
        $streamLength = strlen($stream);

        $objects = [];
        $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n";
        $objects[] = "4 0 obj\n<< /Length {$streamLength} >>\nstream\n{$stream}\nendstream\nendobj\n";
        $objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . count($offsets) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < count($offsets); $i++) {
            $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }

        $pdf .= "trailer\n<< /Size " . count($offsets) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
