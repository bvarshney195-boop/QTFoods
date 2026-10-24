import { useState } from 'react';
import { AccountsPayableWorkspace } from '../components/AccountsPayableWorkspace';
import { PayablesIntegrationWorkspace } from '../components/FinanceP2Workspaces';

export default function FIN_AP() {
  const [area, setArea] = useState<'payables' | 'integrations'>('payables');
  return <>
    <div className="workspace-tabs fin-ap-area-tabs" aria-label="Accounts payable areas">
      <button type="button" className={area === 'payables' ? 'active' : ''} onClick={() => setArea('payables')}>Invoices & payments</button>
      <button type="button" className={area === 'integrations' ? 'active' : ''} onClick={() => setArea('integrations')}>Bank & statutory integrations</button>
    </div>
    {area === 'payables' ? <AccountsPayableWorkspace /> : <PayablesIntegrationWorkspace />}
  </>;
}
