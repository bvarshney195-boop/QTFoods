<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class HelpReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        foreach ($this->articles() as $article) {
            DB::table('help_articles')->updateOrInsert(['id' => $article['id']], $article + [
                'status' => 'PUBLISHED',
                'content_version' => 1,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function articles(): array
    {
        return [
            $this->article(
                '00000000-0000-4000-8000-000000003001',
                'getting-started-with-your-context',
                'GETTING_STARTED',
                null,
                'Getting started with your company and plant context',
                'Choose the correct operating context, understand role-scoped navigation, and recover when access changes.',
                'context company plant navigation access role session',
                [
                    ['heading' => 'Select the operating boundary', 'content' => 'Use the company and plant selector in the header before opening a transaction screen. Every protected query and command is constrained to that selected boundary on the server.'],
                    ['heading' => 'Check role-scoped navigation', 'content' => 'The sidebar lists only screens allowed by your effective role assignment. A missing screen normally means the role, plant assignment, or effective dates need review.'],
                    ['heading' => 'Recover after an access change', 'content' => 'Refresh the session or sign in again after an administrator changes an assignment. If the selected context was revoked, choose another authorised context when prompted.'],
                ],
            ),
            $this->article(
                '00000000-0000-4000-8000-000000003002',
                'account-security-and-device-sessions',
                'SECURITY',
                null,
                'Account security and device sessions',
                'Change your password, enrol MFA, protect recovery codes, and revoke a device session.',
                'password mfa totp recovery code device session security',
                [
                    ['heading' => 'Use the Security panel', 'content' => 'Open Security from the header to change your password, enrol or disable TOTP, replace recovery codes, and inspect logical device sessions.'],
                    ['heading' => 'Store recovery codes safely', 'content' => 'Each recovery code can be used once. Store the newly issued set outside the ERP and replace it immediately if it may have been exposed.'],
                    ['heading' => 'Revoke unfamiliar devices', 'content' => 'Revoking a device invalidates its authenticated session. Sign out the current device with Logout; use device revocation for other sessions.'],
                ],
            ),
            $this->article(
                '00000000-0000-4000-8000-000000003003',
                'work-queue-ownership-and-escalation',
                'WORKFLOWS',
                'WRK-HOME',
                'Work queue ownership, deadlines, and escalation',
                'Claim work, follow the exact workflow target, and understand policy-derived priority and deadlines.',
                'work queue task approval claim assign deadline escalation',
                [
                    ['heading' => 'Claim before acting', 'content' => 'Claim an available task before completing non-approval work. Approval tasks close automatically when the governed source workflow records its decision.'],
                    ['heading' => 'Use the workflow target', 'content' => 'Open the target shown on the task. The target screen and record are server-derived so you do not have to search for the underlying transaction.'],
                    ['heading' => 'Respect policy deadlines', 'content' => 'Priority, due time, and escalation state come from the active workflow policy. Managers can assign or unassign work only within the selected scope.'],
                ],
            ),
            $this->article(
                '00000000-0000-4000-8000-000000003004',
                'resolving-version-conflicts-safely',
                'CONTROLS',
                null,
                'Resolving version conflicts safely',
                'Refresh stale records, preserve evidence, and retry commands without duplicating business effects.',
                'conflict if-match version idempotency retry refresh',
                [
                    ['heading' => 'Why a conflict appears', 'content' => 'Existing-record commands send the version you opened. If another user changes the record first, the server rejects the stale command instead of overwriting their work.'],
                    ['heading' => 'Refresh and review', 'content' => 'Reload the detail, compare the new state and history, then repeat the action only if it is still appropriate. Do not copy a version number from another record.'],
                    ['heading' => 'Safe retries', 'content' => 'A command retry with the same idempotency key and identical content returns the original result. Reusing that key with different content is rejected.'],
                ],
            ),
            $this->article(
                '00000000-0000-4000-8000-000000003005',
                'inventory-availability-and-lot-evidence',
                'WORKFLOWS',
                'INV-STK',
                'Inventory availability and lot evidence',
                'Read on-hand, reserved, quality, expiry, ownership, and immutable movement evidence correctly.',
                'inventory stock lot owner expiry reserved available quality movement',
                [
                    ['heading' => 'Available is server-derived', 'content' => 'Available quantity is derived from on-hand quantity, active reservations, quality status, and expiry. A positive on-hand balance is not automatically reservable.'],
                    ['heading' => 'Open the position', 'content' => 'Use position detail to verify item, lot, owner, location, UOM, quality, reservation history, and movement links before initiating an inventory operation.'],
                    ['heading' => 'Follow immutable evidence', 'content' => 'Posted movements are immutable ledger evidence. Corrections use governed return, transfer, count, adjustment, expiry, or disposal commands rather than editing a movement.'],
                ],
            ),
            $this->article(
                '00000000-0000-4000-8000-000000003006',
                'purchase-requisition-review-flow',
                'WORKFLOWS',
                'PUR-REQ',
                'Purchase requisition review flow',
                'Prepare a costed requisition and follow value-band maker-checker review through resubmission.',
                'purchase requisition approval submit reject resubmit maker checker',
                [
                    ['heading' => 'Prepare the draft', 'content' => 'Record the purpose, required date, and every costed line in INR. The server recalculates line and header totals and validates active item and UOM identities.'],
                    ['heading' => 'Submit an immutable authority snapshot', 'content' => 'Submission resolves the active approval rule and value band. The resulting authority snapshot remains attached to that review round even if policy later changes.'],
                    ['heading' => 'Correct a rejection', 'content' => 'Review the decision notes, edit the rejected requisition, and resubmit. The maker cannot approve the same submission, including through delegated authority.'],
                ],
            ),
            $this->article(
                '00000000-0000-4000-8000-000000003007',
                'controlled-report-snapshots-and-exports',
                'REPORTING',
                'BI-REP',
                'Controlled report snapshots and exports',
                'Generate cutoff-bound report evidence and verify its source freshness, row count, and SHA-256 metadata.',
                'report cutoff freshness snapshot checksum sha256 csv json export',
                [
                    ['heading' => 'Choose the cutoff deliberately', 'content' => 'Each run records the selected cutoff and the latest source timestamp represented by the read model. Inventory availability is a current projection and therefore only accepts today.'],
                    ['heading' => 'Treat runs as immutable evidence', 'content' => 'Rows, column definitions, parameters, totals, and checksum are captured together. Generate a new run when source data changes; an existing run is never refreshed in place.'],
                    ['heading' => 'Verify an export', 'content' => 'CSV and JSON exports are generated from the stored rows, not live tables. Download metadata includes the byte size and SHA-256 digest for integrity verification.'],
                ],
            ),
            $this->article(
                '00000000-0000-4000-8000-000000003008',
                'audit-and-outbox-evidence',
                'CONTROLS',
                'ADM-AUD',
                'Audit and outbox evidence',
                'Trace a governed command from audit outcome through transactional integration delivery.',
                'audit evidence outbox correlation request integration retry quarantine',
                [
                    ['heading' => 'Start from the business record', 'content' => 'Use its audit or history links to identify the command, actor, entity version, selected scope, correlation identifier, and safe change summary.'],
                    ['heading' => 'Follow the outbox event', 'content' => 'The transactional outbox event is committed with the business change. Delivery attempts and receiver acknowledgements are retained separately.'],
                    ['heading' => 'Retry with control', 'content' => 'Only authorised administrators can manually retry or quarantine an integration event. Review the latest failure and receiver acknowledgement before acting.'],
                ],
            ),
        ];
    }

    private function article(
        string $id,
        string $slug,
        string $category,
        ?string $screen,
        string $title,
        string $summary,
        string $searchTerms,
        array $body,
    ): array {
        return [
            'id' => $id,
            'slug' => $slug,
            'category' => $category,
            'related_screen_code' => $screen,
            'title' => $title,
            'summary' => $summary,
            'body_json' => json_encode($body, JSON_THROW_ON_ERROR),
            'search_terms' => $searchTerms,
        ];
    }
}
