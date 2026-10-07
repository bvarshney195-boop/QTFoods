export type ErpContext = {
  company_id: string;
  company_name: string;
  plant_id: string | null;
  plant_name: string | null;
};

export type ErpUser = {
  id: string;
  name: string;
  email: string;
};

export type ErpSession = {
  user: ErpUser;
  security?: {
    email_verified: boolean;
    mfa_enabled: boolean;
    mfa_required: boolean;
    password_change_required: boolean;
    password_changed_at: string | null;
    last_login_at: string | null;
    current_session_id: string | null;
  };
  authentication?: {
    device_session_id: string;
    mfa_method: 'AUTHENTICATOR' | 'RECOVERY_CODE' | null;
  };
  roles: string[];
  allowed_screens: string[];
  allowed_actions: string[];
  delegated_authorities?: Array<{
    id: string;
    permission_code: string;
    delegator: { id: string; name: string };
    effective_from: string;
    effective_to: string;
    reason: string;
  }>;
  contexts: ErpContext[];
  selected_context: ErpContext | null;
};
