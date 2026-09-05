<?php

namespace Tests\Feature;

use App\Models\CareCase;
use App\Models\ChurchDocument;
use App\Models\ChurchDocumentProcessingJob;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Story 14.1: upload and categorize protected church documents.
 */
class ChurchDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'DOC-HQ']);
        $this->branch = Organization::create([
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'DOC-A',
            'parent_id' => $hq->id,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'doc_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function uploader(array $extra = []): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, array_merge([
            'documents.read',
            'documents.upload',
            'members.read',
            'care.cases.read',
            'care.cases.create',
            'care.cases.sensitive.read',
            'care.cases.manage',
        ], $extra));

        return $user;
    }

    private function member(string $suffix): Member
    {
        return Member::create([
            'membership_id' => 'DOC-M-' . $suffix,
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Doc',
            'last_name' => 'Member' . $suffix,
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);
    }

    /**
     * @return array{content_base64: string, content_hash: string, size_bytes: int}
     */
    private function safePdfPayload(string $suffix = '001'): array
    {
        $binary = "%PDF-1.4\n% test document {$suffix}\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";

        return [
            'content_base64' => base64_encode($binary),
            'content_hash' => hash('sha256', $binary),
            'size_bytes' => strlen($binary),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function uploadPayload(Member $member, array $overrides = []): array
    {
        $file = $this->safePdfPayload();

        return array_merge([
            'title' => 'Baptism certificate',
            'category' => 'evidence',
            'record_type' => 'member',
            'record_id' => $member->id,
            'classification' => 'internal',
            'access_scope' => 'record_viewers',
            'retention_policy' => 'standard_7y',
            'filename' => 'baptism.pdf',
            'mime_type' => 'application/pdf',
        ], $file, $overrides);
    }

    public function test_authorized_user_uploads_member_document_to_protected_storage(): void
    {
        $actor = $this->uploader();
        $member = $this->member('01');

        $created = $this->actingAsMfaVerified($actor)
            ->postJson('/api/church-documents', $this->uploadPayload($member))
            ->assertCreated()
            ->assertJsonPath('data.record_type', 'member')
            ->assertJsonPath('data.classification', 'internal')
            ->assertJsonPath('data.malware_scan_status', ChurchDocument::SCAN_CLEAN)
            ->json('data');

        $document = ChurchDocument::query()->findOrFail($created['id']);
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertStringStartsWith('church-documents/', $document->storage_path);

        $this->assertDatabaseHas('church_document_processing_jobs', [
            'church_document_id' => $document->id,
            'job_type' => 'metadata_extract',
            'classification' => 'internal',
            'access_scope' => 'record_viewers',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'church_document.uploaded',
            'subject_type' => ChurchDocument::class,
            'subject_id' => $document->id,
        ]);
    }

    public function test_invalid_content_is_rejected_and_restricted_parent_blocks_open_scope(): void
    {
        $actor = $this->uploader();
        $member = $this->member('02');
        $beneficiary = $this->member('03');

        $careCase = CareCase::create([
            'case_number' => 'CARE-DOC-01',
            'branch_id' => $this->branch->id,
            'beneficiary_member_id' => $beneficiary->id,
            'category' => 'bereavement',
            'description' => 'Restricted pastoral support case.',
            'priority' => 'high',
            'status' => CareCase::STATUS_OPEN,
            'consent_basis' => 'family_request',
            'confidentiality' => 'care_team',
            'data_classification' => 'restricted_sensitive',
            'is_restricted' => true,
            'created_by' => $actor->id,
        ]);

        $this->actingAsMfaVerified($actor)
            ->postJson('/api/church-documents', $this->uploadPayload($member, [
                'filename' => 'payload.pdf',
                'mime_type' => 'application/x-msdownload',
                'content_base64' => base64_encode('MZ fake executable'),
                'content_hash' => hash('sha256', 'MZ fake executable'),
                'size_bytes' => strlen('MZ fake executable'),
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_mime_type');

        $this->assertDatabaseCount('church_documents', 0);

        $restrictedFile = $this->safePdfPayload('care');
        $this->actingAsMfaVerified($actor)
            ->postJson('/api/church-documents', array_merge($restrictedFile, [
                'title' => 'Care notes',
                'category' => 'evidence',
                'record_type' => 'care_case',
                'record_id' => $careCase->id,
                'classification' => 'internal',
                'access_scope' => 'staff',
                'retention_policy' => 'standard_7y',
                'filename' => 'notes.pdf',
                'mime_type' => 'application/pdf',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'classification_too_open');

        $this->actingAsMfaVerified($actor)
            ->postJson('/api/church-documents', array_merge($restrictedFile, [
                'title' => 'Care notes',
                'category' => 'evidence',
                'record_type' => 'care_case',
                'record_id' => $careCase->id,
                'classification' => 'restricted',
                'access_scope' => 'staff',
                'retention_policy' => 'standard_7y',
                'filename' => 'notes.pdf',
                'mime_type' => 'application/pdf',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'access_scope_too_open');

        $accepted = $this->actingAsMfaVerified($actor)
            ->postJson('/api/church-documents', array_merge($restrictedFile, [
                'title' => 'Care notes',
                'category' => 'evidence',
                'record_type' => 'care_case',
                'record_id' => $careCase->id,
                'classification' => 'restricted',
                'access_scope' => 'role_restricted',
                'retention_policy' => 'standard_7y',
                'filename' => 'notes.pdf',
                'mime_type' => 'application/pdf',
            ]))
            ->assertCreated()
            ->json('data');

        $this->assertSame(ChurchDocumentProcessingJob::STATUS_SKIPPED, ChurchDocumentProcessingJob::query()
            ->where('church_document_id', $accepted['id'])
            ->where('job_type', 'thumbnail')
            ->value('status'));
    }
}
