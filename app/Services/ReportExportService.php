<?php

namespace App\Services;

use App\Jobs\GenerateReportExportJob;
use App\Models\AuditEvent;
use App\Models\CustomReport;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Story 13.4: export authorized standard and custom report results.
 */
class ReportExportService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
        private StandardReportService $standardReports,
        private CustomReportService $customReports,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function catalog(User $actor): array
    {
        $this->assertCan($actor, 'reports.export');

        return [
            'formats' => config('report_exports.formats', []),
            'classifications' => config('report_exports.classifications', []),
            'interactive_row_threshold' => config('report_exports.interactive_row_threshold', 100),
            'download_ttl_hours' => config('report_exports.download_ttl_hours', 24),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function request(User $actor, array $payload): array
    {
        $this->assertCan($actor, 'reports.export');

        $validated = validator($payload, [
            'report_type' => ['required', 'string', 'in:standard,custom'],
            'report_key' => ['required_if:report_type,standard', 'nullable', 'string'],
            'custom_report_id' => ['required_if:report_type,custom', 'nullable', 'integer', 'exists:custom_reports,id'],
            'format' => ['required', 'string', 'in:' . implode(',', array_keys(config('report_exports.formats', [])))],
            'classification' => ['nullable', 'string', 'in:' . implode(',', config('report_exports.classifications', []))],
            'filters' => ['nullable', 'array'],
            'force_async' => ['nullable', 'boolean'],
        ])->validate();

        $format = (string) $validated['format'];
        $filters = $validated['filters'] ?? [];
        $reportData = $this->resolveReportData($actor, $validated);
        $tabular = $this->tabularize($reportData, $validated);
        $rowCount = count($tabular['rows']);

        $threshold = (int) config('report_exports.interactive_row_threshold', 100);
        $async = (bool) ($validated['force_async'] ?? false)
            || ! empty($filters['__test_force_async'])
            || $rowCount > $threshold;

        $reference = (string) Str::uuid();
        $plainToken = Str::random(48);

        $export = ReportExport::create([
            'reference' => $reference,
            'requested_by' => $actor->id,
            'report_type' => $validated['report_type'],
            'report_key' => $validated['report_key'] ?? null,
            'custom_report_id' => $validated['custom_report_id'] ?? null,
            'format' => $format,
            'status' => $async ? ReportExport::STATUS_PENDING : ReportExport::STATUS_PROCESSING,
            'filters' => $this->sanitizeFilters($filters),
            'metadata' => [
                'title' => $tabular['title'],
                'labels' => $tabular['labels'],
                'generated_at' => now()->toIso8601String(),
                'delivery_channel' => $format === 'email' ? 'email' : 'download',
            ],
            'classification' => $validated['classification'] ?? $this->defaultClassification($reportData),
            'row_count' => $rowCount,
            'download_token_hash' => Hash::make($plainToken),
            'download_expires_at' => now()->addHours((int) config('report_exports.download_ttl_hours', 24)),
        ]);

        $this->audit($actor, 'report_export.requested', $export, [
            'async' => $async,
            'format' => $format,
            'row_count' => $rowCount,
        ]);

        if ($async) {
            GenerateReportExportJob::dispatch($export);

            return [
                'reference' => $export->reference,
                'status' => $export->status,
                'async' => true,
                'row_count' => $rowCount,
                'download' => [
                    'token' => $plainToken,
                    'expires_at' => $export->download_expires_at?->toIso8601String(),
                ],
                'support' => [
                    'hint' => 'Export is queued. Poll status or download when completed.',
                ],
            ];
        }

        $this->generateFile($export, $tabular, $plainToken);

        return $this->formatExport($export->fresh(), $plainToken, async: false);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{export: ReportExport, token: string, payload: array<string, mixed>}
     */
    public function generateDistributionExport(User $actor, array $payload): array
    {
        $this->assertCan($actor, 'reports.export');

        $validated = validator($payload, [
            'report_type' => ['required', 'string', 'in:standard,custom'],
            'report_key' => ['required_if:report_type,standard', 'nullable', 'string'],
            'custom_report_id' => ['required_if:report_type,custom', 'nullable', 'integer', 'exists:custom_reports,id'],
            'format' => ['required', 'string', 'in:' . implode(',', array_keys(config('report_exports.formats', [])))],
            'classification' => ['nullable', 'string', 'in:' . implode(',', config('report_exports.classifications', []))],
            'filters' => ['nullable', 'array'],
        ])->validate();

        $reportData = $this->resolveReportData($actor, $validated);
        $tabular = $this->tabularize($reportData, $validated);
        $plainToken = Str::random(48);
        $reference = (string) Str::uuid();

        $export = ReportExport::create([
            'reference' => $reference,
            'requested_by' => $actor->id,
            'report_type' => $validated['report_type'],
            'report_key' => $validated['report_key'] ?? null,
            'custom_report_id' => $validated['custom_report_id'] ?? null,
            'format' => $validated['format'],
            'status' => ReportExport::STATUS_PROCESSING,
            'filters' => $this->sanitizeFilters($validated['filters'] ?? []),
            'metadata' => [
                'title' => $tabular['title'],
                'labels' => $tabular['labels'],
                'generated_at' => now()->toIso8601String(),
                'delivery_channel' => 'schedule',
            ],
            'classification' => $validated['classification'] ?? $this->defaultClassification($reportData),
            'row_count' => count($tabular['rows']),
            'download_token_hash' => Hash::make($plainToken),
            'download_expires_at' => now()->addHours((int) config('report_exports.download_ttl_hours', 24)),
        ]);

        $this->generateFile($export, $tabular, $plainToken);

        return [
            'export' => $export->fresh(),
            'token' => $plainToken,
            'payload' => $this->formatExport($export->fresh(), $plainToken, async: false),
        ];
    }

    public function processQueued(ReportExport $export): void
    {
        $export->refresh();
        if (in_array($export->status, [ReportExport::STATUS_COMPLETED, ReportExport::STATUS_FAILED, ReportExport::STATUS_EXPIRED], true)) {
            return;
        }

        $export->update([
            'status' => ReportExport::STATUS_PROCESSING,
            'attempts' => $export->attempts + 1,
        ]);

        try {
            $actor = User::query()->findOrFail($export->requested_by);
            $payload = [
                'report_type' => $export->report_type,
                'report_key' => $export->report_key,
                'custom_report_id' => $export->custom_report_id,
                'format' => $export->format,
                'filters' => $export->filters ?? [],
            ];
            $reportData = $this->resolveReportData($actor, $payload);
            $tabular = $this->tabularize($reportData, $payload);

            $this->generateFile($export, $tabular, plainToken: '', persistToken: false);

            $this->audit($actor, 'report_export.completed', $export->fresh(), [
                'async' => true,
                'attempts' => $export->attempts,
            ]);
        } catch (\Throwable $exception) {
            $export->update([
                'status' => ReportExport::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => 'Export generation failed.',
            ]);

            $actor = User::query()->find($export->requested_by);
            if ($actor !== null) {
                $this->audit($actor, 'report_export.failed', $export, [
                    'attempts' => $export->attempts,
                ]);
            }

            throw $exception;
        }
    }

    public function status(User $actor, string $reference): array
    {
        $this->assertCan($actor, 'reports.export');

        $export = $this->findOwnedExport($actor, $reference);

        return $this->formatExport($export, token: null, async: $export->status === ReportExport::STATUS_PENDING || $export->status === ReportExport::STATUS_PROCESSING);
    }

    /**
     * @return array{filename: string, mime: string, content: string}
     */
    public function download(User $actor, string $reference, string $token): array
    {
        $this->assertCan($actor, 'reports.export');

        $export = $this->findOwnedExport($actor, $reference);

        if ($export->status !== ReportExport::STATUS_COMPLETED) {
            throw new ReportExportException('Export is not ready for download.', 'not_ready', 409);
        }

        if ($export->download_expires_at !== null && $export->download_expires_at->isPast()) {
            $export->update(['status' => ReportExport::STATUS_EXPIRED]);
            $this->audit($actor, 'report_export.expired', $export);
            throw new ReportExportException('Download link has expired.', 'expired', 410);
        }

        if ($export->download_token_hash === null || ! Hash::check($token, $export->download_token_hash)) {
            throw new ReportExportException('Invalid download token.', 'invalid_token', 403);
        }

        if ($export->storage_path === null || ! Storage::disk('local')->exists($export->storage_path)) {
            throw new ReportExportException('Export file is unavailable.', 'missing_file', 404);
        }

        $this->audit($actor, 'report_export.downloaded', $export);

        $format = config("report_exports.formats.{$export->format}", []);

        return [
            'filename' => $this->safeFilename($export),
            'mime' => $format['mime'] ?? 'application/octet-stream',
            'content' => Storage::disk('local')->get($export->storage_path),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolveReportData(User $actor, array $payload): array
    {
        if ($payload['report_type'] === ReportExport::TYPE_STANDARD) {
            $key = (string) ($payload['report_key'] ?? '');
            if ($key === '') {
                throw ValidationException::withMessages(['report_key' => ['Standard report key is required.']]);
            }

            return $this->standardReports->run($actor, $key, $payload['filters'] ?? []);
        }

        $report = CustomReport::query()->findOrFail((int) $payload['custom_report_id']);

        return $this->customReports->run($actor, $report, $payload['filters'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $reportData
     * @param  array<string, mixed>  $payload
     * @return array{title: string, labels: array<string, string>, headers: list<string>, rows: list<array<string, mixed>>}
     */
    private function tabularize(array $reportData, array $payload): array
    {
        if ($payload['report_type'] === ReportExport::TYPE_CUSTOM) {
            $columns = $reportData['rows'][0] ?? [];
            $headers = $reportData['columns'] ?? array_keys(is_array($columns) ? $columns : []);

            return [
                'title' => (string) ($reportData['report_name'] ?? 'Custom report'),
                'labels' => array_combine($headers, $headers) ?: [],
                'headers' => array_values($headers),
                'rows' => $reportData['rows'] ?? [],
                'context' => [
                    'filters' => $reportData['filters'] ?? ($payload['filters'] ?? []),
                    'generated_at' => $reportData['generated_at'] ?? now()->toIso8601String(),
                    'classification' => $reportData['classification'] ?? null,
                ],
            ];
        }

        $headers = ['section', 'label', 'value', 'state', 'definition'];
        $rows = [];
        foreach ($reportData['sections'] ?? [] as $section) {
            $rows[] = [
                'section' => $section['key'] ?? '',
                'label' => $section['label'] ?? '',
                'value' => $section['value'] ?? '',
                'state' => $section['state'] ?? '',
                'definition' => $section['definition'] ?? '',
            ];
        }

        return [
            'title' => (string) ($reportData['label'] ?? $reportData['key'] ?? 'Standard report'),
            'labels' => [
                'section' => 'Section',
                'label' => 'Label',
                'value' => 'Value',
                'state' => 'State',
                'definition' => 'Definition',
            ],
            'headers' => $headers,
            'rows' => $rows,
            'context' => [
                'filters' => $reportData['filters'] ?? ($payload['filters'] ?? []),
                'period' => $reportData['period'] ?? null,
                'generated_at' => $reportData['generated_at'] ?? now()->toIso8601String(),
                'classification' => $reportData['classification'] ?? null,
            ],
        ];
    }

    /**
     * @param  array{title: string, labels: array<string, string>, headers: list<string>, rows: list<array<string, mixed>>, context?: array<string, mixed>}  $tabular
     */
    private function generateFile(ReportExport $export, array $tabular, string $plainToken, bool $persistToken = true): void
    {
        $content = match ($export->format) {
            'csv', 'email' => $this->buildCsv($tabular, $export),
            'excel' => $this->buildExcel($tabular, $export),
            'pdf' => $this->buildPdf($tabular, $export),
            'print' => $this->buildPrintHtml($tabular, $export),
            'dashboard' => $this->buildDashboardJson($tabular, $export),
            default => throw new ReportExportException('Unsupported export format.', 'unsupported_format', 422),
        };

        $extension = config("report_exports.formats.{$export->format}.extension", 'dat');
        $path = 'report-exports/' . $export->reference . '.' . $extension;
        Storage::disk('local')->put($path, $content);

        $updates = [
            'storage_path' => $path,
            'status' => ReportExport::STATUS_COMPLETED,
            'completed_at' => now(),
            'row_count' => count($tabular['rows']),
            'metadata' => array_merge($export->metadata ?? [], [
                'filename' => $this->safeFilename($export),
                'filters' => $export->filters,
                'generated_at' => now()->toIso8601String(),
            ]),
        ];

        if ($persistToken) {
            $updates['download_token_hash'] = Hash::make($plainToken);
        }

        $export->update($updates);

        if ($persistToken) {
            $actor = User::query()->find($export->requested_by);
            if ($actor !== null) {
                $this->audit($actor, 'report_export.completed', $export, ['async' => false]);
            }
        }
    }

    /**
     * @param  array{title: string, labels: array<string, string>, headers: list<string>, rows: list<array<string, mixed>>, context?: array<string, mixed>}  $tabular
     */
    private function buildCsv(array $tabular, ReportExport $export): string
    {
        $lines = [
            $this->csvRow(['Report', $tabular['title']]),
            $this->csvRow(['Generated', now()->toIso8601String()]),
            $this->csvRow(['Classification', $export->classification ?? 'internal']),
            $this->csvRow(['Filters', json_encode($export->filters ?? [], JSON_THROW_ON_ERROR)]),
            '',
            $this->csvRow(array_map(fn (string $header) => $tabular['labels'][$header] ?? $header, $tabular['headers'])),
        ];

        foreach ($tabular['rows'] as $row) {
            $line = [];
            foreach ($tabular['headers'] as $header) {
                $line[] = $this->sanitizeSpreadsheetCell($row[$header] ?? '');
            }
            $lines[] = $this->csvRow($line);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{title: string, labels: array<string, string>, headers: list<string>, rows: list<array<string, mixed>>, context?: array<string, mixed>}  $tabular
     */
    private function buildExcel(array $tabular, ReportExport $export): string
    {
        $lines = [
            implode("\t", ['Report', $tabular['title']]),
            implode("\t", ['Generated', now()->toIso8601String()]),
            implode("\t", ['Classification', $export->classification ?? 'internal']),
            '',
            implode("\t", array_map(fn (string $header) => $tabular['labels'][$header] ?? $header, $tabular['headers'])),
        ];

        foreach ($tabular['rows'] as $row) {
            $line = [];
            foreach ($tabular['headers'] as $header) {
                $line[] = $this->sanitizeSpreadsheetCell($row[$header] ?? '');
            }
            $lines[] = implode("\t", $line);
        }

        return "\xEF\xBB\xBF" . implode("\n", $lines);
    }

    /**
     * @param  array{title: string, labels: array<string, string>, headers: list<string>, rows: list<array<string, mixed>>, context?: array<string, mixed>}  $tabular
     */
    private function buildPdf(array $tabular, ReportExport $export): string
    {
        $lines = [
            $tabular['title'],
            'Generated: ' . now()->toIso8601String(),
            'Classification: ' . ($export->classification ?? 'internal'),
            'Filters: ' . json_encode($export->filters ?? []),
            '',
        ];

        $lines[] = implode(' | ', array_map(fn (string $header) => $tabular['labels'][$header] ?? $header, $tabular['headers']));
        foreach ($tabular['rows'] as $row) {
            $values = [];
            foreach ($tabular['headers'] as $header) {
                $values[] = (string) ($row[$header] ?? '');
            }
            $lines[] = implode(' | ', $values);
        }

        return SimplePdfDocument::fromLines($lines, $tabular['title']);
    }

    /**
     * @param  array{title: string, labels: array<string, string>, headers: list<string>, rows: list<array<string, mixed>>, context?: array<string, mixed>}  $tabular
     */
    private function buildPrintHtml(array $tabular, ReportExport $export): string
    {
        $headerCells = '';
        foreach ($tabular['headers'] as $header) {
            $headerCells .= '<th>' . e($tabular['labels'][$header] ?? $header) . '</th>';
        }

        $bodyRows = '';
        foreach ($tabular['rows'] as $row) {
            $bodyRows .= '<tr>';
            foreach ($tabular['headers'] as $header) {
                $bodyRows .= '<td>' . e((string) ($row[$header] ?? '')) . '</td>';
            }
            $bodyRows .= '</tr>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{$this->e($tabular['title'])}</title>
  <style>
    body { font-family: sans-serif; margin: 2rem; color: #111; }
  </style>
</head>
<body>
  <h1>{$this->e($tabular['title'])}</h1>
  <p>Generated: {$this->e(now()->toIso8601String())}</p>
  <p>Classification: {$this->e($export->classification ?? 'internal')}</p>
  <p>Filters: {$this->e(json_encode($export->filters ?? []))}</p>
  <table border="1" cellpadding="6" cellspacing="0">
    <thead><tr>{$headerCells}</tr></thead>
    <tbody>{$bodyRows}</tbody>
  </table>
</body>
</html>
HTML;
    }

    /**
     * @param  array{title: string, labels: array<string, string>, headers: list<string>, rows: list<array<string, mixed>>, context?: array<string, mixed>}  $tabular
     */
    private function buildDashboardJson(array $tabular, ReportExport $export): string
    {
        return json_encode([
            'title' => $tabular['title'],
            'generated_at' => now()->toIso8601String(),
            'classification' => $export->classification ?? 'internal',
            'filters' => $export->filters ?? [],
            'labels' => $tabular['labels'],
            'headers' => $tabular['headers'],
            'rows' => $tabular['rows'],
            'context' => $tabular['context'] ?? [],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<mixed>  $values
     */
    private function csvRow(array $values): string
    {
        return implode(',', array_map(function (mixed $value): string {
            $string = $this->sanitizeSpreadsheetCell($value);

            return '"' . str_replace('"', '""', $string) . '"';
        }, $values));
    }

    private function sanitizeSpreadsheetCell(mixed $value): string
    {
        $string = (string) ($value ?? '');
        if ($string !== '' && in_array($string[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $string;
        }

        return $string;
    }

    private function safeFilename(ReportExport $export): string
    {
        $title = (string) (($export->metadata['title'] ?? null) ?: 'report-export');
        $slug = Str::slug(Str::limit($title, 60, ''));
        $extension = config("report_exports.formats.{$export->format}.extension", 'dat');

        return ($slug !== '' ? $slug : 'report-export') . '-' . $export->reference . '.' . $extension;
    }

    private function defaultClassification(array $reportData): string
    {
        if (! empty($reportData['classification'])) {
            return (string) $reportData['classification'];
        }

        return 'internal';
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function sanitizeFilters(array $filters): array
    {
        unset($filters['__test_force_async'], $filters['__test_force_failure']);

        return $filters;
    }

    private function findOwnedExport(User $actor, string $reference): ReportExport
    {
        $export = ReportExport::query()->where('reference', $reference)->firstOrFail();

        if ((int) $export->requested_by !== (int) $actor->id) {
            throw new AuthorizationException('Forbidden.');
        }

        return $export;
    }

    private function formatExport(ReportExport $export, ?string $token, bool $async): array
    {
        $payload = [
            'reference' => $export->reference,
            'status' => $export->status,
            'async' => $async,
            'format' => $export->format,
            'classification' => $export->classification,
            'row_count' => $export->row_count,
            'metadata' => $export->metadata,
            'filters' => $export->filters,
            'completed_at' => $export->completed_at?->toIso8601String(),
            'failed_at' => $export->failed_at?->toIso8601String(),
            'failure_reason' => $export->failure_reason,
            'download' => [
                'expires_at' => $export->download_expires_at?->toIso8601String(),
            ],
        ];

        if ($token !== null && $export->status === ReportExport::STATUS_COMPLETED) {
            $payload['download']['token'] = $token;
        }

        return $payload;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function allows(User $actor, string $permission): bool
    {
        return $this->authorization->allows($actor, $permission);
    }

    private function assertCan(User $actor, string $permission): void
    {
        if (! $this->allows($actor, $permission)) {
            throw new AuthorizationException('Forbidden.');
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function audit(User $actor, string $action, ReportExport $export, array $context = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'reports',
            branchId: $actor->branch_id,
            subjectType: ReportExport::class,
            subjectId: $export->id,
            metadata: array_merge([
                'reference' => $export->reference,
                'format' => $export->format,
                'report_type' => $export->report_type,
            ], $context),
        );
    }
}
