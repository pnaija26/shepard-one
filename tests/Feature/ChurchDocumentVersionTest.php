<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ChurchDocument;
use App\Models\ChurchDocumentVersion;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Story 14.2: version, retrieve, and audit church documents.
 */
class ChurchDocumentVersionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $hq = Organization::create(['name' => 'HQ', 'type' => 'headquarters', 'identifier' => 'DOCV-HQ']);
        $this->branch = Organization::create([
            'name' => 'Branch A',
            'type' => 'branch',
            'identifier' => 'DOCV-A',
            'parent_id' => $hq->id,
        ]);
    }

    /**
     * @param  list<string>  $actions
     */
    private function grant(User $user, array $actions): void
    {
        $role = Role::create(['name' => 'docv_' . $user->id . '_' . substr(md5(implode(',', $actions)), 0, 6)]);
        foreach ($actions as $action) {
            RolePermission::create(['role_id' => $role->id, 'scope_type' => 'global', 'action' => $action]);
        }
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'granted_by' => $user->id]);
    }

    private function editor(array $extra = []): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, array_merge([
            'documents.read',
            'documents.upload',
            'documents.manage',
            'members.read',
        ], $extra));

        return $user;
    }

    private function reader(): User
    {
        $user = $this->privilegedUser(['branch_id' => $this->branch->id]);
        $this->grant($user, ['documents.read', 'members.read']);

        return $user;
    }

    private function member(): Member
    {
        return Member::create([
            'membership_id' => 'DOCV-M-01',
            'branch_id' => $this->branch->id,
            'registration_channel' => 'web',
            'first_name' => 'Version',
            'last_name' => 'Member',
            'consent_data_processing' => true,
            'lifecycle_stage' => 'member',
            'lifecycle_status' => 'active',
        ]);
    }

    /**
     * @return array{content_base64: string, content_hash: string, size_bytes: int}
     */
    private function pdfFile(string $suffix): array
    {
        $binary = "%PDF-1.4\n% version {$suffix}\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF";

        return [
            'content_base64' => base64_encode($binary),
            'content_hash' => hash('sha256', $binary),
            'size_bytes' => strlen($binary),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createDocument(User $actor, Member $member, string $suffix = 'v1', string $classification = 'internal'): array
    {
        $file = $this->pdfFile($suffix);

        return $this->actingAsMfaVerified($actor)
            ->postJson('/api/church-documents', array_merge($file, [
                'title' => 'Member record',
                'category' => 'evidence',
                'record_type' => 'member',
                'record_id' => $member->id,
                'classification' => $classification,
                'access_scope' => 'record_viewers',
                'retention_policy' => 'standard_1y',
                'filename' => "record-{$suffix}.pdf",
                'mime_type' => 'application/pdf',
            ]))
            ->assertCreated()
            ->json('data');
    }

    public function test_editor_replaces_document_and_prior_versions_are_retained(): void
    {
        $editor = $this->editor();
        $member = $this->member();
        $created = $this->createDocument($editor, $member, 'v1');
        $documentId = $created['id'];
        $reference = $created['reference'];

        $replacement = $this->pdfFile('v2');
        $updated = $this->actingAsMfaVerified($editor)
            ->postJson("/api/church-documents/{$documentId}/versions", array_merge($replacement, [
                'reason' => 'Updated certificate received.',
                'filename' => 'record-v2.pdf',
                'mime_type' => 'application/pdf',
            ]))
            ->assertOk()
            ->assertJsonPath('data.version_number', 2)
            ->json('data');

        $this->assertDatabaseHas('church_document_versions', [
            'church_document_id' => $documentId,
            'version_number' => 1,
            'is_current' => false,
            'content_hash' => $this->pdfFile('v1')['content_hash'],
        ]);
        $this->assertDatabaseHas('church_document_versions', [
            'church_document_id' => $documentId,
            'version_number' => 2,
            'is_current' => true,
            'replacement_reason' => 'Updated certificate received.',
        ]);

        $this->actingAsMfaVerified($editor)
            ->getJson("/api/church-documents/reference/{$reference}")
            ->assertOk()
            ->assertJsonPath('data.version_number', 2)
            ->assertJsonPath('data.content_hash', $updated['content_hash']);

        $access = $this->actingAsMfaVerified($editor)
            ->postJson("/api/church-documents/{$documentId}/access", [
                'mode' => 'download',
                'version_number' => 1,
            ])
            ->assertOk()
            ->json('data');

        $this->actingAsMfaVerified($editor)
            ->get("/api/church-documents/{$documentId}/download?token={$access['token']}&version_number=1")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_restricted_access_is_audited_and_lifecycle_blocks_unauthorized_deletion(): void
    {
        $editor = $this->editor();
        $reader = $this->reader();
        $member = $this->member();
        $created = $this->createDocument($editor, $member, 'restricted', 'restricted');
        $documentId = $created['id'];

        $access = $this->actingAsMfaVerified($editor)
            ->postJson("/api/church-documents/{$documentId}/access", ['mode' => 'preview'])
            ->assertOk()
            ->json('data');

        $this->actingAsMfaVerified($editor)
            ->get("/api/church-documents/{$documentId}/download?token={$access['token']}")
            ->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'church_document.accessed',
            'subject_type' => ChurchDocument::class,
            'subject_id' => $documentId,
        ]);

        $this->actingAsMfaVerified($reader)
            ->deleteJson("/api/church-documents/{$documentId}")
            ->assertForbidden();

        $document = ChurchDocument::query()->findOrFail($documentId);
        $replacement = $this->pdfFile('restricted-v2');
        $this->actingAsMfaVerified($editor)
            ->postJson("/api/church-documents/{$documentId}/versions", array_merge($replacement, [
                'reason' => 'Corrected copy.',
                'filename' => 'record-v2.pdf',
                'mime_type' => 'application/pdf',
            ]))
            ->assertOk();

        $document->update([
            'retention_ends_at' => now()->subDay(),
            'retention_policy' => 'standard_1y',
        ]);

        Artisan::call('documents:process-lifecycle');
        $this->assertSame(ChurchDocument::LIFECYCLE_ARCHIVED, $document->fresh()->lifecycle_status);

        $document->update(['legal_hold' => true, 'lifecycle_status' => ChurchDocument::LIFECYCLE_ACTIVE]);
        $this->assertSame(2, ChurchDocumentVersion::query()->where('church_document_id', $documentId)->count());

        $this->actingAsMfaVerified($editor)
            ->postJson("/api/church-documents/{$documentId}/archive-request")
            ->assertStatus(422)
            ->assertJsonPath('code', 'legal_hold');

        $this->actingAsMfaVerified($editor)
            ->deleteJson("/api/church-documents/{$documentId}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'deletion_blocked');
    }
}
