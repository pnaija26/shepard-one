<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\MessageTemplate;
use App\Models\MessageTemplatePreview;
use App\Models\MessageTemplateVersion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Story 10.3: versioned email/SMS templates with safe variables.
 */
class MessageTemplateService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, MessageTemplate>
     */
    public function list(User $actor): Collection
    {
        $this->assertCan($actor, 'message_templates.read');

        $query = MessageTemplate::query()
            ->with(['branch:id,name'])
            ->orderBy('name');
        $this->applyBranchScope($query, $actor);

        return $query->limit(100)->get();
    }

    public function show(User $actor, MessageTemplate $template): MessageTemplate
    {
        $this->assertCan($actor, 'message_templates.read');
        $this->assertInScope($actor, $template);

        return $template->load([
            'branch:id,name',
            'versions' => fn ($q) => $q->orderByDesc('version'),
            'versions.previews' => fn ($q) => $q->orderByDesc('ran_at')->limit(5),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): MessageTemplate
    {
        $this->assertCan($actor, 'message_templates.manage');

        $validated = $this->validatePayload($payload);
        if (! empty($validated['branch_id'])) {
            $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        }

        $content = $this->extractContent($validated);
        $validation = $this->validateContent(
            $validated['scenario'],
            $validated['channel'],
            $content['subject'],
            $content['body'],
        );
        if (! $validation['valid']) {
            throw new MessageTemplateException('Template content is invalid.', 'invalid_content', 422, $validation);
        }

        return DB::transaction(function () use ($actor, $validated, $content, $validation): MessageTemplate {
            $template = MessageTemplate::create([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']) . '-' . Str::lower(Str::random(4)),
                'scenario' => $validated['scenario'],
                'channel' => $validated['channel'],
                'language' => $validated['language'] ?? 'en',
                'branch_id' => $validated['branch_id'] ?? null,
                'status' => MessageTemplate::STATUS_DRAFT,
                'current_version' => 0,
                'description' => $validated['description'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            MessageTemplateVersion::create([
                'message_template_id' => $template->id,
                'version' => 1,
                'status' => MessageTemplateVersion::STATUS_DRAFT,
                'subject' => $content['subject'],
                'body' => $content['body'],
                'variables_used' => $validation['variables_used'],
                'last_validation' => $validation,
                'created_by' => $actor->id,
            ]);

            $this->audit($actor, 'message_template.created', $template, ['version' => 1]);

            return $template->fresh(['versions']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(User $actor, MessageTemplate $template, array $payload): MessageTemplate
    {
        $this->assertCan($actor, 'message_templates.manage');
        $this->assertInScope($actor, $template);

        if ($template->status === MessageTemplate::STATUS_RETIRED) {
            throw new MessageTemplateException('Retired templates cannot be edited.', 'retired', 422);
        }

        $validated = $this->validatePayload($payload, requireName: false, allowPartial: true);
        $draft = $this->draftVersion($template);

        $scenario = $validated['scenario'] ?? $template->scenario;
        $channel = $validated['channel'] ?? $template->channel;
        $subject = array_key_exists('subject', $validated) ? $validated['subject'] : $draft->subject;
        $body = array_key_exists('body', $validated) ? $validated['body'] : $draft->body;

        $validation = $this->validateContent($scenario, $channel, $subject, (string) $body);
        if (! $validation['valid']) {
            throw new MessageTemplateException('Template content is invalid.', 'invalid_content', 422, $validation);
        }

        return DB::transaction(function () use ($actor, $template, $draft, $validated, $scenario, $channel, $subject, $body, $validation): MessageTemplate {
            if ($draft->status === MessageTemplateVersion::STATUS_PUBLISHED) {
                $draft = MessageTemplateVersion::create([
                    'message_template_id' => $template->id,
                    'version' => $template->current_version + 1,
                    'status' => MessageTemplateVersion::STATUS_DRAFT,
                    'subject' => $subject,
                    'body' => $body,
                    'variables_used' => $validation['variables_used'],
                    'last_validation' => $validation,
                    'created_by' => $actor->id,
                ]);
            } else {
                $draft->update([
                    'subject' => $subject,
                    'body' => $body,
                    'variables_used' => $validation['variables_used'],
                    'last_validation' => $validation,
                ]);
            }

            $updates = ['updated_by' => $actor->id];
            if (! empty($validated['name'])) {
                $updates['name'] = $validated['name'];
            }
            if (array_key_exists('description', $validated)) {
                $updates['description'] = $validated['description'];
            }
            if (isset($validated['scenario'])) {
                $updates['scenario'] = $scenario;
            }
            if (isset($validated['channel'])) {
                $updates['channel'] = $channel;
            }
            if (isset($validated['language'])) {
                $updates['language'] = $validated['language'];
            }
            $template->update($updates);

            $this->audit($actor, 'message_template.draft_updated', $template, ['version' => $draft->version]);

            return $template->fresh(['versions']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(User $actor, MessageTemplate $template): array
    {
        $this->assertCan($actor, 'message_templates.manage');
        $this->assertInScope($actor, $template);

        $draft = $this->draftVersion($template);
        $validation = $this->validateContent(
            $template->scenario,
            $template->channel,
            $draft->subject,
            $draft->body,
        );
        $draft->update(['last_validation' => $validation, 'variables_used' => $validation['variables_used']]);

        return [
            'message_template_id' => $template->id,
            'version' => $draft->version,
            'validation' => $validation,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preview(User $actor, MessageTemplate $template, array $payload = []): array
    {
        $this->assertCan($actor, 'message_templates.manage');
        $this->assertInScope($actor, $template);

        $sample = $this->sanitizeSample($payload['sample'] ?? config('message_templates.sample_data', []));
        $draft = $this->draftVersion($template);
        $validation = $this->validateContent(
            $template->scenario,
            $template->channel,
            $draft->subject,
            $draft->body,
        );

        $rendered = [
            'subject' => $this->renderString((string) $draft->subject, $sample),
            'body' => $this->renderString($draft->body, $sample),
        ];

        $preview = MessageTemplatePreview::create([
            'message_template_version_id' => $draft->id,
            'sample_data' => $sample,
            'rendered' => $rendered,
            'passed' => $validation['valid'],
            'ran_by' => $actor->id,
            'ran_at' => now(),
        ]);

        $this->audit($actor, 'message_template.previewed', $template, [
            'version' => $draft->version,
            'preview_id' => $preview->id,
            'passed' => $validation['valid'],
        ]);

        return [
            'message_template_id' => $template->id,
            'version' => $draft->version,
            'preview_id' => $preview->id,
            'passed' => $validation['valid'],
            'validation' => $validation,
            'sample' => $sample,
            'rendered' => $rendered,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(User $actor, MessageTemplate $template, array $payload = []): MessageTemplate
    {
        $this->assertCan($actor, 'message_templates.publish');
        $this->assertInScope($actor, $template);

        if ($template->status === MessageTemplate::STATUS_RETIRED) {
            throw new MessageTemplateException('Retired templates cannot be published.', 'retired', 422);
        }

        $draft = $this->draftVersion($template);
        if ($draft->status === MessageTemplateVersion::STATUS_PUBLISHED) {
            throw new MessageTemplateException('There is no unpublished draft to publish.', 'nothing_to_publish', 422);
        }

        $validation = $this->validateContent(
            $template->scenario,
            $template->channel,
            $draft->subject,
            $draft->body,
        );
        if (! $validation['valid']) {
            throw new MessageTemplateException('Cannot publish an invalid template.', 'invalid_content', 422, $validation);
        }

        $effectiveFrom = isset($payload['effective_from'])
            ? Carbon::parse($payload['effective_from'])
            : now();

        return DB::transaction(function () use ($actor, $template, $draft, $validation, $effectiveFrom): MessageTemplate {
            if ($template->current_version > 0) {
                MessageTemplateVersion::query()
                    ->where('message_template_id', $template->id)
                    ->where('version', $template->current_version)
                    ->where('status', MessageTemplateVersion::STATUS_PUBLISHED)
                    ->update([
                        'status' => MessageTemplateVersion::STATUS_SUPERSEDED,
                        'effective_to' => $effectiveFrom->copy()->subSecond(),
                    ]);
            }

            $draft->update([
                'status' => MessageTemplateVersion::STATUS_PUBLISHED,
                'last_validation' => $validation,
                'variables_used' => $validation['variables_used'],
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
                'published_at' => now(),
                'published_by' => $actor->id,
            ]);

            $template->update([
                'status' => MessageTemplate::STATUS_PUBLISHED,
                'current_version' => $draft->version,
                'updated_by' => $actor->id,
                'retired_at' => null,
            ]);

            $this->audit($actor, 'message_template.published', $template, [
                'version' => $draft->version,
                'effective_from' => $effectiveFrom->toIso8601String(),
            ]);

            return $template->fresh(['versions']);
        });
    }

    public function retire(User $actor, MessageTemplate $template): MessageTemplate
    {
        $this->assertCan($actor, 'message_templates.publish');
        $this->assertInScope($actor, $template);

        if ($template->status === MessageTemplate::STATUS_RETIRED) {
            throw new MessageTemplateException('Template is already retired.', 'already_retired', 422);
        }

        return DB::transaction(function () use ($actor, $template): MessageTemplate {
            MessageTemplateVersion::query()
                ->where('message_template_id', $template->id)
                ->where('status', MessageTemplateVersion::STATUS_PUBLISHED)
                ->update([
                    'status' => MessageTemplateVersion::STATUS_SUPERSEDED,
                    'effective_to' => now(),
                ]);

            $template->update([
                'status' => MessageTemplate::STATUS_RETIRED,
                'retired_at' => now(),
                'updated_by' => $actor->id,
            ]);

            $this->audit($actor, 'message_template.retired', $template);

            return $template->fresh(['versions']);
        });
    }

    /**
     * Resolve which published/superseded version is effective at a point in time
     * for scheduled sends. Retired templates yield null for future sends.
     */
    public function resolveEffectiveVersion(MessageTemplate $template, ?\DateTimeInterface $at = null): ?MessageTemplateVersion
    {
        $at = $at ? Carbon::instance($at) : now();

        if ($template->status === MessageTemplate::STATUS_RETIRED && $template->retired_at && $at->gte($template->retired_at)) {
            // Still allow historical resolution for times before retirement via version windows.
        }

        return MessageTemplateVersion::query()
            ->where('message_template_id', $template->id)
            ->whereIn('status', [
                MessageTemplateVersion::STATUS_PUBLISHED,
                MessageTemplateVersion::STATUS_SUPERSEDED,
            ])
            ->whereNotNull('published_at')
            ->where(function (Builder $q) use ($at): void {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $at);
            })
            ->where(function (Builder $q) use ($at): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $at);
            })
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Render a version for send and return content + version id for delivery retention.
     *
     * @param  array<string, mixed>  $data
     * @return array{version_id: int, subject: ?string, body: string, channel: string}
     */
    public function renderForSend(MessageTemplate $template, array $data, ?\DateTimeInterface $at = null): array
    {
        $version = $this->resolveEffectiveVersion($template, $at);
        if ($version === null) {
            throw new MessageTemplateException('No effective template version for send.', 'no_effective_version', 422);
        }

        $sample = $this->sanitizeSample($data);

        return [
            'version_id' => $version->id,
            'subject' => $this->renderString((string) $version->subject, $sample),
            'body' => $this->renderString($version->body, $sample),
            'channel' => $template->channel,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function format(MessageTemplate $template): array
    {
        $draft = $template->relationLoaded('versions')
            ? $template->versions->sortByDesc('version')->first()
            : $this->draftVersion($template);

        return [
            'id' => $template->id,
            'name' => $template->name,
            'slug' => $template->slug,
            'scenario' => $template->scenario,
            'channel' => $template->channel,
            'language' => $template->language,
            'branch_id' => $template->branch_id,
            'branch' => $template->relationLoaded('branch') ? $template->branch : null,
            'status' => $template->status,
            'current_version' => $template->current_version,
            'draft_version' => $draft?->version,
            'draft_status' => $draft?->status,
            'subject' => $draft?->subject,
            'body' => $draft?->body,
            'variables_used' => $draft?->variables_used,
            'last_validation' => $draft?->last_validation,
            'description' => $template->description,
            'retired_at' => $template->retired_at?->toIso8601String(),
            'versions' => $template->relationLoaded('versions')
                ? $template->versions->map(fn (MessageTemplateVersion $version) => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'status' => $version->status,
                    'subject' => $version->subject,
                    'variables_used' => $version->variables_used,
                    'effective_from' => $version->effective_from?->toIso8601String(),
                    'effective_to' => $version->effective_to?->toIso8601String(),
                    'published_at' => $version->published_at?->toIso8601String(),
                    'previews' => $version->relationLoaded('previews')
                        ? $version->previews->map(fn (MessageTemplatePreview $p) => [
                            'id' => $p->id,
                            'passed' => $p->passed,
                            'ran_at' => $p->ran_at?->toIso8601String(),
                            'rendered' => $p->rendered,
                        ])->values()->all()
                        : [],
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @return array{valid: bool, errors: array<int, array<string, mixed>>, warnings: array<int, string>, variables_used: array<int, string>}
     */
    private function validateContent(string $scenario, string $channel, ?string $subject, string $body): array
    {
        $errors = [];
        $warnings = [];

        $channelConfig = config('message_templates.channels.' . $channel);
        if (! is_array($channelConfig)) {
            $errors[] = ['code' => 'unsupported_channel', 'message' => 'Channel is not supported.'];

            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings, 'variables_used' => []];
        }

        $scenarioConfig = config('message_templates.scenarios.' . $scenario);
        if (! is_array($scenarioConfig)) {
            $errors[] = ['code' => 'unsupported_scenario', 'message' => 'Scenario is not supported.'];

            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings, 'variables_used' => []];
        }

        $approved = $scenarioConfig['variables'] ?? [];
        $combined = ($subject ?? '') . "\n" . $body;
        $used = $this->extractVariables($combined);

        foreach ($used as $var) {
            if (! in_array($var, $approved, true)) {
                $errors[] = [
                    'code' => 'unknown_variable',
                    'message' => "Variable {{{$var}}} is not approved for scenario {$scenario}.",
                    'variable' => $var,
                ];
            }
        }

        foreach (config('message_templates.unsafe_markup_patterns', []) as $pattern) {
            if (preg_match($pattern, $combined)) {
                $errors[] = ['code' => 'unsafe_markup', 'message' => 'Content contains unsafe markup.'];
                break;
            }
        }

        if (empty($channelConfig['allows_html']) && preg_match('/<[^>]+>/', $body)) {
            $errors[] = ['code' => 'unsafe_markup', 'message' => 'HTML is not allowed for this channel.'];
        }

        foreach (config('message_templates.prohibited_link_patterns', []) as $pattern) {
            if (preg_match($pattern, $combined)) {
                $errors[] = ['code' => 'prohibited_link', 'message' => 'Content contains a prohibited link scheme.'];
                break;
            }
        }

        $maxSubject = (int) ($channelConfig['max_subject'] ?? 0);
        $maxBody = (int) ($channelConfig['max_body'] ?? 0);

        if ($channel === 'email') {
            if ($subject === null || trim($subject) === '') {
                $errors[] = ['code' => 'missing_subject', 'message' => 'Email templates require a subject.'];
            } elseif ($maxSubject > 0 && mb_strlen($subject) > $maxSubject) {
                $errors[] = ['code' => 'channel_length', 'message' => "Subject exceeds {$maxSubject} characters."];
            }
        }

        if ($channel === 'sms' && $subject !== null && trim($subject) !== '') {
            $warnings[] = 'SMS subject is ignored at send time.';
        }

        if ($maxBody > 0 && mb_strlen($body) > $maxBody) {
            $errors[] = ['code' => 'channel_length', 'message' => "Body exceeds {$maxBody} characters for {$channel}."];
        }

        if (trim($body) === '') {
            $errors[] = ['code' => 'missing_body', 'message' => 'Template body is required.'];
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'variables_used' => array_values($used),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractVariables(string $content): array
    {
        $pattern = config('message_templates.variable_pattern', '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/i');
        preg_match_all($pattern, $content, $matches);

        return array_values(array_unique(array_map(
            fn ($v) => Str::lower((string) $v),
            $matches[1] ?? [],
        )));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderString(string $content, array $data): string
    {
        $pattern = config('message_templates.variable_pattern', '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/i');

        return (string) preg_replace_callback($pattern, function (array $m) use ($data): string {
            $key = Str::lower($m[1]);

            return array_key_exists($key, $data) ? (string) $data[$key] : $m[0];
        }, $content);
    }

    /**
     * @param  array<string, mixed>  $sample
     * @return array<string, mixed>
     */
    private function sanitizeSample(array $sample): array
    {
        $defaults = config('message_templates.sample_data', []);
        $merged = array_merge($defaults, $sample);

        foreach (['password', 'secret', 'token', 'ssn', 'national_id', 'request_body', 'body'] as $key) {
            unset($merged[$key]);
        }

        // Only scalar string/int values for preview safety.
        $clean = [];
        foreach ($merged as $key => $value) {
            if (is_scalar($value)) {
                $clean[Str::lower((string) $key)] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload, bool $requireName = true, bool $allowPartial = false): array
    {
        $scenarios = implode(',', array_keys(config('message_templates.scenarios', [])));
        $channels = implode(',', array_keys(config('message_templates.channels', [])));
        $languages = implode(',', config('message_templates.languages', ['en']));

        $rules = [
            'name' => [$requireName ? 'required' : 'nullable', 'string', 'min:3', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scenario' => [$allowPartial ? 'nullable' : 'required', 'string', 'in:' . $scenarios],
            'channel' => [$allowPartial ? 'nullable' : 'required', 'string', 'in:' . $channels],
            'language' => ['nullable', 'string', 'in:' . $languages],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => [$allowPartial ? 'nullable' : 'required', 'string', 'max:20000'],
            'effective_from' => ['nullable', 'date'],
        ];

        return validator($payload, $rules)->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{subject: ?string, body: string}
     */
    private function extractContent(array $validated): array
    {
        return [
            'subject' => $validated['subject'] ?? null,
            'body' => (string) ($validated['body'] ?? ''),
        ];
    }

    private function draftVersion(MessageTemplate $template): MessageTemplateVersion
    {
        $latest = MessageTemplateVersion::query()
            ->where('message_template_id', $template->id)
            ->orderByDesc('version')
            ->first();

        if ($latest === null) {
            throw new MessageTemplateException('Template has no versions.', 'missing_version', 422);
        }

        return $latest;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(User $actor, string $action, MessageTemplate $template, array $after = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'communications',
            branchId: $template->branch_id,
            subjectType: MessageTemplate::class,
            subjectId: $template->id,
            after: array_merge([
                'name' => $template->name,
                'current_version' => $template->current_version,
            ], $after),
        );
    }

    private function assertInScope(User $actor, MessageTemplate $template): void
    {
        if ($template->branch_id === null) {
            return;
        }
        if (! $this->isInBranchScope($actor, (int) $template->branch_id)) {
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
