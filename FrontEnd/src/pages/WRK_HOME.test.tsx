import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { WorkItem, WorkQueue } from '../api/workQueue';
import { ErpSessionContext } from '../app/ErpSessionContext';
import { makeSession } from '../test/unsoldReturnFixtures';
import WRK_HOME from './WRK_HOME';

const apiMocks = vi.hoisted(() => ({
  listWorkItems: vi.fn(),
  claimWorkItem: vi.fn(),
  completeWorkItem: vi.fn(),
}));

vi.mock('../api/workQueue', async () => {
  const actual = await vi.importActual<typeof import('../api/workQueue')>(
    '../api/workQueue'
  );

  return { ...actual, ...apiMocks };
});

describe('WRK_HOME', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    window.history.replaceState(null, '', '#WRK-HOME');
  });

  it('renders persisted counters and drills into the exact workflow record', async () => {
    apiMocks.listWorkItems.mockResolvedValue(makeQueue([makeApproval()]));

    renderWorkspace();

    expect(await screen.findByText('Review unsold return loss disposition')).toBeInTheDocument();
    expect(screen.getByText('Pending approvals').parentElement).toHaveTextContent('1');
    expect(screen.getByText('Overdue', { selector: '.kpi > span' }).parentElement).toHaveTextContent('1');

    await userEvent.setup().click(screen.getByRole('button', { name: 'Open' }));

    expect(window.location.hash).toBe(
      '#RET-UNSOLD?record=00000000-0000-4000-8000-000000009001'
    );
  });

  it('claims unassigned work with its current version and refreshes ownership', async () => {
    const openTask = makeTask();
    const claimedTask: WorkItem = {
      ...openTask,
      assignee: {
        id: '00000000-0000-4000-8000-000000000203',
        name: 'Demo Finance Manager',
        email: 'finance.user@qtfoods.local',
      },
      record_version: 2,
      allowed_actions: ['COMPLETE'],
    };
    apiMocks.listWorkItems
      .mockResolvedValueOnce(makeQueue([openTask]))
      .mockResolvedValue(makeQueue([claimedTask], { assigned_to_me: 1, unassigned: 0 }));
    apiMocks.claimWorkItem.mockResolvedValue({
      id: openTask.id,
      kind: 'TASK',
      status: 'OPEN',
      assigned_user_id: claimedTask.assignee!.id,
      completed_by: null,
      completed_at: null,
      record_version: 2,
    });

    renderWorkspace();
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Claim' }));

    await waitFor(() => {
      expect(apiMocks.claimWorkItem).toHaveBeenCalledWith(
        openTask.id,
        1,
        expect.any(String)
      );
    });
    expect(await screen.findByText('Demo Finance Manager')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('is now assigned to you');
    expect(screen.getByRole('button', { name: 'Complete' })).toBeEnabled();
  });

  it('completes owned task work through the versioned command', async () => {
    const task = {
      ...makeTask(),
      assignee: {
        id: '00000000-0000-4000-8000-000000000203',
        name: 'Demo Finance Manager',
        email: 'finance.user@qtfoods.local',
      },
      allowed_actions: ['COMPLETE'] as WorkItem['allowed_actions'],
    };
    apiMocks.listWorkItems
      .mockResolvedValueOnce(makeQueue([task], { assigned_to_me: 1, unassigned: 0 }))
      .mockResolvedValue(makeQueue([]));
    apiMocks.completeWorkItem.mockResolvedValue({
      id: task.id,
      kind: 'TASK',
      status: 'COMPLETED',
      assigned_user_id: task.assignee.id,
      completed_by: task.assignee.id,
      completed_at: '2026-09-09T09:00:00.000Z',
      record_version: 2,
    });

    renderWorkspace();
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Complete' }));

    await waitFor(() => {
      expect(apiMocks.completeWorkItem).toHaveBeenCalledWith(
        task.id,
        1,
        expect.any(String)
      );
    });
    expect(await screen.findByText('You are all caught up')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('was completed');
  });
});

function renderWorkspace() {
  return render(
    <ErpSessionContext.Provider
      value={makeSession([
        'ACTION:WRK-HOME:CLAIM',
        'ACTION:WRK-HOME:COMPLETE',
      ])}
    >
      <WRK_HOME />
    </ErpSessionContext.Provider>
  );
}

function makeApproval(): WorkItem {
  return {
    ...baseItem(),
    kind: 'APPROVAL',
    title: 'Review unsold return loss disposition',
    description: 'Review the controlled transaction.',
    priority: 'HIGH',
    source: {
      type: 'approval_request',
      id: '00000000-0000-4000-8000-000000008001',
    },
    target: {
      screen_code: 'RET-UNSOLD',
      record_id: '00000000-0000-4000-8000-000000009001',
      href: 'RET-UNSOLD?record=00000000-0000-4000-8000-000000009001',
    },
    is_overdue: true,
    age_minutes: 1800,
    age_bucket: 'ONE_TO_THREE_DAYS',
    allowed_actions: ['CLAIM', 'OPEN'],
  };
}

function makeTask(): WorkItem {
  return {
    ...baseItem(),
    kind: 'TASK',
    title: 'Check settlement reference',
    description: 'Verify the posted reference before close.',
    priority: 'NORMAL',
    allowed_actions: ['CLAIM'],
  };
}

function baseItem(): WorkItem {
  return {
    id: '00000000-0000-4000-8000-000000007001',
    kind: 'TASK',
    title: '',
    description: null,
    priority: 'NORMAL',
    status: 'OPEN',
    assignee: null,
    source: null,
    target: null,
    due_at: '2026-09-09T08:00:00.000Z',
    is_overdue: false,
    age_minutes: 90,
    age_bucket: 'UNDER_4_HOURS',
    creator: {
      id: '00000000-0000-4000-8000-000000000202',
      name: 'Demo Operations Manager',
    },
    completed_at: null,
    completed_by: null,
    completion_note: null,
    record_version: 1,
    allowed_actions: [],
    created_at: '2026-09-09T06:30:00.000Z',
    updated_at: '2026-09-09T06:30:00.000Z',
  };
}

function makeQueue(
  data: WorkItem[],
  summaryOverrides: Partial<WorkQueue['summary']> = {}
): WorkQueue {
  return {
    data,
    summary: {
      open_total: data.length,
      assigned_to_me: 0,
      unassigned: data.length,
      approvals: data.filter((item) => item.kind === 'APPROVAL').length,
      exceptions: data.filter((item) => item.kind === 'EXCEPTION').length,
      overdue: data.filter((item) => item.is_overdue).length,
      high_priority: data.filter((item) => ['URGENT', 'HIGH'].includes(item.priority)).length,
      older_than_three_days: 0,
      created_7d: data.length,
      completed_7d: 0,
      closure_rate_7d: 0,
      ...summaryOverrides,
    },
    meta: {
      current_page: 1,
      per_page: 50,
      total: data.length,
      last_page: 1,
      from: data.length ? 1 : null,
      to: data.length || null,
    },
    links: { first: '', last: '', prev: null, next: null },
  };
}
