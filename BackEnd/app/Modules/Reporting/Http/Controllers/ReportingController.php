<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Modules\Reporting\Application\ReportDefinitionCatalog;
use App\Modules\Reporting\Application\ReportingQuery;
use App\Modules\Reporting\Application\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ReportingController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly ReportingQuery $query,
        private readonly ReportingService $service,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'report_code' => ['nullable', Rule::in(ReportDefinitionCatalog::CODES)],
        ]);

        return response()->json($this->query->workspace(
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
            $filters,
        ));
    }

    public function show(string $runId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->detail(
            $runId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
        )]);
    }

    public function generate(Request $request): JsonResponse
    {
        $input = $request->all();
        if (is_string($input['run_number'] ?? null)) $input['run_number'] = Str::upper(trim($input['run_number']));
        if (is_string($input['report_code'] ?? null)) $input['report_code'] = Str::upper(trim($input['report_code']));
        $request->replace($input);
        $validated = $request->validate([
            'run_number' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'],
            'report_code' => ['required', Rule::in(ReportDefinitionCatalog::CODES)],
            'as_of_date' => ['required', 'date_format:Y-m-d'],
            'parameters' => ['nullable', 'array:include_zero,include_settled,include_closed'],
            'parameters.include_zero' => ['nullable', 'boolean'],
            'parameters.include_settled' => ['nullable', 'boolean'],
            'parameters.include_closed' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $this->service->generate(
            $validated + $this->commandContext($request, false),
        )], 201);
    }

    public function createExport(string $runId, Request $request): JsonResponse
    {
        $input = $request->all();
        if (is_string($input['format'] ?? null)) $input['format'] = Str::upper(trim($input['format']));
        $request->replace($input);
        $validated = $request->validate(['format' => ['required', Rule::in(['CSV', 'JSON'])]]);

        return response()->json(['data' => $this->service->createExport(
            $runId,
            $validated['format'],
            $this->commandContext($request, false),
        )], 201);
    }

    public function download(string $exportId, Request $request): Response
    {
        $export = $this->query->export(
            $exportId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
        );

        return response((string) $export->payload_text, 200, [
            'Content-Type' => (string) $export->mime_type,
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', (string) $export->file_name).'"',
            'Content-Length' => (string) $export->size_bytes,
            'X-Content-SHA256' => (string) $export->sha256,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }
}
