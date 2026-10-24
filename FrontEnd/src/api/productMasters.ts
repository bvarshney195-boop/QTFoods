import { apiMutation, apiRequest } from './client';

export type ProductMasterResource = 'brands' | 'items' | 'skus' | 'recipes' | 'routes' | 'specifications';
export type ProductMasterStatus = 'DRAFT' | 'ACTIVE' | 'INACTIVE';
export type ProductMasterAction = 'UPDATE' | 'CHANGE_STATUS';

export type ReferenceOption = {
  id: string;
  code: string;
  name: string;
  status: string;
  base_uom?: string;
};

export type UomOption = { code: string; name: string; precision: number };

export type BrandAgreement = {
  id?: string;
  party_id: string;
  agreement_number: string;
  agreement_type: 'LICENSE' | 'DISTRIBUTION' | 'MANUFACTURING' | 'SUPPLY';
  effective_from: string;
  effective_to: string | null;
  currency_code: string;
  minimum_commitment: string;
  status: 'DRAFT' | 'ACTIVE' | 'EXPIRED' | 'TERMINATED';
  notes: string | null;
};

export type UomConversion = {
  id?: string;
  from_uom_code: string;
  to_uom_code: string;
  multiplier: string;
  rounding_mode: 'HALF_UP' | 'UP' | 'DOWN' | 'NONE';
};

export type SkuPack = {
  id?: string;
  code: string;
  name: string;
  uom_code: string;
  quantity: string;
  barcode: string | null;
  is_default: boolean;
};

export type RecipeComponent = {
  id?: string;
  component_sku_id: string;
  sequence_no: number;
  quantity: string;
  uom_code: string;
  waste_percent: string;
  scaling_method: 'SCALABLE' | 'FIXED' | 'PER_UNIT' | 'CONDITIONAL';
  bakers_percentage: string | null;
  is_basis_component: boolean;
  condition_code: string | null;
  quantity_per_unit: string | null;
  loss_included_in_standard: boolean;
  is_packaging: boolean;
};

export type RouteOperation = {
  id?: string;
  sequence_no: number;
  name: string;
  work_center_code: string;
  setup_minutes: string;
  run_minutes_per_unit: string;
  instructions: string | null;
};

export type SpecificationParameter = {
  id?: string;
  sequence_no: number;
  code: string;
  name: string;
  value_type: 'NUMERIC' | 'TEXT' | 'BOOLEAN';
  uom_code: string | null;
  minimum_value: string | null;
  target_value: string | null;
  maximum_value: string | null;
  text_requirement: string | null;
  test_method: string | null;
  is_required: boolean;
};

export type ProductMasterRecord = {
  id: string;
  company_id: string;
  code: string;
  name: string;
  status: ProductMasterStatus;
  record_version: number;
  child_count: number;
  child_label: string;
  parent: { code: string; name: string } | null;
  status_change: {
    reason: string | null;
    changed_at: string;
    changed_by: { id: string; name: string } | null;
  } | null;
  allowed_actions: ProductMasterAction[];
  allowed_statuses: ProductMasterStatus[];
  created_at: string;
  updated_at: string;
  description?: string | null;
  brand_id?: string | null;
  item_type?: string;
  base_uom?: string;
  shelf_life_days?: number | null;
  lot_controlled?: boolean;
  catalog_item_id?: string | null;
  barcode?: string | null;
  pack_quantity?: string;
  pack_uom_code?: string | null;
  revision?: number;
  output_sku_id?: string;
  output_quantity?: string;
  output_uom_code?: string;
  yield_percent?: string;
  basis_quantity?: string;
  basis_uom_code?: string;
  calculation_model?: 'LEGACY_YIELD' | 'WEIGHT_PIECE' | 'UNIT_CONVERSION' | 'PERCENT_YIELD';
  standard_piece_weight?: string | null;
  piece_weight_uom_code?: string | null;
  target_finished_weight?: string | null;
  target_weight_uom_code?: string | null;
  pieces_per_pack?: number;
  handling_loss_percent?: string;
  handling_loss_in_standard?: boolean;
  transformation_loss_percent?: string;
  transformation_loss_in_standard?: boolean;
  rejection_allowance_percent?: string;
  output_rounding_mode?: 'DOWN' | 'UP' | 'HALF_UP' | 'NONE';
  unit_conversion_rate?: string | null;
  effective_from?: string | null;
  effective_to?: string | null;
  notes?: string | null;
  target_type?: 'ITEM' | 'SKU';
  sku_id?: string | null;
  sampling_plan?: string | null;
};

export type ProductMasterDetail = ProductMasterRecord & {
  agreements?: BrandAgreement[];
  conversions?: UomConversion[];
  packs?: SkuPack[];
  components?: RecipeComponent[];
  operations?: RouteOperation[];
  parameters?: SpecificationParameter[];
};

export type ProductMasterLookups = {
  statuses: ProductMasterStatus[];
  item_types: string[];
  agreement_types: BrandAgreement['agreement_type'][];
  agreement_statuses: BrandAgreement['status'][];
  rounding_modes: UomConversion['rounding_mode'][];
  spec_target_types: ('ITEM' | 'SKU')[];
  spec_value_types: SpecificationParameter['value_type'][];
  uoms: UomOption[];
  brands: ReferenceOption[];
  items: ReferenceOption[];
  skus: ReferenceOption[];
  parties: ReferenceOption[];
};

