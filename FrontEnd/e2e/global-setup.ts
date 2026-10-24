import { startE2EStack } from './support/compose';

export default async function globalSetup(): Promise<void> {
  await startE2EStack();
}
