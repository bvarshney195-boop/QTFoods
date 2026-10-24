import { createContext, useContext } from 'react';
import type { ErpSession } from '../types/session';

export const ErpSessionContext = createContext<ErpSession | null>(null);

export function useErpSession(): ErpSession {
  const session = useContext(ErpSessionContext);

  if (!session) {
    throw new Error('ERP session context is unavailable.');
  }

  return session;
}
