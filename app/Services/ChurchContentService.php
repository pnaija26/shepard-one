<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ChurchContent;
use App\Models\ChurchContentPreview;
use App\Models\ChurchContentVersion;
use App\Models\Member;
use App\Models\RoleAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Story 10.7: publish and surface approved church content within publish windows.
 */
class ChurchContentService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, ChurchContent>
     */
    public function listAdmin(User $actor): Collection
    {
        $this->assertCan($actor, 'church_content.read');

        $query = ChurchContent::query()->with(['branch:id,name'])->orderByDesc('id');
        $this->applyBranchScope($query, $actor);

        return $query->limit(100)->get();
    }

    public function show(User $actor, ChurchContent $content): ChurchContent
    {
        $this->assertCan($actor, 'church_content.read');
        $this->assertInScope($actor, $content);

        return $content->load([
            'branch:id,name',
            'versions' => fn ($q) => $q->orderByDesc('version'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): ChurchContent
    {
        $this->assertCan($actor, 'church_content.manage');

        $validated = $this->validatePayload($payload);
        if (! empty($validated['branch_id'])) {
            $this->assertBranchWritable($actor, (int) $validated['branch_id']);
        }

        $validation = $this->validateContentPayload(
            $validated['title'],
            $validated['body'] ?? null,
            $validated['media'] ?? [],
            $validated['links'] ?? [],
        );
        if (! $validation['valid']) {
            throw new ChurchContentException('Content is invalid.', 'invalid_content', 422, $validation);
        }

        return DB::transaction(function () use ($actor, $validated, $validation): ChurchContent {
            $content = ChurchContent::create([
                'reference' => 'CC-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
                'content_type' => $validated['content_type'],
                'title' => $validated['title'],
                'branch_id' => $validated['branch_id'] ?? null,
                'status' => ChurchContent::STATUS_DRAFT,
                'current_version' => 0,
                'visibility' => $validated['visibility'] ?? 'members',
                'audience_type' => $validated['audience_type'] ?? 'all',
                'audience_params' => $validated['audience_params'] ?? [],
                'device_target' => $validated['device_target'] ?? 'all',
                'publish_from' => isset($validated['publish_from']) ? Carbon::parse($validated['publish_from']) : null,
                'publish_to' => isset($validated['publish_to']) ? Carbon::parse($validated['publish_to']) : null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            ChurchContentVersion::create([
                'church_content_id' => $content->id,
                'version' => 1,
                'status' => ChurchContentVersion::STATUS_DRAFT,
                'title' => $validated['title'],
                'body' => $validated['body'] ?? null,
                'media' => $validated['media'] ?? [],
                'links' => $validated['links'] ?? [],
                'last_validation' => $validation,
                'created_by' => $actor->id,
            ]);

            $this->audit($actor, 'church_content.created', $content, ['version' => 1]);

            return $content->fresh(['versions']);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateDraft(User $actor, ChurchContent $content, array $payload): ChurchContent
    {
        $this->assertCan($actor, 'church_content.manage');
        $this->assertInScope($actor, $content);

        if (in_array($content->status, [ChurchContent::STATUS_WITHDRAWN], true)) {
            throw new ChurchContentException('Withdrawn content cannot be edited.', 'not_editable', 422);
        }

        $validated = $this->validatePayload($payload, allowPartial: true);
        $draft = $this->draftVersion($content);

        $title = array_key_exists('title', $validated) ? $validated['title'] : $draft->title;
        $body = array_key_exists('body', $validated) ? $validated['body'] : $draft->body;
        $media = array_key_exists('media', $validated) ? ($validated['media'] ?? []) : ($draft->media ?? []);
        $links = array_key_exists('links', $validated) ? ($validated['links'] ?? []) : ($draft->links ?? []);

        $validation = $this->validateContentPayload($title, $body, $media, $links);
        if (! $validation['valid']) {
            throw new ChurchContentException('Content is invalid.', 'invalid_content', 422, $validation);
        }

        $wasApproved = in_array($content->status, [
            ChurchContent::STATUS_APPROVED,
            ChurchContent::STATUS_PUBLISHED,
            ChurchContent::STATUS_PENDING_APPROVAL,
        ], true) || $draft->status === ChurchContentVersion::STATUS_APPROVED;

        return DB::transaction(function () use ($actor, $content, $draft, $validated, $title, $body, $media, $links, $validation, $wasApproved): ChurchContent {
            if ($draft->status === ChurchContentVersion::STATUS_APPROVED) {
                $draft = ChurchContentVersion::create([
                    'church_content_id' => $content->id,
                    'version' => max($content->current_version, $content->approved_version ?? 0) + 1,
                    'status' => ChurchContentVersion::STATUS_DRAFT,
                    'title' => $title,
                    'body' => $body,
                    'media' => $media,
                    'links' => $links,
                    'last_validation' => $validation,
                    'created_by' => $actor->id,
                ]);
            } else {
                $draft->update([
                    'title' => $title,
                    'body' => $body,
                    'media' => $media,
                    'links' => $links,
                    'last_validation' => $validation,
                ]);
            }

            $updates = [
                'title' => $title,
                'updated_by' => $actor->id,
                'status' => ChurchContent::STATUS_DRAFT,
                'approved_by' => null,
                'approved_at' => null,
                'published_at' => $wasApproved ? null : $content->published_at,
            ];
            foreach (['content_type', 'visibility', 'audience_type', 'audience_params', 'device_target'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updates[$field] = $validated[$field];
                }
            }
            if (array_key_exists('publish_from', $validated)) {
                $updates['publish_from'] = $validated['publish_from'] ? Carbon::parse($validated['publish_from']) : null;
            }
            if (array_key_exists('publish_to', $validated)) {
                $updates['publish_to'] = $validated['publish_to'] ? Carbon::parse($validated['publish_to']) : null;
            }

            $content->update($updates);

            $this->audit($actor, 'church_content.draft_updated', $content, [
                'version' => $draft->version,
                'requires_renewed_approval' => $wasApproved,
            ]);

            return $content->fresh(['versions']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(User $actor, ChurchContent $content): array
    {
        $this->assertCan($actor, 'church_content.manage');
        $this->assertInScope($actor, $content);

        $draft = $this->draftVersion($content);
        $validation = $this->validateContentPayload(
            $draft->title,
            $draft->body,
            $draft->media ?? [],
            $draft->links ?? [],
        );
        $draft->update(['last_validation' => $validation]);

        return [
            'church_content_id' => $content->id,
            'version' => $draft->version,
            'validation' => $validation,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preview(User $actor, ChurchContent $content, array $payload = []): array
    {
        $this->assertCan($actor, 'church_content.manage');
        $this->assertInScope($actor, $content);

        $devices = $payload['devices'] ?? config('church_content.devices', ['web']);
        $draft = $this->draftVersion($content);
        $validation = $this->validateContentPayload(
            $draft->title,
            $draft->body,
            $draft->media ?? [],
            $draft->links ?? [],
        );

        $results = [];
        foreach ($devices as $device) {
            if (! in_array($device, config('church_content.devices', []), true)) {
                continue;
            }
            $result = [
                'device' => $device,
                'title' => $draft->title,
                'body_excerpt' => Str::limit(strip_tags((string) $draft->body), 280),
                'media_count' => count($draft->media ?? []),
                'validation' => $validation,
            ];
            $preview = ChurchContentPreview::create([
                'church_content_version_id' => $draft->id,
                'device' => $device,
                'result' => $result,
                'passed' => $validation['valid'],
                'ran_by' => $actor->id,
                'ran_at' => now(),
            ]);
            $results[] = array_merge($result, ['preview_id' => $preview->id]);
        }

        $this->audit($actor, 'church_content.previewed', $content, [
            'version' => $draft->version,
            'devices' => array_column($results, 'device'),
        ]);

        return [
            'church_content_id' => $content->id,
            'version' => $draft->version,
            'passed' => $validation['valid'],
            'validation' => $validation,
            'previews' => $results,
        ];
    }

    public function submitForApproval(User $actor, ChurchContent $content): ChurchContent
    {
        $this->assertCan($actor, 'church_content.manage');
        $this->assertInScope($actor, $content);

        $draft = $this->draftVersion($content);
        $validation = $this->validateContentPayload(
            $draft->title,
            $draft->body,
            $draft->media ?? [],
            $draft->links ?? [],
        );
        if (! $validation['valid']) {
            throw new ChurchContentException('Cannot submit invalid content.', 'invalid_content', 422, $validation);
        }

        $draft->update(['last_validation' => $validation]);
        $content->update([
            'status' => ChurchContent::STATUS_PENDING_APPROVAL,
            'updated_by' => $actor->id,
        ]);

        $this->audit($actor, 'church_content.submitted', $content, ['version' => $draft->version]);

        return $content->fresh(['versions']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function approve(User $actor, ChurchContent $content, array $payload = []): ChurchContent
    {
        $this->assertCan($actor, 'church_content.approve');
        $this->assertInScope($actor, $content);

        if (! in_array($content->status, [ChurchContent::STATUS_PENDING_APPROVAL, ChurchContent::STATUS_DRAFT], true)) {
            throw new ChurchContentException('Content is not awaiting approval.', 'not_pending', 422);
        }

        $draft = $this->draftVersion($content);
        $validation = $this->validateContentPayload(
            $draft->title,
            $draft->body,
            $draft->media ?? [],
            $draft->links ?? [],
        );
        if (! $validation['valid']) {
            throw new ChurchContentException('Cannot approve invalid content.', 'invalid_content', 422, $validation);
        }

        $publishNow = (bool) ($payload['publish_now'] ?? true);

        return DB::transaction(function () use ($actor, $content, $draft, $validation, $publishNow): ChurchContent {
            if ($content->approved_version) {
                ChurchContentVersion::query()
                    ->where('church_content_id', $content->id)
                    ->where('version', $content->approved_version)
                    ->where('status', ChurchContentVersion::STATUS_APPROVED)
                    ->update(['status' => ChurchContentVersion::STATUS_SUPERSEDED]);
            }

            $draft->update([
                'status' => ChurchContentVersion::STATUS_APPROVED,
                'last_validation' => $validation,
                'approved_at' => now(),
                'approved_by' => $actor->id,
            ]);

            $withinWindow = $content->isWithinPublishWindow();
            $status = ChurchContent::STATUS_APPROVED;
            $publishedAt = null;
            if ($publishNow && $withinWindow) {
                $status = ChurchContent::STATUS_PUBLISHED;
                $publishedAt = now();
            } elseif ($publishNow && ! $withinWindow && $content->publish_from && $content->publish_from->isFuture()) {
                $status = ChurchContent::STATUS_APPROVED;
            }

            $content->update([
                'status' => $status,
                'title' => $draft->title,
                'current_version' => $draft->version,
                'approved_version' => $draft->version,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'published_at' => $publishedAt,
                'updated_by' => $actor->id,
            ]);

            $this->audit($actor, 'church_content.approved', $content, [
                'version' => $draft->version,
                'status' => $status,
            ]);

            return $content->fresh(['versions']);
        });
    }

    public function withdraw(User $actor, ChurchContent $content, ?string $reason = null): ChurchContent
    {
        $this->assertCan($actor, 'church_content.approve');
        $this->assertInScope($actor, $content);

        if (! in_array($content->status, [ChurchContent::STATUS_PUBLISHED, ChurchContent::STATUS_APPROVED], true)) {
            throw new ChurchContentException('Only approved or published content can be withdrawn.', 'not_withdrawable', 422);
        }

        $content->update([
            'status' => ChurchContent::STATUS_WITHDRAWN,
            'withdrawn_at' => now(),
            'updated_by' => $actor->id,
        ]);

        $this->audit($actor, 'church_content.withdrawn', $content, [
            'reason' => $reason,
        ]);

        return $content->fresh(['versions']);
    }

    /**
     * Promote approved items whose publish window has started; expire past publish_to.
     *
     * @return array{published: int, expired: int}
     */
    public function processWindows(User $actor, ?int $branchId = null): array
    {
        $this->assertCan($actor, 'church_content.manage');

        $counts = ['published' => 0, 'expired' => 0];

        $approved = ChurchContent::query()
            ->where('status', ChurchContent::STATUS_APPROVED)
            ->where(function (Builder $q): void {
                $q->whereNull('publish_from')->orWhere('publish_from', '<=', now());
            })
            ->where(function (Builder $q): void {
                $q->whereNull('publish_to')->orWhere('publish_to', '>=', now());
            });
        if ($branchId !== null) {
            $approved->where('branch_id', $branchId);
        } else {
            $this->applyBranchScope($approved, $actor);
        }
        foreach ($approved->limit(50)->get() as $item) {
            $item->update([
                'status' => ChurchContent::STATUS_PUBLISHED,
                'published_at' => $item->published_at ?? now(),
            ]);
            $counts['published']++;
        }

        $toExpire = ChurchContent::query()
            ->whereIn('status', [ChurchContent::STATUS_PUBLISHED, ChurchContent::STATUS_APPROVED])
            ->whereNotNull('publish_to')
            ->where('publish_to', '<', now());
        if ($branchId !== null) {
            $toExpire->where('branch_id', $branchId);
        } else {
            $this->applyBranchScope($toExpire, $actor);
        }
        foreach ($toExpire->limit(50)->get() as $item) {
            $item->update(['status' => ChurchContent::STATUS_EXPIRED]);
            $counts['expired']++;
        }

        return $counts;
    }

    /**
     * Eligible published feed for the current user.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ChurchContent>
     */
    public function feed(User $actor, array $filters = []): Collection
    {
        $this->assertCan($actor, 'church_content.view');

        $device = $filters['device'] ?? 'web';
        $query = ChurchContent::query()
            ->where('status', ChurchContent::STATUS_PUBLISHED)
            ->where(function (Builder $q): void {
                $q->whereNull('publish_from')->orWhere('publish_from', '<=', now());
            })
            ->where(function (Builder $q): void {
                $q->whereNull('publish_to')->orWhere('publish_to', '>=', now());
            })
            ->with(['branch:id,name', 'versions' => fn ($q) => $q->where('status', ChurchContentVersion::STATUS_APPROVED)->orderByDesc('version')->limit(1)])
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        $this->applyBranchScope($query, $actor);
        $this->applyAudienceVisibility($query, $actor, $device);

        if (! empty($filters['content_type'])) {
            $query->where('content_type', $filters['content_type']);
        }

        return $query->limit(50)->get()->filter(fn (ChurchContent $c) => $this->actorMayConsume($actor, $c, $device))->values();
    }

    /**
     * Search omits expired, withdrawn, and unauthorized content.
     *
     * @return Collection<int, ChurchContent>
     */
    public function search(User $actor, string $term, array $filters = []): Collection
    {
        $this->assertCan($actor, 'church_content.view');

        $term = trim($term);
        if (mb_strlen($term) < 2) {
            throw new ChurchContentException('Search query must be at least 2 characters.', 'invalid_query', 422);
        }

        $device = $filters['device'] ?? 'web';
        $max = (int) config('church_content.search.max_results', 50);

        $query = ChurchContent::query()
            ->where('status', ChurchContent::STATUS_PUBLISHED)
            ->where(function (Builder $q): void {
                $q->whereNull('publish_from')->orWhere('publish_from', '<=', now());
            })
            ->where(function (Builder $q): void {
                $q->whereNull('publish_to')->orWhere('publish_to', '>=', now());
            })
            ->where(function (Builder $q) use ($term): void {
                $q->where('title', 'like', '%' . $term . '%')
                    ->orWhereHas('versions', function (Builder $v) use ($term): void {
                        $v->where('status', ChurchContentVersion::STATUS_APPROVED)
                            ->where('body', 'like', '%' . $term . '%');
                    });
            })
            ->with(['branch:id,name'])
            ->orderByDesc('published_at')
            ->limit($max);

        $this->applyBranchScope($query, $actor);
        $this->applyAudienceVisibility($query, $actor, $device);

        return $query->get()->filter(fn (ChurchContent $c) => $this->actorMayConsume($actor, $c, $device))->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function format(ChurchContent $content, bool $includeDraftBody = true): array
    {
        $version = null;
        if ($content->relationLoaded('versions')) {
            $version = $includeDraftBody
                ? $content->versions->sortByDesc('version')->first()
                : $content->versions->firstWhere('status', ChurchContentVersion::STATUS_APPROVED)
                    ?? $content->versions->sortByDesc('version')->first();
        } elseif ($content->approved_version) {
            $version = ChurchContentVersion::query()
                ->where('church_content_id', $content->id)
                ->where('version', $content->approved_version)
                ->first();
        } else {
            $version = $this->draftVersion($content);
        }

        return [
            'id' => $content->id,
            'reference' => $content->reference,
            'content_type' => $content->content_type,
            'title' => $version?->title ?? $content->title,
            'body' => $version?->body,
            'media' => $version?->media ?? [],
            'links' => $version?->links ?? [],
            'branch_id' => $content->branch_id,
            'branch' => $content->relationLoaded('branch') ? $content->branch : null,
            'status' => $content->status,
            'current_version' => $content->current_version,
            'approved_version' => $content->approved_version,
            'draft_version' => $version?->version,
            'visibility' => $content->visibility,
            'audience_type' => $content->audience_type,
            'audience_params' => $content->audience_params,
            'device_target' => $content->device_target,
            'publish_from' => $content->publish_from?->toIso8601String(),
            'publish_to' => $content->publish_to?->toIso8601String(),
            'published_at' => $content->published_at?->toIso8601String(),
            'withdrawn_at' => $content->withdrawn_at?->toIso8601String(),
            'last_validation' => $version?->last_validation,
            'versions' => $content->relationLoaded('versions')
                ? $content->versions->map(fn (ChurchContentVersion $v) => [
                    'id' => $v->id,
                    'version' => $v->version,
                    'status' => $v->status,
                    'title' => $v->title,
                    'approved_at' => $v->approved_at?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $media
     * @param  array<int, array<string, mixed>>  $links
     * @return array{valid: bool, errors: array<int, array<string, mixed>>}
     */
    private function validateContentPayload(?string $title, ?string $body, array $media, array $links): array
    {
        $errors = [];

        if ($title === null || trim($title) === '') {
            $errors[] = ['code' => 'missing_title', 'message' => 'Title is required.'];
        }

        $blob = ($body ?? '') . json_encode($media) . json_encode($links);
        foreach (config('church_content.unsafe_markup_patterns', []) as $pattern) {
            if (preg_match($pattern, $blob)) {
                $errors[] = ['code' => 'unsafe_markup', 'message' => 'Content contains unsafe markup.'];
                break;
            }
        }

        $allowedMime = config('church_content.media.allowed_mime', []);
        $maxBytes = (int) config('church_content.media.max_bytes', 0);
        $requiresAlt = config('church_content.media.requires_alt_for', []);

        foreach ($media as $index => $item) {
            $mime = (string) ($item['mime'] ?? '');
            $size = (int) ($item['size_bytes'] ?? 0);
            if ($mime === '' || ! in_array($mime, $allowedMime, true)) {
                $errors[] = ['code' => 'unsafe_upload', 'message' => "Media {$index} has an unsupported type.", 'index' => $index];
            }
            if ($maxBytes > 0 && $size > $maxBytes) {
                $errors[] = ['code' => 'unsafe_upload', 'message' => "Media {$index} exceeds size limit.", 'index' => $index];
            }
            if (in_array($mime, $requiresAlt, true) && empty($item['alt'])) {
                $errors[] = ['code' => 'missing_accessibility', 'message' => "Media {$index} requires alt text.", 'index' => $index];
            }
            if (str_starts_with($mime, 'application/') && empty($item['label']) && empty($item['alt'])) {
                $errors[] = ['code' => 'missing_accessibility', 'message' => "Download media {$index} requires a label.", 'index' => $index];
            }
        }

        foreach ($links as $index => $link) {
            $href = (string) ($link['href'] ?? '');
            if ($href === '') {
                $errors[] = ['code' => 'invalid_link', 'message' => "Link {$index} is missing href.", 'index' => $index];
                continue;
            }
            foreach (config('church_content.prohibited_link_patterns', []) as $pattern) {
                if (preg_match($pattern, $href)) {
                    $errors[] = ['code' => 'invalid_link', 'message' => "Link {$index} is not allowed.", 'index' => $index];
                    break;
                }
            }
            if (! preg_match('#^(https?://|/|\#)#i', $href)) {
                $errors[] = ['code' => 'invalid_link', 'message' => "Link {$index} must be http(s) or a relative path.", 'index' => $index];
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload, bool $allowPartial = false): array
    {
        return validator($payload, [
            'content_type' => [$allowPartial ? 'nullable' : 'required', 'string', 'in:' . implode(',', config('church_content.content_types', []))],
            'title' => [$allowPartial ? 'nullable' : 'required', 'string', 'min:3', 'max:200'],
            'body' => ['nullable', 'string', 'max:50000'],
            'branch_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'visibility' => ['nullable', 'string', 'in:' . implode(',', config('church_content.visibility', []))],
            'audience_type' => ['nullable', 'string', 'in:' . implode(',', config('church_content.audiences', []))],
            'audience_params' => ['nullable', 'array'],
            'audience_params.branch_id' => ['nullable', 'integer'],
            'audience_params.role_id' => ['nullable', 'integer'],
            'audience_params.member_ids' => ['nullable', 'array'],
            'audience_params.member_ids.*' => ['integer'],
            'device_target' => ['nullable', 'string', 'in:' . implode(',', config('church_content.devices', []))],
            'publish_from' => ['nullable', 'date'],
            'publish_to' => ['nullable', 'date', 'after_or_equal:publish_from'],
            'media' => ['nullable', 'array', 'max:20'],
            'media.*.name' => ['required_with:media', 'string', 'max:180'],
            'media.*.mime' => ['required_with:media', 'string', 'max:120'],
            'media.*.size_bytes' => ['required_with:media', 'integer', 'min:1'],
            'media.*.url' => ['nullable', 'string', 'max:500'],
            'media.*.alt' => ['nullable', 'string', 'max:250'],
            'media.*.label' => ['nullable', 'string', 'max:250'],
            'links' => ['nullable', 'array', 'max:20'],
            'links.*.label' => ['nullable', 'string', 'max:120'],
            'links.*.href' => ['required_with:links', 'string', 'max:500'],
        ])->validate();
    }

    private function applyAudienceVisibility(Builder $query, User $actor, string $device): void
    {
        $query->where(function (Builder $q) use ($device): void {
            $q->where('device_target', 'all')->orWhere('device_target', $device);
        });

        $query->where(function (Builder $q) use ($actor): void {
            $q->where('visibility', 'public')
                ->orWhere('visibility', 'members')
                ->orWhere(function (Builder $inner) use ($actor): void {
                    $inner->where('visibility', 'branch')
                        ->where(function (Builder $b) use ($actor): void {
                            if ($actor->branch_id) {
                                $b->where('branch_id', $actor->branch_id)->orWhereNull('branch_id');
                            } else {
                                $b->whereRaw('1 = 1');
                            }
                        });
                })
                ->orWhere('visibility', 'role');
        });
    }

    private function actorMayConsume(User $actor, ChurchContent $content, string $device): bool
    {
        if (! in_array($content->device_target, ['all', $device], true)) {
            return false;
        }

        if ($content->visibility === 'public' || $content->visibility === 'members') {
            // members visibility still requires authenticated user (already true)
        } elseif ($content->visibility === 'branch') {
            if ($content->branch_id && $actor->branch_id && (int) $content->branch_id !== (int) $actor->branch_id && ! $actor->isChurchWide()) {
                if (! $this->isInBranchScope($actor, (int) $content->branch_id)) {
                    return false;
                }
            }
        } elseif ($content->visibility === 'role') {
            $roleId = (int) ($content->audience_params['role_id'] ?? 0);
            if ($roleId > 0 && ! RoleAssignment::query()->where('user_id', $actor->id)->where('role_id', $roleId)->exists()) {
                return false;
            }
        }

        return match ($content->audience_type) {
            'all' => true,
            'branch' => $this->matchesBranchAudience($actor, $content),
            'members' => $this->matchesMemberAudience($actor, $content),
            'role' => $this->matchesRoleAudience($actor, $content),
            default => false,
        };
    }

    private function matchesBranchAudience(User $actor, ChurchContent $content): bool
    {
        $branchId = (int) ($content->audience_params['branch_id'] ?? $content->branch_id ?? 0);
        if ($branchId === 0) {
            return true;
        }
        if ($actor->isChurchWide()) {
            return true;
        }

        return $this->isInBranchScope($actor, $branchId);
    }

    private function matchesMemberAudience(User $actor, ChurchContent $content): bool
    {
        $ids = array_map('intval', $content->audience_params['member_ids'] ?? []);
        if ($ids === []) {
            return true;
        }
        $member = Member::query()->where('user_id', $actor->id)->first();

        return $member !== null && in_array((int) $member->id, $ids, true);
    }

    private function matchesRoleAudience(User $actor, ChurchContent $content): bool
    {
        $roleId = (int) ($content->audience_params['role_id'] ?? 0);
        if ($roleId === 0) {
            return true;
        }

        return RoleAssignment::query()->where('user_id', $actor->id)->where('role_id', $roleId)->exists();
    }

    private function draftVersion(ChurchContent $content): ChurchContentVersion
    {
        $latest = ChurchContentVersion::query()
            ->where('church_content_id', $content->id)
            ->orderByDesc('version')
            ->first();

        if ($latest === null) {
            throw new ChurchContentException('Content has no versions.', 'missing_version', 422);
        }

        return $latest;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(User $actor, string $action, ChurchContent $content, array $after = []): void
    {
        $this->audit->record(
            actor: $actor,
            action: $action,
            category: AuditEvent::CATEGORY_BUSINESS,
            module: 'communications',
            branchId: $content->branch_id,
            subjectType: ChurchContent::class,
            subjectId: $content->id,
            after: array_merge([
                'reference' => $content->reference,
                'status' => $content->status,
            ], $after),
        );
    }

    private function assertInScope(User $actor, ChurchContent $content): void
    {
        if ($content->branch_id === null) {
            return;
        }
        if (! $this->isInBranchScope($actor, (int) $content->branch_id)) {
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
