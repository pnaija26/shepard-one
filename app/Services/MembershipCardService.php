<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\MembershipCardScanEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Story 2.6: digital membership card display and QR verification.
 */
class MembershipCardService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    public function cardForMember(User $user): array
    {
        $member = $this->resolveLinkedMember($user);
        $this->assertEligible($member);

        $member->loadMissing('branch:id,name');
        $token = $this->issueToken($member);

        return [
            'member_id' => $member->id,
            'full_name' => $member->fullName(),
            'preferred_name' => $member->preferred_name,
            'photo_path' => $member->photo_path,
            'membership_id' => $member->membership_id,
            'branch' => $member->branch ? [
                'id' => $member->branch->id,
                'name' => $member->branch->name,
            ] : null,
            'status' => $this->displayStatus($member),
            'membership_status' => $member->membership_status,
            'lifecycle_status' => $member->lifecycle_status,
            'qr' => [
                'payload' => $token['token'],
                'expires_at' => $token['expires_at'],
            ],
            'issued_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{verified: bool, purpose: string, member: array<string, mixed>}
     */
    public function verify(User $scanner, string $token, string $purpose): array
    {
        $this->assertCanScan($scanner, $purpose);

        $payload = null;

        try {
            $payload = $this->parseToken($token);
            $this->assertNotReplayed($payload['jti']);
            $member = Member::with('branch:id,name')->findOrFail($payload['mid']);
            $this->assertTokenFresh($payload, $member);
            $this->assertEligible($member);

            $this->recordScan($payload['jti'], $member->id, $scanner->id, $purpose, MembershipCardScanEvent::OUTCOME_VERIFIED);

            $this->audit->record(
                actor: $scanner,
                action: 'membership_card.verified',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'members',
                branchId: $member->branch_id,
                subjectType: Member::class,
                subjectId: $member->id,
                metadata: ['purpose' => $purpose, 'jti' => $payload['jti']],
            );

            return [
                'verified' => true,
                'purpose' => $purpose,
                'member' => $this->purposeFields($member, $purpose),
            ];
        } catch (MembershipCardTokenException|MembershipCardIneligibleException $e) {
            if (
                $payload !== null
                && isset($payload['mid'], $payload['jti'])
                && ! ($e instanceof MembershipCardTokenException && $e->reason === 'replay')
            ) {
                $this->recordScan(
                    $payload['jti'],
                    (int) $payload['mid'],
                    $scanner->id,
                    $purpose,
                    MembershipCardScanEvent::OUTCOME_REJECTED,
                );
            }

            $this->audit->record(
                actor: $scanner,
                action: 'membership_card.rejected',
                category: AuditEvent::CATEGORY_SECURITY,
                module: 'members',
                metadata: [
                    'purpose' => $purpose,
                    'reason' => $e instanceof MembershipCardTokenException ? $e->reason : 'ineligible',
                    'message' => $e->getMessage(),
                ],
            );

            throw $e;
        }
    }

    /** @return array{token: string, expires_at: string} */
    public function issueToken(Member $member): array
    {
        $ttl = config('membership_card.token_ttl', 300);
        $issuedAt = now()->timestamp;
        $expiresAt = now()->addSeconds($ttl)->timestamp;

        $payload = [
            'jti' => (string) Str::uuid(),
            'mid' => $member->id,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'v' => $member->updated_at?->timestamp ?? $issuedAt,
        ];

        $encoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encoded, $this->signingKey());

        return [
            'token' => $encoded . '.' . $signature,
            'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
        ];
    }

    public function memberFromToken(string $token): Member
    {
        $payload = $this->parseToken($token);
        $member = Member::query()->find($payload['mid']);

        if ($member === null) {
            throw new MembershipCardTokenException('Invalid membership card reference.', 'malformed');
        }

        $this->assertTokenFresh($payload, $member);
        $this->assertEligible($member);

        return $member;
    }

    /** @return array<string, mixed> */
    private function parseToken(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new MembershipCardTokenException('Invalid membership card reference.', 'malformed');
        }

        [$encoded, $signature] = $parts;
        $expected = hash_hmac('sha256', $encoded, $this->signingKey());

        if (! hash_equals($expected, $signature)) {
            throw new MembershipCardTokenException('Invalid membership card reference.', 'signature');
        }

        $json = $this->base64UrlDecode($encoded);
        $payload = json_decode($json, true);

        if (! is_array($payload)
            || empty($payload['jti'])
            || empty($payload['mid'])
            || empty($payload['exp'])
        ) {
            throw new MembershipCardTokenException('Invalid membership card reference.', 'malformed');
        }

        if ($payload['exp'] < now()->timestamp) {
            throw new MembershipCardTokenException('This membership card reference has expired.', 'expired');
        }

        return $payload;
    }

    private function assertNotReplayed(string $jti): void
    {
        if (MembershipCardScanEvent::where('jti', $jti)->exists()) {
            throw new MembershipCardTokenException('This membership card reference has already been used.', 'replay');
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function assertTokenFresh(array $payload, Member $member): void
    {
        $issuedAt = (int) ($payload['iat'] ?? 0);
        $updatedAt = $member->updated_at?->timestamp ?? 0;

        if ($updatedAt > $issuedAt) {
            throw new MembershipCardTokenException(
                'This membership card reference is no longer current. Please refresh the card.',
                'stale',
            );
        }
    }

    private function assertEligible(Member $member): void
    {
        $reasons = $this->eligibilityReasons($member);

        if ($reasons !== []) {
            throw new MembershipCardIneligibleException(
                'Membership card is not available for this profile.',
                $reasons,
            );
        }
    }

    /** @return string[] */
    private function eligibilityReasons(Member $member): array
    {
        $reasons = [];

        if ($member->merged_into_id !== null) {
            $reasons[] = 'merged';
        }

        if ($member->isArchived()) {
            $reasons[] = 'archived';
        }

        if (! $member->consent_data_processing) {
            $reasons[] = 'consent_required';
        }

        if (! in_array($member->membership_status, config('membership_card.eligible_membership_statuses', []), true)) {
            $reasons[] = 'membership_status';
        }

        $lifecycleStatus = $member->lifecycle_status ?? 'active';
        if (in_array($lifecycleStatus, config('membership_card.blocked_lifecycle_statuses', []), true)) {
            $reasons[] = 'lifecycle_status';
        }

        foreach (config('membership_card.required_fields', []) as $field) {
            if (empty($member->{$field})) {
                $reasons[] = "missing_{$field}";
            }
        }

        return $reasons;
    }

    /** @return array<string, mixed> */
    private function purposeFields(Member $member, string $purpose): array
    {
        $config = config("membership_card.purposes.{$purpose}");
        if ($config === null) {
            throw new MembershipCardTokenException('Unsupported verification purpose.', 'purpose');
        }

        $all = [
            'id' => $member->id,
            'full_name' => $member->fullName(),
            'membership_id' => $member->membership_id,
            'photo_path' => $member->photo_path,
            'branch' => $member->branch ? [
                'id' => $member->branch->id,
                'name' => $member->branch->name,
            ] : null,
            'status' => $this->displayStatus($member),
        ];

        return collect($config['fields'])
            ->mapWithKeys(fn (string $field) => [$field => $all[$field] ?? null])
            ->all();
    }

    private function displayStatus(Member $member): string
    {
        return $member->lifecycle_status ?? $member->membership_status;
    }

    private function resolveLinkedMember(User $user): Member
    {
        $member = Member::query()->where('user_id', $user->id)->first();

        if ($member === null) {
            throw new \Illuminate\Auth\Access\AuthorizationException('No member profile is linked to your account.');
        }

        return $member;
    }

    private function assertCanScan(User $scanner, string $purpose): void
    {
        if (! array_key_exists($purpose, config('membership_card.purposes', []))) {
            throw new MembershipCardTokenException('Unsupported verification purpose.', 'purpose');
        }

        if (! $this->authorization->allows($scanner, 'membership_card.scan')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function recordScan(string $jti, int $memberId, int $scannerId, string $purpose, string $outcome): void
    {
        DB::transaction(function () use ($jti, $memberId, $scannerId, $purpose, $outcome): void {
            if (MembershipCardScanEvent::where('jti', $jti)->lockForUpdate()->exists()) {
                throw new MembershipCardTokenException('This membership card reference has already been used.', 'replay');
            }

            MembershipCardScanEvent::create([
                'jti' => $jti,
                'member_id' => $memberId,
                'scanned_by' => $scannerId,
                'purpose' => $purpose,
                'outcome' => $outcome,
                'scanned_at' => now(),
            ]);
        });
    }

    private function signingKey(): string
    {
        return (string) config('app.key');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw new MembershipCardTokenException('Invalid membership card reference.', 'malformed');
        }

        return $decoded;
    }
}
