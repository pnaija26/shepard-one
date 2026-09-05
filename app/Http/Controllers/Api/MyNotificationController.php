<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberNotification;
use App\Services\NotificationInboxException;
use App\Services\NotificationInboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story 10.2: personal notification inbox.
 */
class MyNotificationController extends Controller
{
    public function __construct(
        private NotificationInboxService $inbox,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $result = $this->inbox->inbox($request->user(), $request->query());

            return response()->json([
                'data' => $result['notifications']->map(fn (MemberNotification $n) => $this->inbox->format($n))->values(),
                'meta' => [
                    'unread_count' => $result['unread_count'],
                    'total' => $result['total'],
                    'categories' => config('notifications.categories', []),
                ],
            ]);
        } catch (NotificationInboxException $exception) {
            return $this->inboxError($exception);
        }
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->inbox->summary($request->user()),
        ]);
    }

    public function show(Request $request, MemberNotification $memberNotification): JsonResponse
    {
        try {
            $item = $this->inbox->show($request->user(), $memberNotification);

            return response()->json([
                'data' => $this->inbox->format($item),
            ]);
        } catch (NotificationInboxException $exception) {
            return $this->inboxError($exception);
        }
    }

    public function markRead(Request $request, MemberNotification $memberNotification): JsonResponse
    {
        try {
            $item = $this->inbox->markRead($request->user(), $memberNotification);

            return response()->json([
                'data' => $this->inbox->format($item),
                'meta' => ['unread_count' => $this->inbox->summary($request->user())['unread_count']],
            ]);
        } catch (NotificationInboxException $exception) {
            return $this->inboxError($exception);
        }
    }

    public function markUnread(Request $request, MemberNotification $memberNotification): JsonResponse
    {
        try {
            $item = $this->inbox->markUnread($request->user(), $memberNotification);

            return response()->json([
                'data' => $this->inbox->format($item),
                'meta' => ['unread_count' => $this->inbox->summary($request->user())['unread_count']],
            ]);
        } catch (NotificationInboxException $exception) {
            return $this->inboxError($exception);
        }
    }

    public function archive(Request $request, MemberNotification $memberNotification): JsonResponse
    {
        try {
            $item = $this->inbox->archive($request->user(), $memberNotification);

            return response()->json([
                'data' => $this->inbox->format($item),
                'meta' => ['unread_count' => $this->inbox->summary($request->user())['unread_count']],
            ]);
        } catch (NotificationInboxException $exception) {
            return $this->inboxError($exception);
        }
    }

    public function unarchive(Request $request, MemberNotification $memberNotification): JsonResponse
    {
        try {
            $item = $this->inbox->unarchive($request->user(), $memberNotification);

            return response()->json([
                'data' => $this->inbox->format($item),
                'meta' => ['unread_count' => $this->inbox->summary($request->user())['unread_count']],
            ]);
        } catch (NotificationInboxException $exception) {
            return $this->inboxError($exception);
        }
    }

    public function markAllRead(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->inbox->markAllRead($request->user()),
        ]);
    }

    public function open(Request $request, MemberNotification $memberNotification): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->inbox->open($request->user(), $memberNotification),
            ]);
        } catch (NotificationInboxException $exception) {
            return $this->inboxError($exception);
        }
    }

    private function inboxError(NotificationInboxException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->codeKey(),
            'details' => $exception->details(),
        ], $exception->httpStatus());
    }
}
