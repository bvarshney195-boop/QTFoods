import { lazy, type ComponentType, type LazyExoticComponent } from 'react';
import { screenRegistry } from '../data/screenRegistry';

type PageModule = { default: ComponentType };
type PageComponent = LazyExoticComponent<ComponentType>;

const pageModules = import.meta.glob<PageModule>([
  '../pages/*.tsx',
  '!../pages/*.test.tsx',
]);

function lazyPage(code: string): PageComponent {
  const path = `../pages/${code.replaceAll('-', '_')}.tsx`;
  const loader = pageModules[path];
  if (!loader) throw new Error(`Missing page module for ${code}.`);
  return lazy(loader);
}

export const pageMap = Object.fromEntries(
  screenRegistry.map(({ code }) => [code, lazyPage(code)])
) as Record<string, PageComponent>;
