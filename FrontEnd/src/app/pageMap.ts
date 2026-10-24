import ACC_LOGIN from '../pages/ACC_LOGIN';
import ACC_CTX from '../pages/ACC_CTX';
import WRK_HOME from '../pages/WRK_HOME';
import ADM_ORG from '../pages/ADM_ORG';
import ADM_LOC from '../pages/ADM_LOC';
import ADM_USER from '../pages/ADM_USER';
import ADM_ROLE from '../pages/ADM_ROLE';
import ADM_RULE from '../pages/ADM_RULE';
import ADM_AUD from '../pages/ADM_AUD';
import ADM_INT from '../pages/ADM_INT';
import ADM_HELP from '../pages/ADM_HELP';
import BI_REP from '../pages/BI_REP';
import MD_PARTY from '../pages/MD_PARTY';
import MD_BRAND from '../pages/MD_BRAND';
import MD_ITEM from '../pages/MD_ITEM';
import MD_SKU from '../pages/MD_SKU';
import PUR_REQ from '../pages/PUR_REQ';
import PUR_RFQ from '../pages/PUR_RFQ';
import PUR_PO from '../pages/PUR_PO';
import INB_GATE from '../pages/INB_GATE';
import INB_GRN from '../pages/INB_GRN';
import QC_IN from '../pages/QC_IN';
import INB_RETURN from '../pages/INB_RETURN';
import INV_STK from '../pages/INV_STK';
import INV_ISS from '../pages/INV_ISS';
import INV_TRF from '../pages/INV_TRF';
import INV_COUNT from '../pages/INV_COUNT';
import INV_EXP from '../pages/INV_EXP';
import MD_REC from '../pages/MD_REC';
import MD_ROUTE from '../pages/MD_ROUTE';
import MD_SPEC from '../pages/MD_SPEC';
import PLAN_DEM from '../pages/PLAN_DEM';
import PLAN_MRP from '../pages/PLAN_MRP';
import PLAN_SCH from '../pages/PLAN_SCH';
import PRO_ORDER from '../pages/PRO_ORDER';
import PRO_STAGE from '../pages/PRO_STAGE';
import PRO_LOSS from '../pages/PRO_LOSS';
import QC_LAB from '../pages/QC_LAB';
import QC_SAFE from '../pages/QC_SAFE';
import PACK_ART from '../pages/PACK_ART';
import PACK_RUN from '../pages/PACK_RUN';
import FG_LOT from '../pages/FG_LOT';
import TRACE_CASE from '../pages/TRACE_CASE';
import COST_BATCH from '../pages/COST_BATCH';
import CRM_LEAD from '../pages/CRM_LEAD';
import CRM_PRICE from '../pages/CRM_PRICE';
import CRM_ORDER from '../pages/CRM_ORDER';
import CON_WORK from '../pages/CON_WORK';
import DSP_PICK from '../pages/DSP_PICK';
import DSP_LOAD from '../pages/DSP_LOAD';
import DSP_POD from '../pages/DSP_POD';
import RET_CASE from '../pages/RET_CASE';
import RET_UNSOLD from '../pages/RET_UNSOLD';
import FIN_AR from '../pages/FIN_AR';
import BI_PROFIT from '../pages/BI_PROFIT';
import FIN_AP from '../pages/FIN_AP';
import FIN_EXP from '../pages/FIN_EXP';
import FIN_GL from '../pages/FIN_GL';
import COST_OH from '../pages/COST_OH';
import ASSET_REG from '../pages/ASSET_REG';
import HR_PAY from '../pages/HR_PAY';
import ENG_MNT from '../pages/ENG_MNT';
import SCALE_PLANT from '../pages/SCALE_PLANT';
import PORTAL_EXT from '../pages/PORTAL_EXT';
import OPT_PLAN from '../pages/OPT_PLAN';
import FIN_SIM from '../pages/FIN_SIM';
import FIN_ADJ from '../pages/FIN_ADJ';
import FIN_LEGACY from '../pages/FIN_LEGACY';
import FIN_ARCH from '../pages/FIN_ARCH';
import FIN_OPEN from '../pages/FIN_OPEN';
import FIN_SUP from '../pages/FIN_SUP';

export const pageMap = {
  'ACC-LOGIN': ACC_LOGIN,
  'ACC-CTX': ACC_CTX,
  'WRK-HOME': WRK_HOME,
  'ADM-ORG': ADM_ORG,
  'ADM-LOC': ADM_LOC,
  'ADM-USER': ADM_USER,
  'ADM-ROLE': ADM_ROLE,
  'ADM-RULE': ADM_RULE,
  'ADM-AUD': ADM_AUD,
  'ADM-INT': ADM_INT,
  'ADM-HELP': ADM_HELP,
  'BI-REP': BI_REP,
  'MD-PARTY': MD_PARTY,
  'MD-BRAND': MD_BRAND,
  'MD-ITEM': MD_ITEM,
  'MD-SKU': MD_SKU,
  'PUR-REQ': PUR_REQ,
  'PUR-RFQ': PUR_RFQ,
  'PUR-PO': PUR_PO,
  'INB-GATE': INB_GATE,
  'INB-GRN': INB_GRN,
  'QC-IN': QC_IN,
  'INB-RETURN': INB_RETURN,
  'INV-STK': INV_STK,
  'INV-ISS': INV_ISS,
  'INV-TRF': INV_TRF,
  'INV-COUNT': INV_COUNT,
  'INV-EXP': INV_EXP,
  'MD-REC': MD_REC,
  'MD-ROUTE': MD_ROUTE,
  'MD-SPEC': MD_SPEC,
  'PLAN-DEM': PLAN_DEM,
  'PLAN-MRP': PLAN_MRP,
  'PLAN-SCH': PLAN_SCH,
  'PRO-ORDER': PRO_ORDER,
  'PRO-STAGE': PRO_STAGE,
  'PRO-LOSS': PRO_LOSS,
  'QC-LAB': QC_LAB,
  'QC-SAFE': QC_SAFE,
  'PACK-ART': PACK_ART,
  'PACK-RUN': PACK_RUN,
  'FG-LOT': FG_LOT,
  'TRACE-CASE': TRACE_CASE,
  'COST-BATCH': COST_BATCH,
  'CRM-LEAD': CRM_LEAD,
  'CRM-PRICE': CRM_PRICE,
  'CRM-ORDER': CRM_ORDER,
  'CON-WORK': CON_WORK,
  'DSP-PICK': DSP_PICK,
  'DSP-LOAD': DSP_LOAD,
  'DSP-POD': DSP_POD,
  'RET-CASE': RET_CASE,
  'RET-UNSOLD': RET_UNSOLD,
  'FIN-AR': FIN_AR,
  'BI-PROFIT': BI_PROFIT,
  'FIN-AP': FIN_AP,
  'FIN-EXP': FIN_EXP,
  'FIN-GL': FIN_GL,
  'COST-OH': COST_OH,
  'ASSET-REG': ASSET_REG,
  'HR-PAY': HR_PAY,
  'ENG-MNT': ENG_MNT,
  'SCALE-PLANT': SCALE_PLANT,
  'PORTAL-EXT': PORTAL_EXT,
  'OPT-PLAN': OPT_PLAN,
  'FIN-SIM': FIN_SIM,
  'FIN-ADJ': FIN_ADJ,
  'FIN-LEGACY': FIN_LEGACY,
  'FIN-ARCH': FIN_ARCH,
  'FIN-OPEN': FIN_OPEN,
  'FIN-SUP': FIN_SUP,
} as const;
