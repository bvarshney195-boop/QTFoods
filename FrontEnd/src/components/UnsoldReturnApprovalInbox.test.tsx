import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ErpSessionContext } from '../app/ErpSessionContext';
import {
  APPROVAL_ID,
  makeApprovalDecisionResult,
  makeApprovalDetail,
  makeApprovalList,
  makeSession,
} from '../test/unsoldReturnFixtures';
import { UnsoldReturnApprovalInbox } from './UnsoldReturnApprovalInbox';

const apiMocks = vi.hoisted(() => ({
  listUnsoldReturnApprovals: vi.fn(),
  getUnsoldReturnApproval: vi.fn(),
  approveUnsoldReturnApproval: vi.fn(),
  rejectUnsoldReturnApproval: vi.fn(),
}));

vi.mock('../api/unsoldReturns', async () => {
  const actual = await vi.importActual<typeof import('../api/unsoldReturns')>(
    '../api/unsoldReturns'
  );

  return {
    ...actual,
    ...apiMocks,
  };
});

describe('UnsoldReturnApprovalInbox', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    const detail = makeApprovalDetail();
    apiMocks.listUnsoldReturnApprovals.mockResolvedValue(makeApprovalList(detail));
    apiMocks.getUnsoldReturnApproval.mockResolvedValue(detail);
    apiMocks.approveUnsoldReturnApproval.mockResolvedValue(
      makeApprovalDecisionResult()
    );
  });

  it('submits a maker-checker approval with the current approval version', async () => {
    const user = userEvent.setup();
    const onDecision = vi.fn();

    render(
      <ErpSessionContext.Provider
        value={makeSession(['ACTION:RET-UNSOLD:APPROVE'])}
      >
        <UnsoldReturnApprovalInbox
          refreshToken={0}
          onOpenCase={vi.fn()}
          onDecision={onDecision}
        />
      </ErpSessionContext.Provider>
    );

    const approveButton = await screen.findByRole('button', {
      name: 'Approve disposition',
    });
    expect(
      screen.getByRole('button', {
        name: /Submission 1.*4 destroy.*Standard loss authority/,
      })
    ).toBeInTheDocument();
    expect(
      screen.getByText(/Submitted by Demo Operations Manager/)
    ).toBeInTheDocument();
    expect(screen.getByText('Total destroy').parentElement).toHaveTextContent('4');

    await user.type(
      screen.getByLabelText('Reviewer note / rejection reason'),
      'Reviewed by Finance'
    );
    await user.click(approveButton);

    await waitFor(() => {
      expect(apiMocks.approveUnsoldReturnApproval).toHaveBeenCalledWith(
        APPROVAL_ID,
        'Reviewed by Finance',
        1,
        expect.any(String)
      );
    });
    expect(onDecision).toHaveBeenCalledWith(makeApprovalDecisionResult());
    expect(screen.getByRole('status')).toHaveTextContent(
      /Disposition approved/
    );
  });

  it('disables decision controls when maker-checker rules reject the reviewer', async () => {
    const detail = makeApprovalDetail(false);
    apiMocks.listUnsoldReturnApprovals.mockResolvedValue(makeApprovalList(detail));
    apiMocks.getUnsoldReturnApproval.mockResolvedValue(detail);

    render(
      <ErpSessionContext.Provider
        value={makeSession(['ACTION:RET-UNSOLD:APPROVE'])}
      >
        <UnsoldReturnApprovalInbox
          refreshToken={0}
          onOpenCase={vi.fn()}
          onDecision={vi.fn()}
        />
      </ErpSessionContext.Provider>
    );

    expect(
      await screen.findByText(/Makers cannot review their own disposition/)
    ).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Approve disposition' })
    ).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Reject to Quality' })).toBeDisabled();
    expect(apiMocks.approveUnsoldReturnApproval).not.toHaveBeenCalled();
  });
});
