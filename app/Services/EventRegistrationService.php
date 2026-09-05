<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\ChurchEvent;
use App\Models\ChurchEventRegistration;
use App\Models\ChurchEventScanEvent;
use App\Models\Member;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Story 4.3: event registration and QR admission.
 */
class EventRegistrationService
{
    public function __construct(
        private AuthorizationService $authorization,
        private AuditService $audit,
    ) {
    }

    /**
     * @return Collection<int, ChurchEventRegistration>
     */
    public function listRegistrations(User $actor, ChurchEvent $event): Collection
    {
        $this->assertCan($actor, 'events.registrations.read');
        $this->assertEventInScope($actor, $event);

        return ChurchEventRegistration::query()
            ->where('church_event_id', $event->id)
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function register(User $actor, ChurchEvent $event, array $payload): ChurchEventRegistration
    {
        $staffAssist = $this->authorization->allows($actor, 'events.registrations.manage');
        if (! $staffAssist && ! $this->authorization->allows($actor, 'events.registrations.self')) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $this->assertEventInScope($actor, $event);

        $validated = $this->validateRegistrationPayload($payload, $staffAssist);

        return DB::transaction(function () use ($actor, $event, $validated, $staffAssist): ChurchEventRegistration {
            $this->assertRegistrationAllowed($event, $validated);

            $personType = $validated['person_type'] ?? null;
            $personId = $validated['person_id'] ?? null;

            if ($personType !== null && $personId !== null) {
                $this->assertNoDuplicate($event, $personType, (int) $personId);
            } elseif (! empty($validated['registrant_email'])) {
                $this->assertNoDuplicateEmail($event, $validated['registrant_email']);
            }

            $status = $this->resolveRegistrationStatus($event);
            $paymentStatus = $this->resolvePaymentStatus($event, $validated, $status);

            if ($paymentStatus === ChurchEventRegistration::PAYMENT_PENDING && $status === ChurchEventRegistration::STATUS_CONFIRMED) {
                throw new EventRegistrationException(
                    'Payment is required before registration can be confirmed.',
                    'payment_required',
                    422,
                    'Complete payment to secure your place.',
                );
            }

            $jti = (string) Str::uuid();
            $registration = ChurchEventRegistration::create([
                'church_event_id' => $event->id,
                'person_type' => $personType,
                'person_id' => $personId,
                'registrant_name' => $validated['registrant_name'],
                'registrant_email' => $validated['registrant_email'] ?? null,
                'registrant_phone' => $validated['registrant_phone'] ?? null,
                'channel' => $validated['channel'] ?? 'web',
                'status' => $status,
                'confirmation_code' => strtoupper(Str::random(10)),
                'credential_jti' => $jti,
                'payment_status' => $paymentStatus,
                'consent_data_processing' => (bool) ($validated['consent_data_processing'] ?? false),
                'registered_by' => $staffAssist ? $actor->id : null,
            ]);

            $this->audit->record(
                actor: $actor,
                action: 'event.registration.created',
                category: AuditEvent::CATEGORY_BUSINESS,
                module: 'events',
                branchId: $event->branch_id,
                subjectType: ChurchEvent::class,
                subjectId: $event->id,
                after: ['registration_id' => $registration->id, 'status' => $registration->status],
            );

            return $registration->fresh();
        });
    }

    /**
     * @return array{admitted: bool, message: string, registration?: array<string, mixed>, event_pass?: array<string, mixed>}
     */
    public function admitByCredential(User $scanner, string $token, ?int $expectedEventId = null): array
    {
        $this->assertCan($scanner, 'events.admit.scan');

        $payload = null;
        $registration = null;

        try {
            $payload = $this->parseCredential($token);
            $registration = ChurchEventRegistration::query()
                ->with('event')
                ->where('credential_jti', $payload['jti'])
                ->first();

            if ($registration === null) {
                throw new EventRegistrationException('Registration not recognized.', 'invalid', 404);
            }

            $event = $registration->event;
            if ($event === null) {
                throw new EventRegistrationException('Registration not recognized.', 'invalid', 404);
            }

            $this->assertEventInScope($scanner, $event);

            if ($expectedEventId !== null && (int) $expectedEventId !== (int) $event->id) {
                throw new EventRegistrationException('Credential is not valid for this event.', 'wrong_event', 422);
            }

            if ($expectedEventId !== null && isset($payload['eid']) && (int) $payload['eid'] !== (int) $expectedEventId) {
                throw new EventRegistrationException('Credential is not valid for this event.', 'wrong_event', 422);
            }

            if ($registration->status === ChurchEventRegistration::STATUS_CANCELLED) {
                throw new EventRegistrationException('This registration is no longer valid.', 'cancelled', 422);
            }

            if ($registration->status === ChurchEventRegistration::STATUS_WAITLISTED) {
                throw new EventRegistrationException('This registration is on the waitlist.', 'waitlisted', 422, 'Await seat confirmation.');
            }

            if ($registration->admitted_at !== null) {
                throw new EventRegistrationException('This credential has already been used.', 'duplicate_scan', 422);
            }

            if ($registration->payment_status === ChurchEventRegistration::PAYMENT_PENDING) {
                throw new EventRegistrationException('Payment is required before admission.', 'payment_required', 422);
            }

            DB::transaction(function () use ($scanner, $registration, $event, $payload): void {
                $registration->update(['admitted_at' => now()]);

                ChurchEventScanEvent::create([
                    'church_event_id' => $event->id,
                    'registration_id' => $registration->id,
                    'credential_jti' => $payload['jti'],
                    'outcome' => ChurchEventScanEvent::OUTCOME_ADMITTED,
                    'scanned_by' => $scanner->id,
                    'created_at' => now(),
                ]);

                $this->audit->record(
                    actor: $scanner,
                    action: 'event.registration.admitted',
                    category: AuditEvent::CATEGORY_BUSINESS,
                    module: 'events',
                    branchId: $event->branch_id,
                    subjectType: ChurchEventRegistration::class,
                    subjectId: $registration->id,
                );
            });

            return [
                'admitted' => true,
                'message' => 'Admission successful.',
                'registration' => $this->formatRegistration($registration->fresh(), includeCredential: false),
                'event_pass' => [
                    'event_id' => $event->id,
                    'event_title' => $event->title,
                    'registrant_name' => $registration->registrant_name,
                    'admitted_at' => $registration->fresh()->admitted_at?->toIso8601String(),
                ],
            ];
        } catch (EventRegistrationException $e) {
            if ($payload !== null && isset($payload['jti'])) {
                ChurchEventScanEvent::create([
                    'church_event_id' => $registration?->church_event_id ?? ($expectedEventId ?? 0),
                    'registration_id' => $registration?->id,
                    'credential_jti' => $payload['jti'],
                    'outcome' => ChurchEventScanEvent::OUTCOME_REJECTED,
                    'reason' => $e->reason,
                    'scanned_by' => $scanner->id,
                    'created_at' => now(),
                ]);
            }

            return [
                'admitted' => false,
                'message' => $e->getMessage(),
                'next_step' => $e->nextStep,
                'reason' => $e->reason,
            ];
        }
    }

    public function formatRegistration(ChurchEventRegistration $registration, bool $includeCredential = true): array
    {
        $event = $registration->relationLoaded('event') ? $registration->event : $registration->event()->first();

        return [
            'id' => $registration->id,
            'church_event_id' => $registration->church_event_id,
            'registrant_name' => $registration->registrant_name,
            'registrant_email' => $registration->registrant_email,
            'status' => $registration->status,
            'confirmation_code' => $registration->confirmation_code,
            'channel' => $registration->channel,
            'payment_status' => $registration->payment_status,
            'admitted_at' => $registration->admitted_at?->toIso8601String(),
            'credential' => $includeCredential ? $this->issueCredential($registration) : null,
            'event' => $event ? [
                'id' => $event->id,
                'title' => $event->title,
                'event_date' => $event->event_date?->toDateString(),
            ] : null,
        ];
    }

    /**
     * @return array{token: string, expires_at: string}
     */
    public function issueCredential(ChurchEventRegistration $registration): array
    {
        $event = $registration->event ?? ChurchEvent::query()->find($registration->church_event_id);
        $expiresAt = $event?->event_date?->copy()->addDays((int) config('event_registrations.credential_ttl_days', 30))->endOfDay()
            ?? now()->addDays(30);

        $payload = [
            'jti' => $registration->credential_jti,
            'rid' => $registration->id,
            'eid' => $registration->church_event_id,
            'exp' => $expiresAt->timestamp,
        ];

        $encoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encoded, $this->signingKey());

        return [
            'token' => $encoded . '.' . $signature,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function confirmedCount(ChurchEvent $event): int
    {
        return ChurchEventRegistration::query()
            ->where('church_event_id', $event->id)
            ->where('status', ChurchEventRegistration::STATUS_CONFIRMED)
            ->count();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertRegistrationAllowed(ChurchEvent $event, array $validated): void
    {
        if ($event->status !== ChurchEvent::STATUS_PUBLISHED) {
            $nextStep = $event->status === ChurchEvent::STATUS_CANCELLED
                ? 'Contact the event team for assistance.'
                : 'Check back when registration opens.';

            throw new EventRegistrationException('Registration is not open for this event.', 'closed', 422, $nextStep);
        }

        if ($event->registration_availability !== ChurchEvent::REGISTRATION_OPEN) {
            throw new EventRegistrationException('Registration is currently closed.', 'closed', 422, 'Contact the event team for assistance.');
        }

        if (! ($validated['consent_data_processing'] ?? false)) {
            throw ValidationException::withMessages(['consent_data_processing' => ['Consent is required to register.']]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveRegistrationStatus(ChurchEvent $event): string
    {
        $registrationConfig = $event->registration ?? [];
        $capacity = (int) ($registrationConfig['capacity'] ?? $event->capacity ?? 0);
        $confirmed = $this->confirmedCount($event);

        if ($capacity > 0 && $confirmed >= $capacity) {
            if ($registrationConfig['waitlist_enabled'] ?? false) {
                return ChurchEventRegistration::STATUS_WAITLISTED;
            }

            throw new EventRegistrationException(
                'This event is at capacity.',
                'capacity_full',
                422,
                'Join the waitlist or choose another session.',
            );
        }

        return ChurchEventRegistration::STATUS_CONFIRMED;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolvePaymentStatus(ChurchEvent $event, array $validated, string $status): string
    {
        if ($status === ChurchEventRegistration::STATUS_WAITLISTED) {
            return ChurchEventRegistration::PAYMENT_NOT_REQUIRED;
        }

        $ticketing = $event->ticketing_policy ?? [];
        if (($ticketing['type'] ?? 'free') === 'paid') {
            if (($validated['payment_status'] ?? null) === ChurchEventRegistration::PAYMENT_PAID) {
                return ChurchEventRegistration::PAYMENT_PAID;
            }

            return ChurchEventRegistration::PAYMENT_PENDING;
        }

        return ChurchEventRegistration::PAYMENT_NOT_REQUIRED;
    }

    private function assertNoDuplicate(ChurchEvent $event, string $personType, int $personId): void
    {
        $exists = ChurchEventRegistration::query()
            ->where('church_event_id', $event->id)
            ->where('person_type', $personType)
            ->where('person_id', $personId)
            ->where('status', '!=', ChurchEventRegistration::STATUS_CANCELLED)
            ->exists();

        if ($exists) {
            throw new EventRegistrationException(
                'You are already registered for this event.',
                'duplicate',
                422,
                'Use your existing confirmation or contact the event team.',
            );
        }
    }

    private function assertNoDuplicateEmail(ChurchEvent $event, string $email): void
    {
        $exists = ChurchEventRegistration::query()
            ->where('church_event_id', $event->id)
            ->where('registrant_email', $email)
            ->where('status', '!=', ChurchEventRegistration::STATUS_CANCELLED)
            ->exists();

        if ($exists) {
            throw new EventRegistrationException(
                'A registration already exists for this email.',
                'duplicate',
                422,
                'Check your confirmation email or contact the event team.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseCredential(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new EventRegistrationException('Invalid event credential.', 'malformed', 422);
        }

        [$encoded, $signature] = $parts;
        $expected = hash_hmac('sha256', $encoded, $this->signingKey());

        if (! hash_equals($expected, $signature)) {
            throw new EventRegistrationException('Invalid event credential.', 'signature', 422);
        }

        $payload = json_decode($this->base64UrlDecode($encoded), true);

        if (! is_array($payload) || empty($payload['jti']) || empty($payload['rid']) || empty($payload['exp'])) {
            throw new EventRegistrationException('Invalid event credential.', 'malformed', 422);
        }

        if ($payload['exp'] < now()->timestamp) {
            throw new EventRegistrationException('This event credential has expired.', 'expired', 422);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRegistrationPayload(array $payload, bool $staffAssist): array
    {
        $rules = [
            'registrant_name' => ['required', 'string', 'max:255'],
            'registrant_email' => ['nullable', 'email', 'max:191'],
            'registrant_phone' => ['nullable', 'string', 'max:32'],
            'channel' => ['nullable', 'string', 'in:' . implode(',', config('event_registrations.channels', []))],
            'consent_data_processing' => ['required', 'boolean', 'accepted'],
            'payment_status' => ['nullable', 'string', 'in:' . implode(',', config('event_registrations.payment_statuses', []))],
        ];

        if ($staffAssist) {
            $rules['person_type'] = ['nullable', 'string', 'in:' . Member::class . ',' . Visitor::class];
            $rules['person_id'] = ['nullable', 'integer', 'min:1'];
        } else {
            $rules['person_type'] = ['required', 'string', 'in:' . Member::class];
            $rules['person_id'] = ['required', 'integer', 'min:1'];
        }

        return validator($payload, $rules)->validate();
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
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function assertCan(User $actor, string $action): void
    {
        if (! $this->authorization->allows($actor, $action)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }

    private function assertEventInScope(User $actor, ChurchEvent $event): void
    {
        if ($actor->isChurchWide()) {
            return;
        }

        try {
            BranchScope::for($actor)->assertIncludes((int) $event->branch_id);
        } catch (BranchScopeException) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }
    }
}