export type ProductMasterWorkspace = {
  resource: ProductMasterResource;
  data: ProductMasterRecord[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
  summary: { total: number; draft: number; active: number; inactive: number };
  lookups: ProductMasterLookups;
  allowed_actions: ('CREATE')[];
};

export type RecipeCalculation = {
  recipe: { id: string; code: string; name: string; revision: number; basis_uom_code: string; output_uom_code: string };
  precision_scale: number;
  rounding_policy: string;
  calculation_model: string;
  recipe_basis_quantity: string;
  batch_basis_quantity: string;
  scaling_factor: string;
  theoretical_input_mass: string;
  theoretical_complete_units: string;
  theoretical_residual_quantity: string;
  expected_formed_units: string;
  expected_good_units_before_rounding: string;
  expected_good_units: string;
  expected_transformation_loss_quantity: string;
  transformation_loss_deducted_from_piece_count: boolean;
  exception_code: string | null;
  requirements: Array<{
    component_id: string;
    component_sku_id: string;
    component_code: string;
    component_name: string;
    scaling_method: string;
    calculated_quantity: string;
    normalised_input_mass: string;
    uom_code: string;
    is_packaging: boolean;
    condition_applied: boolean;
    loss_gross_up_applied: boolean;
  }>;
};

export type ProductMasterWrite = Record<string, unknown> & { name: string };

export type ProductMasterCommandResult = {
  entity_type: string;
  id: string;
  status: ProductMasterStatus;
  record_version: number;
  [key: `${string}_count`]: number;
};

const endpoints: Record<ProductMasterResource, string> = {
  brands: '/api/v1/master/brands',
  items: '/api/v1/master/items',
  skus: '/api/v1/master/skus',
  recipes: '/api/v1/manufacturing/recipes',
  routes: '/api/v1/manufacturing/routes',
  specifications: '/api/v1/quality/specifications',
};

export async function listProductMasters(
  resource: ProductMasterResource,
  filters: {
    page?: number;
    per_page?: number;
    q?: string;
    status?: ProductMasterStatus | '';
    type?: string;
    parent_id?: string;
    sort?: 'CODE' | 'NAME' | 'NEWEST' | 'OLDEST';
  } = {},
): Promise<ProductMasterWorkspace> {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== '') query.set(key, String(value));
  }
  return apiRequest<ProductMasterWorkspace>(`${endpoints[resource]}${query.size ? `?${query}` : ''}`);
}

export async function getProductMaster(
  resource: ProductMasterResource,
  recordId: string,
): Promise<ProductMasterDetail> {
  return (await apiRequest<{ data: ProductMasterDetail }>(`${endpoints[resource]}/${recordId}`)).data;
}

export async function createProductMaster(
  resource: ProductMasterResource,
  body: ProductMasterWrite & { code: string; status: ProductMasterStatus },
  idempotencyKey: string,
): Promise<ProductMasterCommandResult> {
  return (await apiMutation<{ data: ProductMasterCommandResult }>(endpoints[resource], body, {
    idempotencyKey,
  })).data;
}

export async function updateProductMaster(
  resource: ProductMasterResource,
  record: Pick<ProductMasterDetail, 'id' | 'record_version'>,
  body: ProductMasterWrite,
  idempotencyKey: string,
): Promise<ProductMasterCommandResult> {
  return (await apiMutation<{ data: ProductMasterCommandResult }>(`${endpoints[resource]}/${record.id}`, body, {
    expectedVersion: record.record_version,
    idempotencyKey,
  })).data;
}

export async function changeProductMasterStatus(
  resource: ProductMasterResource,
  record: Pick<ProductMasterDetail, 'id' | 'record_version'>,
  targetStatus: ProductMasterStatus,
  reason: string,
  idempotencyKey: string,
): Promise<ProductMasterCommandResult> {
  return (await apiMutation<{ data: ProductMasterCommandResult }>(`${endpoints[resource]}/${record.id}/status`, {
    target_status: targetStatus,
    reason,
  }, {
    expectedVersion: record.record_version,
    idempotencyKey,
  })).data;
}

export async function deleteProductMaster(
  resource: ProductMasterResource,
  record: Pick<ProductMasterDetail, 'id' | 'record_version'>,
  idempotencyKey: string,
): Promise<Omit<ProductMasterCommandResult, 'status'> & { status: 'DELETED'; deleted: true }> {
  return (await apiMutation<{ data: Omit<ProductMasterCommandResult, 'status'> & { status: 'DELETED'; deleted: true } }>(`${endpoints[resource]}/${record.id}/delete`, {
    confirmed: true,
  }, {
    expectedVersion: record.record_version,
    idempotencyKey,
  })).data;
}

export async function previewRecipe(recordId: string, body: unknown): Promise<RecipeCalculation> {
  return (await apiMutation<{ data: RecipeCalculation }>(`${endpoints.recipes}/${recordId}/preview`, body)).data;
}
