<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ExperienceOperationsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const ADMIN_USER_ID = '00000000-0000-4000-8000-000000000204';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::query()->findOrFail(self::ADMIN_USER_ID))->withSession([
            'erp.company_id' => self::COMPANY_ID,
            'erp.plant_id' => self::PLANT_ID,
        ]);
    }

    public function test_csv_preview_reports_shape_duplicates_and_domain_errors_without_a_server_error(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'parties.csv',
            "code,display_name,party_kind\nCSV-ONE,First party,ORGANISATION,unexpected\nCSV-ONE,Duplicate party,UNKNOWN\n",
        );

        $response = $this->withHeader('Accept', 'application/json')->post('/api/v1/experience/imports/preview', [
            'entity_type' => 'PARTIES',
            'file' => $file,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'INVALID')
            ->assertJsonPath('data.row_count', 2);

        $errors = collect($response->json('data.errors'));
        $this->assertTrue($errors->contains(fn (array $error): bool => $error['row'] === 2 && $error['field'] === '_row'));
        $this->assertTrue($errors->contains(fn (array $error): bool => $error['row'] === 3 && $error['field'] === 'code'));
        $this->assertTrue($errors->contains(fn (array $error): bool => $error['row'] === 3 && $error['field'] === 'party_kind'));

        $batchId = (string) $response->json('data.id');
        $this->postJson('/api/v1/experience/imports/'.$batchId.'/commit')
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.batch.0', 'Only a validated import batch can be committed.');
        $this->assertDatabaseMissing('parties', ['code' => 'CSV-ONE']);
    }

    public function test_csv_import_normalises_valid_drafts_and_authorised_rollback_removes_them(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'parties.csv',
            "code,display_name,legal_name,party_kind,notes\nimport-party-01,Imported Party,,organisation,Created by controlled import\n",
        );

        $preview = $this->withHeader('Accept', 'application/json')->post('/api/v1/experience/imports/preview', [
            'entity_type' => 'PARTIES',
            'file' => $file,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'VALID')
            ->assertJsonPath('data.error_count', 0)
            ->assertJsonPath('data.preview.0.code', 'IMPORT-PARTY-01')
            ->assertJsonPath('data.preview.0.party_kind', 'ORGANISATION');

        $batchId = (string) $preview->json('data.id');
        $this->postJson('/api/v1/experience/imports/'.$batchId.'/commit')
            ->assertOk()
            ->assertJsonPath('data.status', 'COMMITTED')
            ->assertJsonPath('data.created_count', 1);

        $party = DB::table('parties')->where('company_id', self::COMPANY_ID)->where('code', 'IMPORT-PARTY-01')->first();
        $this->assertNotNull($party);
        $this->assertSame('Imported Party', $party->legal_name);
        $this->assertSame('DRAFT', $party->status);

        $this->postJson('/api/v1/experience/imports/'.$batchId.'/rollback')
            ->assertOk()
            ->assertJsonPath('data.status', 'ROLLED_BACK')
            ->assertJsonPath('data.deleted_count', 1);
        $this->assertDatabaseMissing('parties', ['id' => $party->id]);
        $this->assertDatabaseHas('bulk_import_batches', ['id' => $batchId, 'status' => 'ROLLED_BACK']);
    }

    public function test_item_csv_rejects_unknown_domain_values_and_normalises_valid_drafts(): void
    {
        $invalidFile = UploadedFile::fake()->createWithContent(
            'invalid-items.csv',
            "code,name,item_type,base_uom\nIMPORT-ITEM-INVALID,Invalid Item,UNKNOWN,NO_SUCH_UOM\n",
        );
        $invalid = $this->withHeader('Accept', 'application/json')->post('/api/v1/experience/imports/preview', [
            'entity_type' => 'ITEMS',
            'file' => $invalidFile,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'INVALID')
            ->assertJsonPath('data.error_count', 2);
        $invalidErrors = collect($invalid->json('data.errors'));
        $this->assertTrue($invalidErrors->contains(fn (array $error): bool => $error['field'] === 'item_type'));
        $this->assertTrue($invalidErrors->contains(fn (array $error): bool => $error['field'] === 'base_uom'));

        $validFile = UploadedFile::fake()->createWithContent(
            'valid-items.csv',
            "code,name,item_type,base_uom\nimport-item-01,Imported Ingredient,raw_material,kg\n",
        );
        $preview = $this->withHeader('Accept', 'application/json')->post('/api/v1/experience/imports/preview', [
            'entity_type' => 'ITEMS',
            'file' => $validFile,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'VALID')
            ->assertJsonPath('data.preview.0.code', 'IMPORT-ITEM-01')
            ->assertJsonPath('data.preview.0.item_type', 'RAW_MATERIAL')
            ->assertJsonPath('data.preview.0.base_uom', 'KG');
        $batchId = (string) $preview->json('data.id');

        $this->postJson('/api/v1/experience/imports/'.$batchId.'/commit')
            ->assertOk()->assertJsonPath('data.created_count', 1);
        $this->assertDatabaseHas('items', [
            'company_id' => self::COMPANY_ID,
            'code' => 'IMPORT-ITEM-01',
            'item_type' => 'RAW_MATERIAL',
            'base_uom' => 'KG',
            'status' => 'DRAFT',
        ]);
        $this->postJson('/api/v1/experience/imports/'.$batchId.'/rollback')
            ->assertOk()->assertJsonPath('data.deleted_count', 1);
        $this->assertDatabaseMissing('items', ['company_id' => self::COMPANY_ID, 'code' => 'IMPORT-ITEM-01']);
    }
}
