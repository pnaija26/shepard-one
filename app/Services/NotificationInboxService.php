<?php

namespace App\Services;

use App\Models\MemberNotification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Story 10.2: personal notification inbox with status controls and deep links.
 */
class NotificationInboxService
{
    public function __construct(
        private AuthorizationService $authorization,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{notifications: Collection<int, MemberNotification>, unread_count: int, total: int}
     */
    public function inbox(User $actor, array $filters = []): array
    {
        $includeArchived = (bool) ($filters['include_archived'] ?? false);
        $category = $filters['category'] ?? null;
        $unreadOnly = (bool) ($filters['unread_only'] ?? false);
        $limit = min((int) ($filters['limit'] ?? config('notifications.page_size', 50)), 100);

        $allowedCategories = array_keys(config('notifications.categories', []));

        $query = MemberNotification::query()
            ->where('user_id', $actor->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! $includeArchived) {
            $query->whereNull('archived_at');
        }

        if (is_string($category) && $category !== '') {
            if (! in_array($category, $allowedCategories, true)) {
                throw new NotificationInboxException('Unknown notification category.', 'invalid_category', 422);
            }
            $query->where(function ($q) use ($category): void {
                $q->where('category', $category)
                    ->orWhere(function ($inner) use ($category): void {
                        // Legacy rows without category — match by type mapping at query time is hard;
                        // fetch candidates later filtered in PHP when category column null.
                        $inner->whereNull('category');
                    });
            });
        }

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $rows = $query->limit($limit * 2)->get()
            ->map(fn (MemberNotification $n) => $this->hydrate($n))
            ->filter(function (MemberNotification $n) use ($allowedCategories, $category): bool {
                if (! in_array($n->category, $allowedCategories, true)) {
                    return false;
                }
                if (is_string($category) && $category !== '' && $n->category !== $category) {
                    return false;
                }

                return true;
            })
            ->take($limit)
            ->values();

        return [
            'notifications' => $rows,
            'unread_count' => $this->unreadCount($actor),
            'total' => $rows->count(),
        ];
    }

    public function summary(User $actor): array
    {
        return [
            'unread_count' => $this->unreadCount($actor),
            'categories' => config('notifications.categories', []),
        ];
    }

    public function show(User $actor, MemberNotification $notification): MemberNotification
    {
        $this->assertOwner($actor, $notification);
        $hydrated = $this->hydrate($notification);
        $allowed = array_keys(config('notifications.categories', []));
        if (! in_array($hydrated->category, $allowed, true)) {
            throw new NotificationInboxException('Notification is not available in your inbox.', 'not_permitted', 403);
        }

        return $hydrated;
    }

    public function markRead(User $actor, MemberNotification $notification): MemberNotification
    {
        $this->assertOwner($actor, $notification);
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return $this->hydrate($notification->fresh());
    }

    public function markUnread(User $actor, MemberNotification $notification): MemberNotification
    {
        $this->assertOwner($actor, $notification);
        $notification->update(['read_at' => null]);

        return $this->hydrate($notification->fresh());
    }

    public function archive(User $actor, MemberNotification $notification): MemberNotification
    {
        $this->assertOwner($actor, $notification);
        if ($notification->archived_at === null) {
            $notification->update([
                'archived_at' => now(),
                'read_at' => $notification->read_at ?? now(),
            ]);
        }

        return $this->hydrate($notification->fresh());
    }

    public function unarchive(User $actor, MemberNotification $notification): MemberNotification
    {
        $this->assertOwner($actor, $notification);
        $notification->update(['archived_at' => null]);

        return $this->hydrate($notification->fresh());
    }

    public function markAllRead(User $actor): array
    {
        $updated = MemberNotification::query()
            ->where('user_id', $actor->id)
            ->whereNull('archived_at')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return [
            'updated' => $updated,
            'unread_count' => $this->unreadCount($actor),
        ];
    }

    /**
     * Follow an approved deep link after rechecking authorization.
     *
     * @return array{deep_link: string, authorized: bool, category: string}
     */
    public function open(User $actor, MemberNotification $notification): array
    {
        $notification = $this->show($actor, $notification);
        $link = $this->resolveDeepLink($notification);

        if ($link === null) {
            throw new NotificationInboxException('No approved deep link for this notification.', 'no_deep_link', 422);
        }

        if (! $this->deepLinkApproved($link)) {
            throw new NotificationInboxException('Deep link is not on the approved list.', 'deep_link_rejected', 422);
        }

        $requiredAction = $this->requiredActionForDeepLink($link);
        $authorized = $requiredAction === null || $this->authorization->allows($actor, $requiredAction);

        if (! $authorized) {
            throw new NotificationInboxException(
                'You are not authorized to open this destination.',
                'deep_link_forbidden',
                403,
                ['deep_link' => $link, 'required_action' => $requiredAction],
            );
        }

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return [
            'deep_link' => $link,
            'authorized' => true,
            'category' => $notification->category,
            'notification_id' => $notification->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function format(MemberNotification $notification): array
    {
        $notification = $this->hydrate($notification);

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'category' => $notification->category,
            'category_label' => config('notifications.categories.' . $notification->category, $notification->category),
            'message' => $notification->message,
            'metadata' => $this->sanitizeMetadata($notification->metadata ?? []),
            'deep_link' => $this->resolveDeepLink($notification),
            'read_at' => $notification->read_at?->toIso8601String(),
            'archived_at' => $notification->archived_at?->toIso8601String(),
            'is_read' => $notification->read_at !== null,
            'is_archived' => $notification->archived_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    private function unreadCount(User $actor): int
    {
        $allowed = array_keys(config('notifications.categories', []));

        return MemberNotification::query()
            ->where('user_id', $actor->id)
            ->whereNull('archived_at')
            ->whereNull('read_at')
            ->get()
            ->map(fn (MemberNotification $n) => $this->hydrate($n))
            ->filter(fn (MemberNotification $n) => in_array($n->category, $allowed, true))
            ->count();
    }

    private function hydrate(MemberNotification $notification): MemberNotification
    {
        if ($notification->category === null || $notification->category === '') {
            $notification->category = $this->categoryForType((string) $notification->type);
        }

        if (($notification->deep_link === null || $notification->deep_link === '')
            && is_array($notification->metadata)
            && ! empty($notification->metadata['deep_link'])) {
            $notification->deep_link = (string) $notification->metadata['deep_link'];
        }

        return $notification;
    }

    private function categoryForType(string $type): ?string
    {
        $map = config('notifications.type_categories', []);
        foreach ($map as $prefix => $category) {
            if ($type === $prefix || str_starts_with($type, (string) $prefix)) {
                return $category;
            }
        }

        return null;
    }

    private function resolveDeepLink(MemberNotification $notification): ?string
    {
        if (is_string($notification->deep_link) && $notification->deep_link !== '') {
            return $this->normalizeDeepLink($notification->deep_link);
        }

        $meta = $notification->metadata ?? [];
        if (! empty($meta['deep_link']) && is_string($meta['deep_link'])) {
            return $this->normalizeDeepLink($meta['deep_link']);
        }

        $defaults = config('notifications.default_deep_links', []);
        foreach ($defaults as $prefix => $link) {
            if (str_starts_with((string) $notification->type, (string) $prefix)) {
                return $this->normalizeDeepLink((string) $link);
            }
        }

        return null;
    }

    private function normalizeDeepLink(string $link): string
    {
        $link = trim($link);
        if ($link === '') {
            return $link;
        }

        // Only allow app-relative paths — never external URLs.
        if (Str::startsWith($link, ['http://', 'https://', '//'])) {
            return '';
        }

        if (! str_starts_with($link, '/')) {
            $link = '/' . $link;
        }

        return strtok($link, '?') ?: $link;
    }

    private function deepLinkApproved(string $link): bool
    {
        if ($link === '') {
            return false;
        }

        $approved = config('notifications.deep_links', []);
        foreach (array_keys($approved) as $path) {
            if ($link === $path || str_starts_with($link, rtrim((string) $path, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    private function requiredActionForDeepLink(string $link): ?string
    {
        $approved = config('notifications.deep_links', []);
        $best = null;
        $bestLen = -1;
        foreach ($approved as $path => $action) {
            $path = (string) $path;
            if ($link === $path || str_starts_with($link, rtrim($path, '/') . '/')) {
                if (strlen($path) > $bestLen) {
                    $best = $action;
                    $bestLen = strlen($path);
                }
            }
        }

        return is_string($best) ? $best : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $clean = $metadata;
        foreach (['body', 'request_body', 'password', 'secret', 'token', 'national_id', 'ssn'] as $key) {
            unset($clean[$key]);
        }

        return $clean;
    }

    private function assertOwner(User $actor, MemberNotification $notification): void
    {
        if ((int) $notification->user_id !== (int) $actor->id) {
            throw new NotificationInboxException('Notification not found.', 'not_found', 404);
        }
    }
}
