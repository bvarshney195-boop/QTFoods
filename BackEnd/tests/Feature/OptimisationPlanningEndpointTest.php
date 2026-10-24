<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OptimisationPlanningEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = '00000000-0000-4000-8000-000000000001';
    private const PLANT = '00000000-0000-4000-8000-000000000101';
    private const OTHER_PLANT = '00000000-0000-4000-8000-000000000102';
    private const OPERATIONS = '00000000-0000-4000-8000-000000000202';
    private const FINANCE = '00000000-0000-4000-8000-000000000203';
    private const ADMIN = '00000000-0000-4000-8000-000000000204';
    private const SALES = '00000000-0000-4000-8000-000000000201';
    private const FINISHED_SKU = '00000000-0000-4000-8000-000000000601';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->signIn(self::OPERATIONS);
    }

    public function test_versioned_input_recommendation_review_and_outcome_complete_the_lifecycle(): void
    {
        [$demandId, $mrpId] = $this->releasedDemand('OPT-LIFECYCLE', '1500', true);
        $workspace = $this->getJson('/api/v1/optimisation/plans')->assertOk()
            ->assertJsonPath('allowed_actions.0', 'CREATE')
            ->assertJsonPath('lookups.released_demand_plans.0.id', $demandId);
        $this->assertContains('BALANCED', $workspace->json('lookups.objectives'));

        $payload = $this->optimisationPayload('OPT-PLAN-001', $demandId);
        $key = (string) Str::uuid();
        $created = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/optimisation/plans', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.input_version', 1)->assertJsonPath('data.input_line_count', 1);
        $planId = (string) $created->json('data.id');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $created->json('data.input_checksum'));
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/optimisation/plans', $payload)
            ->assertCreated()->assertExactJson($created->json());

        $this->getJson('/api/v1/optimisation/plans/'.$planId)->assertOk()
            ->assertJsonPath('data.current_input.demand_plan.id', $demandId)
            ->assertJsonPath('data.current_input.demand_plan.version_snapshot', 2)
            ->assertJsonPath('data.current_input.lines.0.output_sku.code', 'SKU-APPLE-100')
            ->assertJsonPath('data.current_input.lines.0.available_quantity_snapshot', '500.000000')
            ->assertJsonPath('data.current_input.lines.0.material_shortage_count', 1)
            ->assertJsonPath('data.current_input.lines.0.material_shortages.0.component_code', 'SKU-APPLE-BASE');

        $generated = $this->withHeaders($this->headers(1))
            ->postJson('/api/v1/optimisation/plans/'.$planId.'/generate')
            ->assertOk()->assertJsonPath('data.status', 'GENERATED')
            ->assertJsonPath('data.record_version', 2)->assertJsonPath('data.recommendation_count', 1)
            ->assertJsonPath('data.production_quantity', '1150.000000');
        $this->assertGreaterThanOrEqual(3, $generated->json('data.warning_count'));

        $detail = $this->getJson('/api/v1/optimisation/plans/'.$planId)->assertOk()
            ->assertJsonPath('data.recommendations.0.action_type', 'MIXED')
            ->assertJsonPath('data.recommendations.0.target_quantity', '1650.000000')
            ->assertJsonPath('data.recommendations.0.stock_allocation_quantity', '500.000000')
            ->assertJsonPath('data.recommendations.0.production_quantity', '1150.000000')
            ->assertJsonPath('data.recommendations.0.priority', 'CRITICAL')
            ->assertJsonPath('data.recommendations.0.algorithm.code', 'DETERMINISTIC_NET_REQUIREMENTS')
            ->assertJsonFragment(['code' => 'HEURISTIC_SCOPE', 'severity' => 'INFO'])
            ->assertJsonFragment(['code' => 'MATERIAL_SHORTAGE', 'severity' => 'WARNING'])
            ->assertJsonFragment(['code' => 'CAPACITY_NOT_RESERVED', 'severity' => 'WARNING'])
            ->assertJsonFragment(['code' => 'COST_UNAVAILABLE', 'severity' => 'WARNING']);
        $recommendationId = (string) $detail->json('data.recommendations.0.id');

        $this->withHeaders($this->headers(2))->postJson('/api/v1/optimisation/plans/'.$planId.'/submit')
            ->assertOk()->assertJsonPath('data.status', 'SUBMITTED')->assertJsonPath('data.review_round', 1);
        $this->withHeaders($this->headers(3))->postJson('/api/v1/optimisation/plans/'.$planId.'/approve', [
            'decision_notes' => 'Attempted approval without review authority.',
        ])->assertForbidden();

        $this->signIn(self::FINANCE);
        $this->withHeaders($this->headers(3))->postJson('/api/v1/optimisation/plans/'.$planId.'/approve', [
            'decision_notes' => 'Reviewed demand version, cost caveat, capacity caveat, and material shortage.',
        ])->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.record_version', 4);
        $this->signIn(self::OPERATIONS);
        $this->withHeaders($this->headers(4))->postJson('/api/v1/optimisation/plans/'.$planId.'/complete')
            ->assertUnprocessable()->assertJsonPath(
                'error.fields.outcomes.0',
                'Record an outcome for every current recommendation before completing the plan.',
            );

        $outcome = $this->withHeaders($this->headers(4))->postJson(
            '/api/v1/optimisation/plans/'.$planId.'/recommendations/'.$recommendationId.'/outcome',
            $this->outcomePayload(),
        )->assertOk()->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.record_version', 5)->assertJsonPath('data.outcome_version', 1)
            ->assertJsonPath('data.outcomes_recorded', 1);
        $outcomeId = (string) $outcome->json('data.outcome_id');

        $replacement = $this->outcomePayload() + ['expected_outcome_version' => 1];
        $replacement['actual_service_level'] = '96.5';
        $replacement['notes'] = 'Final observation replaces the provisional service measurement.';
        $this->withHeaders($this->headers(5))->postJson(
            '/api/v1/optimisation/plans/'.$planId.'/recommendations/'.$recommendationId.'/outcome',
            $replacement,
        )->assertOk()->assertJsonPath('data.record_version', 6)->assertJsonPath('data.outcome_version', 2);

        $this->withHeaders($this->headers(6))->postJson('/api/v1/optimisation/plans/'.$planId.'/complete')
            ->assertOk()->assertJsonPath('data.status', 'COMPLETED')->assertJsonPath('data.record_version', 7);
        $this->getJson('/api/v1/optimisation/plans/'.$planId)->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.recommendations.0.outcome.id', $outcomeId)
            ->assertJsonPath('data.recommendations.0.outcome.record_version', 2)
            ->assertJsonPath('data.recommendations.0.outcome.actual_service_level', '96.500');

        $this->assertDatabaseHas('optimisation_plan_reviews', [
            'optimisation_plan_id' => $planId, 'status' => 'APPROVED', 'submitted_by' => self::OPERATIONS,
            'decided_by' => self::FINANCE,
        ]);
        $this->assertDatabaseHas('audit_events', ['command' => 'COMPLETE_OPTIMISATION_PLAN', 'entity_id' => $planId]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'optimisation.outcome.updated', 'aggregate_id' => $planId]);
        $this->assertDatabaseHas('mrp_runs', ['id' => $mrpId, 'demand_plan_id' => $demandId]);
    }

    public function test_discrete_uom_demand_and_recommendations_never_produce_fractional_units(): void
    {
        DB::table('stock_positions')->where('item_id', self::FINISHED_SKU)->update([
            'quantity_base' => 0,
            'reserved_quantity_base' => 0,
        ]);

        $fractional = $this->command()->postJson(
            '/api/v1/planning/demand',
            $this->demandPayload('DEMAND-FRACTIONAL-PACK', '15.5'),
        )->assertUnprocessable();
        $this->assertSame(
            'Demand quantity for PACK must use whole units.',
            $fractional->json()['error']['fields']['lines.0.quantity'][0] ?? null,
        );

        [$demandId] = $this->releasedDemand('OPT-WHOLE-PACK', '15');
        $payload = $this->optimisationPayload('OPT-WHOLE-PACK', $demandId);
        $payload['safety_stock_percent'] = '10';
        $created = $this->command()->postJson('/api/v1/optimisation/plans', $payload)->assertCreated();
        $planId = (string) $created->json('data.id');

        $this->withHeaders($this->headers(1))->postJson('/api/v1/optimisation/plans/'.$planId.'/generate')
            ->assertOk()
            ->assertJsonPath('data.production_quantity', '17.000000');
        $this->getJson('/api/v1/optimisation/plans/'.$planId)
            ->assertOk()
            ->assertJsonPath('data.recommendations.0.uom_code', 'PACK')
            ->assertJsonPath('data.recommendations.0.target_quantity', '17.000000')
            ->assertJsonPath('data.recommendations.0.production_quantity', '17.000000');
    }

    public function test_rejection_requires_a_new_immutable_input_and_preserves_review_history(): void
    {
        [$demandId] = $this->releasedDemand('OPT-REVISION', '100');
        $this->signIn(self::ADMIN);
        $created = $this->command()->postJson('/api/v1/optimisation/plans', $this->optimisationPayload('OPT-PLAN-REVISE', $demandId))
            ->assertCreated();
        $planId = (string) $created->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/optimisation/plans/'.$planId.'/generate')->assertOk();
        $this->withHeaders($this->headers(2))->postJson('/api/v1/optimisation/plans/'.$planId.'/submit')->assertOk();
        $this->withHeaders($this->headers(3))->postJson('/api/v1/optimisation/plans/'.$planId.'/approve', [
            'decision_notes' => 'Self approval must fail.',
        ])->assertUnprocessable()->assertJsonPath(
            'error.fields.actor.0', 'The optimisation submitter cannot decide the same recommendation set.',
        );

        $this->signIn(self::FINANCE);
        $this->withHeaders($this->headers(3))->postJson('/api/v1/optimisation/plans/'.$planId.'/reject', [
            'decision_notes' => 'Increase safety stock before approval.',
        ])->assertOk()->assertJsonPath('data.status', 'REJECTED')->assertJsonPath('data.record_version', 4);

        $this->signIn(self::OPERATIONS);
        $revision = $this->optimisationInput($demandId);
        $revision['safety_stock_percent'] = '20';
        $revised = $this->withHeaders($this->headers(4))->postJson('/api/v1/optimisation/plans/'.$planId.'/inputs', $revision)
            ->assertOk()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.input_version', 2)
            ->assertJsonPath('data.record_version', 5);
        $this->assertNotSame($created->json('data.input_checksum'), $revised->json('data.input_checksum'));
        $this->withHeaders($this->headers(5))->postJson('/api/v1/optimisation/plans/'.$planId.'/generate')
            ->assertOk()->assertJsonPath('data.record_version', 6);
        $this->withHeaders($this->headers(6))->postJson('/api/v1/optimisation/plans/'.$planId.'/submit')
            ->assertOk()->assertJsonPath('data.review_round', 2)->assertJsonPath('data.record_version', 7);

        $this->signIn(self::FINANCE);
        $this->withHeaders($this->headers(7))->postJson('/api/v1/optimisation/plans/'.$planId.'/approve', [
            'decision_notes' => 'Revised input and higher safety stock accepted.',
        ])->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.record_version', 8);
        $this->getJson('/api/v1/optimisation/plans/'.$planId)->assertOk()
            ->assertJsonPath('data.current_input_version', 2)
            ->assertJsonPath('data.current_input.safety_stock_percent', '20.000')
            ->assertJsonCount(2, 'data.input_versions')->assertJsonCount(2, 'data.reviews')
            ->assertJsonPath('data.reviews.0.status', 'APPROVED')
            ->assertJsonPath('data.reviews.1.status', 'REJECTED');
        $this->assertSame(2, DB::table('optimisation_recommendations')->where('optimisation_plan_id', $planId)->count());
    }

    public function test_permissions_scope_versions_and_source_staleness_are_enforced(): void
    {
        $this->signIn(self::SALES);
        $this->getJson('/api/v1/optimisation/plans')->assertForbidden();
        $this->postJson('/api/v1/optimisation/plans', [])->assertForbidden();

        $this->signIn(self::OPERATIONS);
        $draftDemand = $this->command()->postJson('/api/v1/planning/demand', $this->demandPayload('DEMAND-DRAFT', '10'))
            ->assertCreated();
        $this->command()->postJson('/api/v1/optimisation/plans', $this->optimisationPayload(
            'OPT-DRAFT-SOURCE', (string) $draftDemand->json('data.id'),
        ))->assertUnprocessable()->assertJsonPath(
            'error.fields.demand_plan_id.0', 'Optimisation inputs must snapshot a released demand plan.',
        );

        [$demandId] = $this->releasedDemand('OPT-STALE', '100');
        $created = $this->command()->postJson('/api/v1/optimisation/plans', $this->optimisationPayload('OPT-STALE-001', $demandId))
            ->assertCreated();
        $planId = (string) $created->json('data.id');
        $this->withHeaders($this->headers(99))->postJson('/api/v1/optimisation/plans/'.$planId.'/generate')
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/optimisation/plans/'.$planId.'/generate')
            ->assertOk()->assertJsonPath('data.record_version', 2);
        $this->withHeaders($this->headers(2))->postJson('/api/v1/planning/demand/'.$demandId.'/cancel', [
            'reason' => 'Commercial forecast was superseded.',
        ])->assertOk();
        $this->withHeaders($this->headers(2))->postJson('/api/v1/optimisation/plans/'.$planId.'/submit')
            ->assertUnprocessable()->assertJsonPath(
                'error.fields.demand_plan_id.0',
                'The source demand plan changed after snapshotting. Create a new input version before review.',
            );

        $this->signIn(self::ADMIN, self::OTHER_PLANT);
        $this->getJson('/api/v1/optimisation/plans/'.$planId)->assertNotFound();
        $this->signIn(self::OPERATIONS);
        $this->getJson('/api/v1/optimisation/plans?q=STALE&status=GENERATED&objective=BALANCED')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $planId);
        $this->withHeaders($this->headers(2))->postJson('/api/v1/optimisation/plans/'.$planId.'/cancel', [
            'reason' => 'Source demand is no longer active.',
        ])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_outcome_identity_and_optimistic_replacement_are_controlled(): void
    {
        [$demandId] = $this->releasedDemand('OPT-OUTCOME', '50');
        $planId = $this->approvedPlan($demandId, 'OPT-OUTCOME-001');
        $this->signIn(self::OPERATIONS);
        $detail = $this->getJson('/api/v1/optimisation/plans/'.$planId)->assertOk();
        $recommendationId = (string) $detail->json('data.recommendations.0.id');
        $otherRecommendation = (string) Str::uuid();
        $this->withHeaders($this->headers(4))->postJson(
            '/api/v1/optimisation/plans/'.$planId.'/recommendations/'.$otherRecommendation.'/outcome',
            $this->outcomePayload(),
        )->assertNotFound();

        $this->withHeaders($this->headers(4))->postJson(
            '/api/v1/optimisation/plans/'.$planId.'/recommendations/'.$recommendationId.'/outcome',
            $this->outcomePayload(),
        )->assertOk()->assertJsonPath('data.outcome_version', 1);
        $this->withHeaders($this->headers(5))->postJson(
            '/api/v1/optimisation/plans/'.$planId.'/recommendations/'.$recommendationId.'/outcome',
            $this->outcomePayload(),
        )->assertUnprocessable()->assertJsonPath(
            'error.fields.expected_outcome_version.0',
            'The current outcome version is required when replacing an observation.',
        );
        $stale = $this->outcomePayload() + ['expected_outcome_version' => 99];
        $this->withHeaders($this->headers(5))->postJson(
            '/api/v1/optimisation/plans/'.$planId.'/recommendations/'.$recommendationId.'/outcome', $stale,
        )->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
        $this->assertDatabaseHas('optimisation_outcomes', [
            'recommendation_id' => $recommendationId, 'record_version' => 1,
        ]);
    }

    private function approvedPlan(string $demandId, string $number): string
    {
        $created = $this->command()->postJson('/api/v1/optimisation/plans', $this->optimisationPayload($number, $demandId))
            ->assertCreated();
        $id = (string) $created->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/optimisation/plans/'.$id.'/generate')->assertOk();
        $this->withHeaders($this->headers(2))->postJson('/api/v1/optimisation/plans/'.$id.'/submit')->assertOk();
        $this->signIn(self::FINANCE);
        $this->withHeaders($this->headers(3))->postJson('/api/v1/optimisation/plans/'.$id.'/approve', [
            'decision_notes' => 'Independent scenario review completed.',
        ])->assertOk();

        return $id;
    }

    private function releasedDemand(string $suffix, string $quantity, bool $runMrp = false): array
    {
        $created = $this->command()->postJson('/api/v1/planning/demand', $this->demandPayload('DEMAND-'.$suffix, $quantity))
            ->assertCreated();
        $id = (string) $created->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/planning/demand/'.$id.'/release')->assertOk();
        $mrpId = null;
        if ($runMrp) {
            $mrp = $this->command()->postJson('/api/v1/planning/mrp/runs', [
                'run_number' => 'MRP-'.$suffix, 'demand_plan_id' => $id, 'run_date' => now()->toDateString(),
            ])->assertCreated();
            $mrpId = (string) $mrp->json('data.id');
        }

        return [$id, $mrpId];
    }

    private function demandPayload(string $number, string $quantity): array
    {
        return [
            'plan_number' => $number, 'name' => 'Optimisation source demand',
            'horizon_start' => now()->toDateString(), 'horizon_end' => now()->addDays(14)->toDateString(),
            'notes' => 'Controlled source for optimisation tests.',
            'lines' => [[
                'output_sku_id' => self::FINISHED_SKU, 'demand_date' => now()->addDays(7)->toDateString(),
                'demand_type' => 'FIRM', 'quantity' => $quantity, 'notes' => 'Scenario demand.',
            ]],
        ];
    }

    private function optimisationPayload(string $number, string $demandId): array
    {
        return ['plan_number' => $number, 'name' => 'Governed service and cost scenario']
            + $this->optimisationInput($demandId);
    }

    private function optimisationInput(string $demandId): array
    {
        return [
            'demand_plan_id' => $demandId, 'objective' => 'BALANCED',
            'service_level_target' => '97.5', 'safety_stock_percent' => '10',
            'planning_lead_days' => 5, 'max_utilisation_percent' => '85',
            'holding_cost_rate' => '18', 'shortage_penalty_rate' => '12',
            'currency' => 'INR',
            'assumptions' => 'Stable demand within the source horizon; no unapproved capacity reservation.',
        ];
    }

    private function outcomePayload(): array
    {
        return [
            'result' => 'PARTIAL', 'actual_stock_quantity' => '500',
            'actual_production_quantity' => '1125', 'actual_service_level' => '95',
            'actual_cost' => '13750', 'currency' => 'INR',
            'observed_on' => now()->addDays(14)->toDateString(),
            'notes' => 'Measured after the scenario horizon closed.',
        ];
    }

    private function signIn(string $userId, string $plantId = self::PLANT): void
    {
        $this->actingAs(User::query()->findOrFail($userId))
            ->withSession(['erp.company_id' => self::COMPANY, 'erp.plant_id' => $plantId]);
    }

    private function command(): static
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid());
    }

    private function headers(int $version): array
    {
        return ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $version];
    }
}
