import { useCallback, useEffect, useMemo, useState } from 'react';

const routeChangeEvent = 'erp:register-route-change';

export type EditCardRoute =
  | { mode: 'register'; recordId: null }
  | { mode: 'create'; recordId: null }
  | { mode: 'edit'; recordId: string };

type RegisterStateValue = string | number | boolean | null | undefined;

export function readRegisterRouteParams(screenCode: string): URLSearchParams {
  const { screen, parameters } = currentHashRoute();
  return screen === screenCode ? parameters : new URLSearchParams();
}

export function readRouteString(parameters: URLSearchParams, key: string, fallback = ''): string {
  return parameters.get(key)?.trim() || fallback;
}

export function readRoutePage(parameters: URLSearchParams): number {
  const page = Number(parameters.get('page'));
  return Number.isSafeInteger(page) && page > 0 ? page : 1;
}

export function readRouteChoice<T extends string>(
  parameters: URLSearchParams,
  key: string,
  choices: readonly T[],
  fallback: T,
): T {
  const value = parameters.get(key) as T | null;
  return value && choices.includes(value) ? value : fallback;
}

export function revealEditCard(element: HTMLElement | null): void {
  element?.scrollIntoView?.({ block: 'start' });
  element?.querySelector<HTMLElement>('h2[tabindex], h3[tabindex]')?.focus({ preventScroll: true });
}

export function revealRegister(element: HTMLElement | null): void {
  element?.scrollIntoView?.({ block: 'start' });
  element?.querySelector<HTMLElement>('input, select, button')?.focus({ preventScroll: true });
}

/**
 * Adds record-aware navigation to the application's existing hash router.
 * Register filters remain in the URL, so browser Back, direct links and reloads
 * restore the same search/sort/page before reopening an edit card by database ID.
 */
export function useEditCardNavigation(
  screenCode: string,
  registerState: Record<string, RegisterStateValue>,
) {
  const stateKey = useMemo(() => serialiseState(registerState), [registerState]);
  const [route, setRoute] = useState<EditCardRoute>(() => readCardRoute(screenCode));

  const refreshRoute = useCallback(() => {
    const next = readCardRoute(screenCode);
    setRoute((current) => sameRoute(current, next) ? current : next);
  }, [screenCode]);

  useEffect(() => {
    window.addEventListener('hashchange', refreshRoute);
    window.addEventListener('popstate', refreshRoute);
    window.addEventListener(routeChangeEvent, refreshRoute);
    refreshRoute();
    return () => {
      window.removeEventListener('hashchange', refreshRoute);
      window.removeEventListener('popstate', refreshRoute);
      window.removeEventListener(routeChangeEvent, refreshRoute);
    };
  }, [refreshRoute]);

  useEffect(() => {
    const current = currentHashRoute();
    if (current.screen !== screenCode) return;
    const parameters = mergeRegisterState(current.parameters, registerState);
    replaceHash(screenCode, parameters, false);
  }, [screenCode, stateKey]); // stateKey intentionally represents registerState by value.

  const openEditCard = useCallback((recordId: string) => {
    const parameters = mergeRegisterState(readParametersFor(screenCode), registerState);
    parameters.delete('card');
    parameters.set('id', recordId);
    pushHash(screenCode, parameters);
  }, [screenCode, stateKey]);

  const openCreateCard = useCallback(() => {
    const parameters = mergeRegisterState(readParametersFor(screenCode), registerState);
    parameters.delete('id');
    parameters.set('card', 'new');
    pushHash(screenCode, parameters);
  }, [screenCode, stateKey]);

  const replaceWithEditCard = useCallback((recordId: string) => {
    const parameters = mergeRegisterState(readParametersFor(screenCode), registerState);
    parameters.delete('card');
    parameters.set('id', recordId);
    replaceHash(screenCode, parameters, true);
  }, [screenCode, stateKey]);

  const returnToRegister = useCallback(() => {
    const parameters = mergeRegisterState(readParametersFor(screenCode), registerState);
    parameters.delete('id');
    parameters.delete('card');
    replaceHash(screenCode, parameters, true);
  }, [screenCode, stateKey]);

  return { route, openEditCard, openCreateCard, replaceWithEditCard, returnToRegister };
}

function readCardRoute(screenCode: string): EditCardRoute {
  const { screen, parameters } = currentHashRoute();
  if (screen !== screenCode) return { mode: 'register', recordId: null };
  const recordId = parameters.get('id')?.trim();
  if (recordId) return { mode: 'edit', recordId };
  if (parameters.get('card') === 'new') return { mode: 'create', recordId: null };
  return { mode: 'register', recordId: null };
}

function currentHashRoute(): { screen: string; parameters: URLSearchParams } {
  const route = window.location.hash.replace(/^#/, '');
  const separator = route.indexOf('?');
  if (separator < 0) return { screen: route, parameters: new URLSearchParams() };
  return {
    screen: route.slice(0, separator),
    parameters: new URLSearchParams(route.slice(separator + 1)),
  };
}

function readParametersFor(screenCode: string): URLSearchParams {
  const current = currentHashRoute();
  return current.screen === screenCode ? current.parameters : new URLSearchParams();
}

function mergeRegisterState(
  current: URLSearchParams,
  registerState: Record<string, RegisterStateValue>,
): URLSearchParams {
  const parameters = new URLSearchParams(current);
  for (const [key, value] of Object.entries(registerState)) {
    if (value === undefined || value === null || value === '' || value === false) parameters.delete(key);
    else parameters.set(key, String(value));
  }
  return parameters;
}

function pushHash(screenCode: string, parameters: URLSearchParams) {
  const url = hashUrl(screenCode, parameters);
  window.history.pushState({ ...(window.history.state ?? {}), qtEditCard: screenCode }, '', url);
  window.dispatchEvent(new Event(routeChangeEvent));
}

function replaceHash(screenCode: string, parameters: URLSearchParams, notify: boolean) {
  const url = hashUrl(screenCode, parameters);
  const currentUrl = `${window.location.pathname}${window.location.search}${window.location.hash}`;
  if (currentUrl !== url) window.history.replaceState(window.history.state, '', url);
  if (notify) window.dispatchEvent(new Event(routeChangeEvent));
}

function hashUrl(screenCode: string, parameters: URLSearchParams) {
  const query = parameters.toString();
  return `${window.location.pathname}${window.location.search}#${screenCode}${query ? `?${query}` : ''}`;
}

function serialiseState(state: Record<string, RegisterStateValue>) {
  return Object.entries(state)
    .sort(([left], [right]) => left.localeCompare(right))
    .map(([key, value]) => `${key}:${String(value ?? '')}`)
    .join('|');
}

function sameRoute(left: EditCardRoute, right: EditCardRoute) {
  return left.mode === right.mode && left.recordId === right.recordId;
}
