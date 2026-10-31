<?php

namespace App\Reports;

use App\Models\BusinessSetting;
use App\Services\Audit;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

/** CSV / Excel / PDF export of any ReportTable, with the organisation header. */
final class Exporter
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    private const NAVY = '0B1F33';

    private const ORANGE = 'FF6B00';

    public static function respond(ReportTable $table, string $format): Response
    {
        $user = auth()->user();
        abort_unless($user && $user->hasCapability('export_reports'), 403, 'You do not have permission to export reports.');
        $business = BusinessSetting::current();
        $filters = [];
        foreach ($table->filters as [$label, $value]) {
            $filters[$label] = $value;
        }
        Audit::log('EXPORT', objectRepr: $table->title.' ('.strtoupper($format).')', modelName: 'Report',
            changes: ['rows' => count($table->rows), 'filters' => $filters]);

        return match ($format) {
            'csv' => self::csv($table, $business),
            'xlsx' => self::xlsx($table, $business),
            default => self::pdf($table, $business),
        };
    }

    public static function format(mixed $value, string $kind): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($kind) {
            'money' => Money::inr($value),
            'number' => is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.') : (string) $value,
            'percent' => $value.'%',
            'date' => $value instanceof CarbonInterface ? $value->format('d-m-Y') : (string) $value,
            'datetime' => $value instanceof CarbonInterface ? $value->format('d-m-Y H:i') : (string) $value,
            default => (string) $value,
        };
    }

    private static function filename(ReportTable $table, string $ext): string
    {
        return Str::slug($table->title).'-'.now()->format('Ymd').'.'.$ext;
    }

    private static function csv(ReportTable $table, BusinessSetting $business): Response
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 correctly
        fputcsv($out, [$business->business_name]);
        if ($business->tagline) {
            fputcsv($out, [$business->tagline]);
        }
        fputcsv($out, [$table->title]);
        foreach ($table->filters as [$label, $value]) {
            fputcsv($out, ["{$label}: {$value}"]);
        }
        foreach ($table->summary as [$label, $value]) {
            fputcsv($out, [$label, $value]);
        }
        fputcsv($out, []);
        fputcsv($out, array_map(fn ($c) => $c[0], $table->columns));
        foreach ($table->rows as $row) {
            fputcsv($out, array_map(function ($c) use ($row) {
                $v = $row[$c[1]] ?? null;

                return $c[2] === 'money' && $v !== null && $v !== '' ? Money::of($v) : self::format($v, $c[2]);
            }, $table->columns));
        }
        if ($table->totals) {
            $line = [];
            foreach ($table->columns as $i => [$label, $key, $kind]) {
                $v = $table->totals[$key] ?? null;
                $line[] = $i === 0 ? $table->totalsLabel : ($kind === 'money' && $v !== null ? Money::of($v) : self::format($v, $kind));
            }
            fputcsv($out, $line);
        }
        fputcsv($out, []);
        fputcsv($out, ['Generated '.now()->format('d-m-Y H:i').' · '.$table->moneyNote()]);
        rewind($out);
        $content = stream_get_contents($out);
        fclose($out);

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.self::filename($table, 'csv').'"',
        ]);
    }

    private static function xlsx(ReportTable $table, BusinessSetting $business): Response
    {
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet();
        $ws->setTitle(Str::limit(preg_replace('/[\\\\\/\?\*\[\]:]/', '', $table->title), 31, ''));
        $ncols = max(count($table->columns), 1);
        $last = Coordinate::stringFromColumnIndex($ncols);
        $fill = fn (string $rgb) => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rgb]];

        $banner = function (int $row, string $text, array $font, ?string $bg = null, ?float $height = null) use ($ws, $last, $fill) {
            $ws->mergeCells("A{$row}:{$last}{$row}");
            $ws->setCellValue("A{$row}", $text);
            $style = ['font' => $font, 'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER]];
            if ($bg) {
                $style['fill'] = $fill($bg);
            }
            $ws->getStyle("A{$row}:{$last}{$row}")->applyFromArray($style);
            if ($height) {
                $ws->getRowDimension($row)->setRowHeight($height);
            }
        };

        $r = 1;
        $banner($r++, mb_strtoupper($business->business_name), ['size' => 16, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']], self::NAVY, 28);
        $sub = implode(' · ', array_filter([$business->tagline, $business->contactLine()]));
        $banner($r++, $sub, ['size' => 9, 'color' => ['rgb' => 'FFFFFF']], self::ORANGE, 16);
        if ($business->address) {
            $banner($r++, preg_replace('/\s+/', ' ', $business->address), ['size' => 9, 'color' => ['rgb' => '475569']]);
        }
        $r++;
        $banner($r++, $table->title, ['size' => 13, 'bold' => true, 'color' => ['rgb' => self::NAVY]], null, 20);
        foreach ($table->filters as [$label, $value]) {
            $banner($r++, "{$label}: {$value}", ['size' => 9, 'color' => ['rgb' => '475569']]);
        }
        if ($table->summary) {
            $r++;
            foreach ($table->summary as [$label, $value]) {
                $ws->setCellValue("A{$r}", $label);
                $ws->getStyle("A{$r}")->getFont()->setBold(true)->getColor()->setRGB(self::NAVY);
                if (is_string($value) && preg_match('/^-?\d+\.\d{2}$/', $value)) {
                    $ws->setCellValue("B{$r}", Money::toFloat($value));
                    $ws->getStyle("B{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
                } else {
                    $ws->setCellValue("B{$r}", (string) $value);
                }
                $r++;
            }
        }
        $r++;

        $headerRow = $r;
        foreach ($table->columns as $i => [$label, $key, $kind]) {
            $cell = Coordinate::stringFromColumnIndex($i + 1).$r;
            $ws->setCellValue($cell, $label);
            $ws->getStyle($cell)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => $fill(self::NAVY),
                'alignment' => ['horizontal' => in_array($kind, ['money', 'number', 'percent'], true) ? Alignment::HORIZONTAL_RIGHT : Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
        }
        $ws->getRowDimension($r)->setRowHeight(22);
        $r++;

        $widths = array_map(fn ($c) => mb_strlen($c[0]) + 2, $table->columns);
        foreach ($table->rows as $idx => $row) {
            foreach ($table->columns as $i => [$label, $key, $kind]) {
                $cell = Coordinate::stringFromColumnIndex($i + 1).$r;
                $value = $row[$key] ?? null;
                $style = $ws->getStyle($cell);
                if (in_array($kind, ['money', 'number'], true) && $value !== null && $value !== '') {
                    $ws->setCellValue($cell, $kind === 'money' ? Money::toFloat($value) : (float) $value);
                    if ($kind === 'money') {
                        $style->getNumberFormat()->setFormatCode('#,##0.00');
                    }
                } elseif (in_array($kind, ['date', 'datetime'], true) && $value instanceof CarbonInterface) {
                    $ws->setCellValue($cell, ExcelDate::PHPToExcel($value->toDateTimeImmutable()));
                    $style->getNumberFormat()->setFormatCode($kind === 'date' ? 'DD-MM-YYYY' : 'DD-MM-YYYY HH:MM');
                } elseif ($value !== null && $value !== '') {
                    $ws->setCellValueExplicit($cell, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
                $style->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D6DFEA');
                if ($idx % 2) {
                    $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F5F7FA');
                }
                $widths[$i] = min(max($widths[$i], mb_strlen(self::format($value, $kind)) + 2), 60);
            }
            $r++;
        }

        if ($table->totals) {
            foreach ($table->columns as $i => [$label, $key, $kind]) {
                $cell = Coordinate::stringFromColumnIndex($i + 1).$r;
                $value = $i === 0 ? $table->totalsLabel : ($table->totals[$key] ?? null);
                if ($i > 0 && $kind === 'money' && $value !== null) {
                    $ws->setCellValue($cell, Money::toFloat($value));
                    $ws->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.00');
                } elseif ($value !== null) {
                    $ws->setCellValue($cell, $i === 0 ? $value : self::format($value, $kind));
                }
                $ws->getStyle($cell)->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => self::NAVY]], 'fill' => $fill('FFE4CF'),
                    'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => self::ORANGE]]],
                ]);
            }
            $r++;
        }

        $r++;
        $banner($r, 'Generated '.now()->format('d-m-Y H:i').' · '.$table->moneyNote().' '.$business->report_footer,
            ['size' => 8, 'italic' => true, 'color' => ['rgb' => '64748B']]);
        foreach ($widths as $i => $w) {
            $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth(max($w, 10));
        }
        $ws->freezePane('A'.($headerRow + 1));
        $setup = $ws->getPageSetup();
        $setup->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);
        $setup->setOrientation($ncols > 6 ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT);
        $setup->setFitToWidth(1)->setFitToHeight(0);

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($book))->save($tmp);
        $content = file_get_contents($tmp);
        @unlink($tmp);

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.self::filename($table, 'xlsx').'"',
        ]);
    }

    private static function pdf(ReportTable $table, BusinessSetting $business): Response
    {
        $logo = null;
        if ($business->logo && Storage::disk('local')->exists($business->logo)) {
            $mime = Storage::disk('local')->mimeType($business->logo) ?: 'image/png';
            $logo = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($business->logo));
        }
        $landscape = count($table->columns) > 6;
        $html = view('exports.pdf', [
            'table' => $table, 'business' => $business, 'logo' => $logo, 'landscape' => $landscape,
            'generated' => now()->format('d-m-Y H:i'), 'user' => auth()->user(),
        ])->render();
        $pdf = Pdf::loadHTML($html)->setPaper('a4', $landscape ? 'landscape' : 'portrait');

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.self::filename($table, 'pdf').'"',
        ]);
    }
}
