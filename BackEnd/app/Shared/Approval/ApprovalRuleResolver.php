<?php

namespace App\Shared\Approval;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApprovalRuleResolver
{
    public function resolve(
        string $ruleCode,
        string $authorityValue,
        string $companyId,
        ?string $plantId,
    ): array {
        if (bccomp($authorityValue, '0', 6) < 0) {
            throw ValidationException::withMessages([
                'authority_value' => ['Approval authority value cannot be negative.'],
            ]);
        }

        $rule = DB::table('approval_rules')
            ->where('code', $ruleCode)
            ->where('status', 'ACTIVE')
            ->where(function ($query) use ($companyId, $plantId) {
                $query->where(fn ($scope) => $scope
                    ->whereNull('company_id')->whereNull('plant_id'))
                    ->orWhere(fn ($scope) => $scope
                        ->where('company_id', $companyId)->whereNull('plant_id'));
                if ($plantId !== null) {
                    $query->orWhere(fn ($scope) => $scope
                        ->where('company_id', $companyId)->where('plant_id', $plantId));
                }
            })
            ->orderByRaw('CASE WHEN plant_id IS NOT NULL THEN 0 WHEN company_id IS NOT NULL THEN 1 ELSE 2 END')
            ->first();

        if (! $rule) {
            throw ValidationException::withMessages([
                'approval_rule' => ["No active {$ruleCode} approval rule covers this company and plant."],
            ]);
        }

        $band = DB::table('approval_rule_bands')
            ->where('approval_rule_id', $rule->id)
            ->where('minimum_value', '<=', $authorityValue)
            ->where(fn ($query) => $query
                ->whereNull('maximum_value')
                ->orWhere('maximum_value', '>', $authorityValue))
            ->orderBy('sequence')
            ->first();

        if (! $band) {
            throw ValidationException::withMessages([
                'authority_value' => [
                    "The active {$ruleCode} rule has no authority band for {$authorityValue} {$rule->authority_uom}.",
                ],
            ]);
        }

        return [
            'rule_id' => (string) $rule->id,
            'rule_version' => (int) $rule->record_version,
            'rule_name' => $rule->name,
            'entity_type' => $rule->entity_type,
            'authority_metric' => $rule->authority_metric,
            'authority_uom' => $rule->authority_uom,
            'band_id' => (string) $band->id,
            'band_name' => $band->name,
            'required_permission' => $band->required_permission,
            'escalation_permission' => $band->escalation_permission,
            'work_priority' => $band->work_priority,
            'due_hours' => (int) $band->due_hours,
            'escalate_after_hours' => (int) $band->escalate_after_hours,
        ];
    }
}
