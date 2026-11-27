<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class ExperienceExcelEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = '00000000-0000-4000-8000-000000000001';
    private const PLANT = '00000000-0000-4000-8000-000000000101';
    private const ADMIN = '00000000-0000-4000-8000-000000000204';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::query()->findOrFail(self::ADMIN))->withSession([
            'erp.company_id' => self::COMPANY,
            'erp.plant_id' => self::PLANT,
        ]);
    }

    public function test_crm_parties_import_from_excel_commit_export_and_rollback(): void
    {
        $file = $this->workbook('crm-parties.xlsx', [
            ['Code', 'Display Name', 'Legal Name', 'Party Kind', 'Role Codes', 'Contact Name', 'Contact Email', 'Contact Phone', 'Notes'],
            ['CRM-EXCEL-001', 'Excel Trading Partner', 'Excel Trading Partner Pvt Ltd', 'ORGANISATION', 'CUSTOMER|SUPPLIER', 'Anita Rao', 'anita@example.test', '9876543210', 'Imported workbook record.'],
        ]);
        $preview = $this->withHeader('Accept', 'application/json')->post('/api/v1/experience/imports/preview', [
            'entity_type' => 'PARTIES', 'file' => $file,
        ])->assertCreated()->assertJsonPath('data.status', 'VALID')
            ->assertJsonPath('data.row_count', 1)->assertJsonPath('data.error_count', 0);
        $batchId = (string) $preview->json('data.id');

        $this->postJson('/api/v1/experience/imports/'.$batchId.'/commit')->assertOk()
            ->assertJsonPath('data.status', 'COMMITTED')->assertJsonPath('data.created_count', 1);
        $partyId = (string) DB::table('parties')->where('company_id', self::COMPANY)
            ->where('code', 'CRM-EXCEL-001')->value('id');
        $this->assertNotSame('', $partyId);
        $this->assertSame(['CUSTOMER', 'SUPPLIER'], DB::table('party_roles')->where('party_id', $partyId)
            ->orderBy('role_code')->pluck('role_code')->all());
        $this->assertDatabaseHas('party_contacts', [
            'party_id' => $partyId, 'name' => 'Anita Rao', 'email' => 'anita@example.test', 'is_primary' => true,
        ]);

        $export = $this->get('/api/v1/experience/exports/parties')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'qt-crm-export-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, $export->streamedContent());
            $rows = IOFactory::load($path)->getActiveSheet()->toArray('', false, true, false);
        } finally {
            @unlink($path);
        }
        $exported = collect($rows)->first(fn (array $row): bool => ($row[0] ?? null) === 'CRM-EXCEL-001');
        $this->assertNotNull($exported);
        $this->assertSame('CUSTOMER|SUPPLIER', $exported[4]);
        $this->assertSame('anita@example.test', $exported[6]);

        $this->postJson('/api/v1/experience/imports/'.$batchId.'/rollback')->assertOk()
            ->assertJsonPath('data.status', 'ROLLED_BACK')->assertJsonPath('data.deleted_count', 1);
        $this->assertDatabaseMissing('parties', ['id' => $partyId]);
        $this->assertDatabaseMissing('party_roles', ['party_id' => $partyId]);
    }

    public function test_legacy_xls_party_workbook_is_accepted_and_seller_alias_maps_to_supplier(): void
    {
        $file = $this->workbook('legacy-parties.xls', [
            ['Code', 'Display Name', 'Party Kind', 'Role Codes'],
            ['CRM-XLS-SELLER', 'Legacy Seller', 'ORGANISATION', 'SELLER'],
        ], true);
        $preview = $this->withHeader('Accept', 'application/json')->post('/api/v1/experience/imports/preview', [
            'entity_type' => 'PARTIES', 'file' => $file,
        ])->assertCreated()->assertJsonPath('data.status', 'VALID')->assertJsonPath('data.error_count', 0);

        $this->postJson('/api/v1/experience/imports/'.$preview->json('data.id').'/commit')->assertOk();
        $partyId = DB::table('parties')->where('code', 'CRM-XLS-SELLER')->value('id');
        $this->assertDatabaseHas('party_roles', ['party_id' => $partyId, 'role_code' => 'SUPPLIER']);
    }

    private function workbook(string $name, array $rows, bool $legacy = false): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows);
        ob_start();
        ($legacy ? new Xls($spreadsheet) : new Xlsx($spreadsheet))->save('php://output');
        $content = (string) ob_get_clean();
        $spreadsheet->disconnectWorksheets();

        return UploadedFile::fake()->createWithContent($name, $content);
    }
}
