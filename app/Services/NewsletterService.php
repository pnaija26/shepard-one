<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CommunicationSuppression;
use App\Models\Member;
use App\Models\MemberNotification;
use App\Models\Newsletter;
use App\Models\NewsletterDelivery;
use App\Models\NewsletterEvent;
use App\Models\NewsletterPreview;
use App\Models\NewsletterVersion;
use App\Models\User;
use App\Services\BranchScope;
use App\Services\BranchScopeException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Story 10.5: compose, approve, schedule, deliver, and measure newsletters.
 */
class NewsletterService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, Newsletter>
     */
    public function list(User $actor): Collection
    {
        $this->assertCan($actor, 'newsletters.read');

        $query = Newsletter::query()->with(['branch:id,name'])->orderByDesc('id');
        $this->applyBranchScope($query, $actor);

        return $query->limit(100)->get();
    }

    public function show(User $actor, Newsletter $newsletter): Newsletter
    {
        $this->assertCan($actor, 'newsletters.read');
        $this->assertInScope($actor, $newsletter);

        return $newsletter->load([
            'branch:id,name',
            'versions' => fn ($q) => $q->orderByDesc('version'),
            'versions.previews' => fn ($q) => $q->orderByDesc('ran_at')->limit(6),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): Newsletter
    {
        $this->assertCan($actor, 'newsletters.manage');

        $validated = $this->validatePayload($payload);
        if (! empty($validated['branch_id'])) {
            $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        }

        $sections = array_values($validated['sections']);
        $validation = $this->validateSections($sections);
        if (! $validation['valid']) {
            throw new NewsletterException('Newsletter content is invalid.', 'invalid_content', 422, $validation);
        }

        return DB::transaction(function () use ($actor, $validated, $sections, $validation): Newsletter {
            $newsletter = Newsletter::create([
                'reference' => 'NL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
                'name' => $validated['name'],
                'branch_id' => $validated['branch_id'] ?? null,
                'status' => Newsletter::STATUS_DRAFT,
                'current_version' => 0,
                'audience_type' => $validated['audience_type'] ?? 'branch',
                'audience_params' => $validated['audience_params'] ?? [],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            NewsletterVersion::create([
                'newsletter_id' => $newsletter->id,
                'version' => 1,
                'status' => NewsletterVersion::STATUS_DRAFT,
                'subject' => $validated['subject'],
                'preview_text' => $validated['preview_text'] ?? null,
                'sections' => $sections,
                'has_unsubscribe' => $validation['has_unsubscribe'],
                'last_validation' => $validation,
                'created_by' => $actor->id,
            ]);

            $this->audit($actor, 'newsletter.created', $newsletter, ['version' => 1]);

            return $newsletter->fresh(['versions']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(User $actor, Newsletter $newsletter, array $payload): Newsletter
    {
        $this->assertCan($actor, 'newsletters.manage');
        $this->assertInScope($actor, $newsletter);

        if (in_array($newsletter->status, [Newsletter::STATUS_SENT, Newsletter::STATUS_CANCELLED], true)) {
            throw new NewsletterException('Sent or cancelled newsletters cannot be edited.', 'not_editable', 422);
        }

        $validated = $this->validatePayload($payload, requireName: false, allowPartial: true);
        $draft = $this->draftVersion($newsletter);

        $subject = array_key_exists('subject', $validated) ? $validated['subject'] : $draft->subject;
        $previewText = array_key_exists('preview_text', $validated) ? $validated['preview_text'] : $draft->preview_text;
        $sections = array_key_exists('sections', $validated) ? array_values($validated['sections']) : ($draft->sections ?? []);

        $validation = $this->validateSections($sections);
        if (! $validation['valid']) {
            throw new NewsletterException('Newsletter content is invalid.', 'invalid_content', 422, $validation);
        }

        $wasApproved = in_array($newsletter->status, [
            Newsletter::STATUS_APPROVED,
            Newsletter::STATUS_SCHEDULED,
            Newsletter::STATUS_PENDING_APPROVAL,
        ], true) || $draft->status === NewsletterVersion::STATUS_APPROVED;

        return DB::transaction(function () use ($actor, $newsletter, $draft, $validated, $subject, $previewText, $sections, $validation, $wasApproved): Newsletter {
            if ($draft->status === NewsletterVersion::STATUS_APPROVED) {
                // Material edit of locked approved content → new draft requiring renewed approval.
                $draft = NewsletterVersion::create([
                    'newsletter_id' => $newsletter->id,
                    'version' => max($newsletter->current_version, $newsletter->approved_version ?? 0) + 1,
                    'status' => NewsletterVersion::STATUS_DRAFT,
                    'subject' => $subject,
                    'preview_text' => $previewText,
                    'sections' => $sections,
                    'has_unsubscribe' => $validation['has_unsubscribe'],
                    'last_validation' => $validation,
                    'created_by' => $actor->id,
                ]);
            } else {
                $draft->update([
                    'subject' => $subject,
                    'preview_text' => $previewText,
                    'sections' => $sections,
                    'has_unsubscribe' => $validation['has_unsubscribe'],
                    'last_validation' => $validation,
                ]);
            }

            $updates = [
                'updated_by' => $actor->id,
                'status' => Newsletter::STATUS_DRAFT,
                'approved_by' => null,
                'approved_at' => null,
                'scheduled_at' => $wasApproved ? null : $newsletter->scheduled_at,
            ];
            if (! empty($validated['name'])) {
                $updates['name'] = $validated['name'];
            }
            if (isset($validated['audience_type'])) {
                $updates['audience_type'] = $validated['audience_type'];
            }
            if (array_key_exists('audience_params', $validated)) {
                $updates['audience_params'] = $validated['audience_params'];
            }

            $newsletter->update($updates);

            $this->audit($actor, 'newsletter.draft_updated', $newsletter, [
                'version' => $draft->version,
                'requires_renewed_approval' => $wasApproved,
            ]);

            return $newsletter->fresh(['versions']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(User $actor, Newsletter $newsletter): array
    {
        $this->assertCan($actor, 'newsletters.manage');
        $this->assertInScope($actor, $newsletter);

        $draft = $this->draftVersion($newsletter);
        $validation = $this->validateSections($draft->sections ?? []);
        $draft->update([
            'last_validation' => $validation,
            'has_unsubscribe' => $validation['has_unsubscribe'],
        ]);

        return [
            'newsletter_id' => $newsletter->id,
            'version' => $draft->version,
            'validation' => $validation,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preview(User $actor, Newsletter $newsletter, array $payload = []): array
    {
        $this->assertCan($actor, 'newsletters.manage');
        $this->assertInScope($actor, $newsletter);

        $viewports = $payload['viewports'] ?? array_keys(config('newsletters.viewports', []));
        $draft = $this->draftVersion($newsletter);
        $validation = $this->validateSections($draft->sections ?? []);
        $html = $this->renderHtml($draft);

        $results = [];
        foreach ($viewports as $viewport) {
            if (! isset(config('newsletters.viewports')[$viewport])) {
                continue;
            }
            $width = config('newsletters.viewports.' . $viewport . '.width');
            $result = [
                'viewport' => $viewport,
                'width' => $width,
                'subject' => $draft->subject,
                'html_excerpt' => Str::limit(strip_tags($html), 280),
                'validation' => $validation,
            ];
            $preview = NewsletterPreview::create([
                'newsletter_version_id' => $draft->id,
                'viewport' => $viewport,
                'result' => $result,
                'passed' => $validation['valid'],
                'ran_by' => $actor->id,
                'ran_at' => now(),
            ]);
            $results[] = array_merge($result, ['preview_id' => $preview->id]);
        }

        $this->audit($actor, 'newsletter.previewed', $newsletter, [
            'version' => $draft->version,
            'viewports' => array_column($results, 'viewport'),
        ]);

        return [
            'newsletter_id' => $newsletter->id,
            'version' => $draft->version,
            'passed' => $validation['valid'],
            'validation' => $validation,
            'previews' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendTest(User $actor, Newsletter $newsletter, array $payload): array
    {
        $this->assertCan($actor, 'newsletters.manage');
        $this->assertInScope($actor, $newsletter);

        $validated = validator($payload, [
            'member_ids' => ['required', 'array', 'min:1', 'max:5'],
            'member_ids.*' => ['integer', 'exists:members,id'],
        ])->validate();

        $draft = $this->draftVersion($newsletter);
        $validation = $this->validateSections($draft->sections ?? []);
        if (! $validation['valid']) {
            throw new NewsletterException('Cannot test-send an invalid newsletter.', 'invalid_content', 422, $validation);
        }

        $sent = 0;
        foreach ($validated['member_ids'] as $memberId) {
            $member = Member::query()->find($memberId);
            if ($member === null) {
                continue;
            }
            $delivery = NewsletterDelivery::query()->updateOrCreate(
                [
                    'newsletter_id' => $newsletter->id,
                    'member_id' => $member->id,
                    'newsletter_version_id' => $draft->id,
                    'is_test' => true,
                ],
                [
                    'channel' => 'email',
                    'status' => NewsletterDelivery::STATUS_SENT,
                    'provider_ref' => 'TEST-' . Str::upper(Str::random(6)),
                    'sent_at' => now(),
                ],
            );
            $this->recordEvent($newsletter, $delivery, 'sent', ['test' => true]);
            if ($member->user_id) {
                MemberNotification::create([
                    'member_id' => $member->id,
                    'user_id' => $member->user_id,
                    'type' => 'communication.announcement',
                    'category' => 'administrative',
                    'message' => '[Test] ' . $draft->subject,
                    'metadata' => [
                        'newsletter_id' => $newsletter->id,
                        'version' => $draft->version,
                        'test' => true,
                    ],
                    'deep_link' => '/newsletters',
                ]);
            }
            $sent++;
        }

        $this->audit($actor, 'newsletter.test_sent', $newsletter, [
            'version' => $draft->version,
            'sent' => $sent,
        ]);

        return ['sent' => $sent, 'version' => $draft->version];
    }

    public function submitForApproval(User $actor, Newsletter $newsletter): Newsletter
    {
        $this->assertCan($actor, 'newsletters.manage');
        $this->assertInScope($actor, $newsletter);

        $draft = $this->draftVersion($newsletter);
        $validation = $this->validateSections($draft->sections ?? []);
        if (! $validation['valid']) {
            throw new NewsletterException('Cannot submit an invalid newsletter.', 'invalid_content', 422, $validation);
        }

        $draft->update(['last_validation' => $validation]);
        $newsletter->update([
            'status' => Newsletter::STATUS_PENDING_APPROVAL,
            'updated_by' => $actor->id,
        ]);

        $this->audit($actor, 'newsletter.submitted', $newsletter, ['version' => $draft->version]);

        return $newsletter->fresh(['versions']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function approve(User $actor, Newsletter $newsletter, array $payload = []): Newsletter
    {
        $this->assertCan($actor, 'newsletters.approve');
        $this->assertInScope($actor, $newsletter);

        if (! in_array($newsletter->status, [Newsletter::STATUS_PENDING_APPROVAL, Newsletter::STATUS_DRAFT], true)) {
            throw new NewsletterException('Newsletter is not awaiting approval.', 'not_pending', 422);
        }

        $draft = $this->draftVersion($newsletter);
        $validation = $this->validateSections($draft->sections ?? []);
        if (! $validation['valid']) {
            throw new NewsletterException('Cannot approve an invalid newsletter.', 'invalid_content', 422, $validation);
        }

        $scheduledAt = isset($payload['scheduled_at']) ? Carbon::parse($payload['scheduled_at']) : null;

        return DB::transaction(function () use ($actor, $newsletter, $draft, $validation, $scheduledAt): Newsletter {
            if ($newsletter->approved_version) {
                NewsletterVersion::query()
                    ->where('newsletter_id', $newsletter->id)
                    ->where('version', $newsletter->approved_version)
                    ->where('status', NewsletterVersion::STATUS_APPROVED)
                    ->update(['status' => NewsletterVersion::STATUS_SUPERSEDED]);
            }

            $draft->update([
                'status' => NewsletterVersion::STATUS_APPROVED,
                'last_validation' => $validation,
                'has_unsubscribe' => $validation['has_unsubscribe'],
                'approved_at' => now(),
                'approved_by' => $actor->id,
            ]);

            $newsletter->update([
                'status' => $scheduledAt ? Newsletter::STATUS_SCHEDULED : Newsletter::STATUS_APPROVED,
                'current_version' => $draft->version,
                'approved_version' => $draft->version,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'scheduled_at' => $scheduledAt,
                'updated_by' => $actor->id,
            ]);

            $this->audit($actor, 'newsletter.approved', $newsletter, [
                'version' => $draft->version,
                'scheduled_at' => $scheduledAt?->toIso8601String(),
            ]);

            return $newsletter->fresh(['versions']);
        });
    }

    /**
     * Deliver due scheduled/approved newsletters.
     *
     * @return array{processed: int, sent: int, skipped: int}
     */
    public function processDue(User $actor, ?int $branchId = null): array
    {
        $this->assertCan($actor, 'newsletters.process');

        $counts = ['processed' => 0, 'sent' => 0, 'skipped' => 0];

        $query = Newsletter::query()
            ->where(function (Builder $q): void {
                $q->where(function (Builder $inner): void {
                    $inner->where('status', Newsletter::STATUS_SCHEDULED)
                        ->where('scheduled_at', '<=', now());
                })->orWhere(function (Builder $inner): void {
                    $inner->where('status', Newsletter::STATUS_APPROVED)
                        ->whereNull('scheduled_at');
                });
            })
            ->orderBy('id');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        } else {
            $this->applyBranchScope($query, $actor);
        }

        foreach ($query->limit(10)->get() as $newsletter) {
            $counts['processed']++;
            $result = $this->deliver($actor, $newsletter);
            $counts['sent'] += $result['sent'];
            $counts['skipped'] += $result['skipped'];
        }

        return $counts;
    }

    /**
     * @return array{sent: int, skipped: int}
     */
    public function deliver(User $actor, Newsletter $newsletter): array
    {
        if ($newsletter->approved_version === null) {
            throw new NewsletterException('No approved version to deliver.', 'not_approved', 422);
        }

        $version = NewsletterVersion::query()
            ->where('newsletter_id', $newsletter->id)
            ->where('version', $newsletter->approved_version)
            ->where('status', NewsletterVersion::STATUS_APPROVED)
            ->first();

        if ($version === null) {
            throw new NewsletterException('Approved version missing.', 'not_approved', 422);
        }

        $newsletter->update(['status' => Newsletter::STATUS_SENDING]);

        $recipients = $this->resolveAudience($actor, $newsletter);
        $sent = 0;
        $skipped = 0;
        $batch = (int) config('newsletters.batch_size', 100);

        foreach ($recipients->take($batch) as $member) {
            if (! $member->consent_data_processing) {
                $this->skipDelivery($newsletter, $version, $member, 'missing_consent');
                $skipped++;

                continue;
            }
            if (in_array($member->lifecycle_status, ['deceased', 'archived', 'suspended'], true)) {
                $this->skipDelivery($newsletter, $version, $member, 'excluded_status');
                $skipped++;

                continue;
            }
            if (! $member->email) {
                $this->skipDelivery($newsletter, $version, $member, 'missing_destination');
                $skipped++;

                continue;
            }
            if ($this->isSuppressed($member->id)) {
                $this->skipDelivery($newsletter, $version, $member, 'unsubscribed');
                $skipped++;

                continue;
            }

            $existing = NewsletterDelivery::query()
                ->where('newsletter_id', $newsletter->id)
                ->where('member_id', $member->id)
                ->where('newsletter_version_id', $version->id)
                ->where('is_test', false)
                ->where('status', NewsletterDelivery::STATUS_SENT)
                ->exists();
            if ($existing) {
                $skipped++;

                continue;
            }

            $delivery = NewsletterDelivery::query()->updateOrCreate(
                [
                    'newsletter_id' => $newsletter->id,
                    'member_id' => $member->id,
                    'newsletter_version_id' => $version->id,
                    'is_test' => false,
                ],
                [
                    'channel' => 'email',
                    'status' => NewsletterDelivery::STATUS_SENT,
                    'provider_ref' => 'NL-' . Str::upper(Str::random(8)),
                    'sent_at' => now(),
                    'skip_reason' => null,
                ],
            );
            $this->recordEvent($newsletter, $delivery, 'sent');
            $this->recordEvent($newsletter, $delivery, 'delivered', ['provider' => 'simulated']);

            if ($member->user_id) {
                MemberNotification::create([
                    'member_id' => $member->id,
                    'user_id' => $member->user_id,
                    'type' => 'communication.announcement',
                    'category' => 'administrative',
                    'message' => $version->subject,
                    'metadata' => [
                        'newsletter_id' => $newsletter->id,
                        'version' => $version->version,
                    ],
                    'deep_link' => '/newsletters',
                ]);
            }
            $sent++;
        }

        $newsletter->update([
            'status' => Newsletter::STATUS_SENT,
            'sent_at' => now(),
            'updated_by' => $actor->id,
        ]);

        $this->audit($actor, 'newsletter.delivered', $newsletter, [
            'version' => $version->version,
            'sent' => $sent,
            'skipped' => $skipped,
        ]);

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordProviderEvent(User $actor, Newsletter $newsletter, array $payload): NewsletterEvent
    {
        $this->assertCan($actor, 'newsletters.process');
        $this->assertInScope($actor, $newsletter);

        $validated = validator($payload, [
            'event_type' => ['required', 'string', 'in:' . implode(',', config('newsletters.analytics_metrics', []))],
            'delivery_id' => ['nullable', 'integer', 'exists:newsletter_deliveries,id'],
            'provider' => ['nullable', 'string', 'max:64'],
            'occurred_at' => ['nullable', 'date'],
        ])->validate();

        $delivery = null;
        if (! empty($validated['delivery_id'])) {
            $delivery = NewsletterDelivery::query()
                ->where('newsletter_id', $newsletter->id)
                ->find($validated['delivery_id']);
        }

        if ($delivery && in_array($validated['event_type'], ['bounced', 'delivered'], true)) {
            $delivery->update([
                'status' => $validated['event_type'] === 'bounced'
                    ? NewsletterDelivery::STATUS_BOUNCED
                    : NewsletterDelivery::STATUS_DELIVERED,
            ]);
        }

        return $this->recordEvent(
            $newsletter,
            $delivery,
            $validated['event_type'],
            [],
            $validated['provider'] ?? 'simulated',
            isset($validated['occurred_at']) ? Carbon::parse($validated['occurred_at']) : now(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function analytics(User $actor, Newsletter $newsletter): array
    {
        $this->assertCan($actor, 'newsletters.analytics');
        $this->assertInScope($actor, $newsletter);

        $totals = [];
        foreach (config('newsletters.analytics_metrics', []) as $metric) {
            $totals[$metric] = NewsletterEvent::query()
                ->where('newsletter_id', $newsletter->id)
                ->where('event_type', $metric)
                ->count();
        }

        return [
            'newsletter_id' => $newsletter->id,
            'reference' => $newsletter->reference,
            'approved_version' => $newsletter->approved_version,
            'totals' => $totals,
            'provider_limitations' => config('newsletters.provider_limitations', []),
            'privacy_note' => 'Tracking respects consent and suppression lists; analytics exclude message body content.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function format(Newsletter $newsletter): array
    {
        $draft = $newsletter->relationLoaded('versions')
            ? $newsletter->versions->sortByDesc('version')->first()
            : $this->draftVersion($newsletter);

        return [
            'id' => $newsletter->id,
            'reference' => $newsletter->reference,
            'name' => $newsletter->name,
            'branch_id' => $newsletter->branch_id,
            'branch' => $newsletter->relationLoaded('branch') ? $newsletter->branch : null,
            'status' => $newsletter->status,
            'current_version' => $newsletter->current_version,
            'approved_version' => $newsletter->approved_version,
            'draft_version' => $draft?->version,
            'draft_status' => $draft?->status,
            'subject' => $draft?->subject,
            'preview_text' => $draft?->preview_text,
            'sections' => $draft?->sections,
            'has_unsubscribe' => $draft?->has_unsubscribe,
            'last_validation' => $draft?->last_validation,
            'audience_type' => $newsletter->audience_type,
            'audience_params' => $newsletter->audience_params,
            'scheduled_at' => $newsletter->scheduled_at?->toIso8601String(),
            'sent_at' => $newsletter->sent_at?->toIso8601String(),
            'approved_at' => $newsletter->approved_at?->toIso8601String(),
            'versions' => $newsletter->relationLoaded('versions')
                ? $newsletter->versions->map(fn (NewsletterVersion $v) => [
                    'id' => $v->id,
                    'version' => $v->version,
                    'status' => $v->status,
                    'subject' => $v->subject,
                    'has_unsubscribe' => $v->has_unsubscribe,
                    'approved_at' => $v->approved_at?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array{valid: bool, errors: array<int, array<string, mixed>>, warnings: array<int, string>, has_unsubscribe: bool}
     */
    private function validateSections(array $sections): array
    {
        $errors = [];
        $warnings = [];
        $types = config('newsletters.section_types', []);
        $hasUnsubscribe = false;

        if ($sections === []) {
            $errors[] = ['code' => 'empty_sections', 'message' => 'At least one section is required.'];
        }

        foreach ($sections as $index => $section) {
            $type = $section['type'] ?? null;
            if (! is_string($type) || ! isset($types[$type])) {
                $errors[] = ['code' => 'unknown_section', 'message' => "Section {$index} has an unsupported type.", 'index' => $index];

                continue;
            }
            if ($type === 'unsubscribe') {
                $hasUnsubscribe = true;
            }
            foreach ($types[$type]['required'] ?? [] as $field) {
                if (! array_key_exists($field, $section) || $section[$field] === '' || $section[$field] === null) {
                    $errors[] = [
                        'code' => 'inaccessible_content',
                        'message' => "Section {$index} ({$type}) is missing required field {$field}.",
                        'index' => $index,
                        'field' => $field,
                    ];
                }
            }
            if ($type === 'image' && empty($section['alt'])) {
                $errors[] = [
                    'code' => 'inaccessible_content',
                    'message' => "Image section {$index} requires alt text.",
                    'index' => $index,
                ];
            }
            if ($type === 'button' && (empty($section['label']) || empty($section['href']))) {
                $errors[] = [
                    'code' => 'inaccessible_content',
                    'message' => "Button section {$index} requires label and href.",
                    'index' => $index,
                ];
            }

            $blob = json_encode($section) ?: '';
            foreach (config('newsletters.unsafe_markup_patterns', []) as $pattern) {
                if (preg_match($pattern, $blob)) {
                    $errors[] = ['code' => 'unsafe_markup', 'message' => "Section {$index} contains unsafe markup.", 'index' => $index];
                    break;
                }
            }
        }

        if (config('newsletters.required_unsubscribe', true) && ! $hasUnsubscribe) {
            $errors[] = [
                'code' => 'missing_unsubscribe',
                'message' => 'Newsletters must include an unsubscribe section.',
            ];
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'has_unsubscribe' => $hasUnsubscribe,
        ];
    }

    private function renderHtml(NewsletterVersion $version): string
    {
        $parts = ['<article>'];
        foreach ($version->sections ?? [] as $section) {
            $type = $section['type'] ?? 'custom';
            $parts[] = match ($type) {
                'text', 'custom', 'announcement' => '<p>' . e((string) ($section['body'] ?? $section['title'] ?? '')) . '</p>',
                'image' => '<img src="' . e((string) ($section['src'] ?? '')) . '" alt="' . e((string) ($section['alt'] ?? '')) . '" />',
                'button' => '<a href="' . e((string) ($section['href'] ?? '#')) . '">' . e((string) ($section['label'] ?? 'Open')) . '</a>',
                'verse' => '<blockquote>' . e((string) ($section['text'] ?? '')) . ' — ' . e((string) ($section['reference'] ?? '')) . '</blockquote>',
                'unsubscribe' => '<p><a href="/unsubscribe">' . e((string) ($section['label'] ?? 'Unsubscribe')) . '</a></p>',
                default => '<p>' . e((string) ($section['title'] ?? $section['body'] ?? $type)) . '</p>',
            };
        }
        $parts[] = '</article>';

        return implode("\n", $parts);
    }

    /**
     * @return Collection<int, Member>
     */
    private function resolveAudience(User $actor, Newsletter $newsletter): Collection
    {
        $params = $newsletter->audience_params ?? [];
        $query = Member::query()->whereNull('archived_at')->whereNull('merged_into_id');

        return match ($newsletter->audience_type) {
            'members' => $query->whereIn('id', array_map('intval', $params['member_ids'] ?? []))->limit(2000)->get(),
            default => $query->where('branch_id', (int) ($params['branch_id'] ?? $newsletter->branch_id))->limit(2000)->get(),
        };
    }

    private function skipDelivery(Newsletter $newsletter, NewsletterVersion $version, Member $member, string $reason): void
    {
        NewsletterDelivery::query()->updateOrCreate(
            [
                'newsletter_id' => $newsletter->id,
                'member_id' => $member->id,
                'newsletter_version_id' => $version->id,
                'is_test' => false,
            ],
            [
                'channel' => 'email',
                'status' => NewsletterDelivery::STATUS_SKIPPED,
                'skip_reason' => $reason,
            ],
        );
    }

    private function isSuppressed(int $memberId): bool
    {
        return CommunicationSuppression::query()
            ->where('member_id', $memberId)
            ->where('active', true)
            ->where(function (Builder $q): void {
                $q->whereNull('channel')->orWhere('channel', 'email');
            })
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordEvent(
        Newsletter $newsletter,
        ?NewsletterDelivery $delivery,
        string $type,
        array $payload = [],
        string $provider = 'simulated',
        ?Carbon $at = null,
    ): NewsletterEvent {
        return NewsletterEvent::create([
            'newsletter_id' => $newsletter->id,
            'newsletter_delivery_id' => $delivery?->id,
            'event_type' => $type,
            'provider' => $provider,
            'payload' => $payload,
            'occurred_at' => $at ?? now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload, bool $requireName = true, bool $allowPartial = false): array
    {
        return validator($payload, [
            'name' => [$requireName ? 'required' : 'nullable', 'string', 'min:3', 'max:160'],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'subject' => [$allowPartial ? 'nullable' : 'required', 'string', 'min:3', 'max:200'],
            'preview_text' => ['nullable', 'string', 'max:250'],
            'sections' => [$allowPartial ? 'nullable' : 'required', 'array', 'min:1'],
            'audience_type' => ['nullable', 'string', 'in:branch,members'],
            'audience_params' => ['nullable', 'array'],
            'audience_params.branch_id' => ['nullable', 'integer'],
            'audience_params.member_ids' => ['nullable', 'array'],
            'audience_params.member_ids.*' => ['integer', 'exists:members,id'],
            'scheduled_at' => ['nullable', 'date'],
        ])->validate();
    }

    private function draftVersion(Newsletter $newsletter): NewsletterVersion
    {
        $latest = NewsletterVersion::query()
            ->where('newsletter_id', $newsletter->id)
            ->orderByDesc('version')
            ->first();

        if ($latest === null) {
            throw new NewsletterException('Newsletter has no versions.', 'missing_version', 422);
        }

        return $latest;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(User $actor, string $action, Newsletter $newsletter, array $after = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'communications',
            branchId: $newsletter->branch_id,
            subjectType: Newsletter::class,
            subjectId: $newsletter->id,
            after: array_merge([
                'reference' => $newsletter->reference,
                'status' => $newsletter->status,
            ], $after),
        );
    }

    private function assertInScope(User $actor, Newsletter $newsletter): void
    {
        if ($newsletter->branch_id === null) {
            return;
        }
        if (! $this->isInBranchScope($actor, (int) $newsletter->branch_id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function applyBranchScope(Builder $query, User $actor): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            $scope = BranchScope::for($actor);
            $query->where(function (Builder $inner) use ($scope): void {
                $inner->whereNull('branch_id')
                    ->orWhereIn('branch_id', $scope->subtreeIds((int) $scope->branchId()));
            });
        } catch (BranchScopeException) {
            $query->whereRaw('1 = 0');
        }
    }

    private function assertBranchWritable(User $actor, int $branchId): void
    {
        if (! $this->isInBranchScope($actor, $branchId)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function isInBranchScope(User $actor, int $branchId): bool
    {
        if ($actor->isChurchWide()) {
            return true;
        }

        try {
            return BranchScope::for($actor)->includes($branchId);
        } catch (BranchScopeException) {
            return false;
        }
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }
}
