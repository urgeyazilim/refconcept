import { assertSimulatorAnswersThisSuite } from './ai-routes'

/**
 * Before any test runs: check that this suite's accounts will be answered by the simulator.
 *
 * A check rather than a change. The routing table used to be rewritten here and put back in
 * the teardown, which billed the owner when a run ended early and served the simulator to
 * real customers when a run died late. The server decides per account now, and all this has
 * to do is refuse to start if that is not set up.
 *
 * Not swallowed like the teardown's housekeeping. A run that cannot confirm this would bill
 * the owner for every photograph it uploads, and that is worth a red suite.
 */
export default async function globalSetup(): Promise<void> {
  await assertSimulatorAnswersThisSuite()
}
