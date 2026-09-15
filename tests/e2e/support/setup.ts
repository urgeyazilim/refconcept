import { pointBackgroundTasksAtSimulator } from './ai-routes'

/**
 * Before any test runs: the tasks a test triggers in the background go to the simulator.
 *
 * Not swallowed like the teardown's housekeeping. A run that cannot arrange this would bill
 * the owner for every photograph it uploads, and that is worth a red suite.
 */
export default async function globalSetup(): Promise<void> {
  await pointBackgroundTasksAtSimulator()
}
