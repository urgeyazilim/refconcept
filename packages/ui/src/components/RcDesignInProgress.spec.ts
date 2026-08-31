import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import RcDesignInProgress from './RcDesignInProgress.vue'

/**
 * The minute a customer spends waiting for their room.
 *
 * What matters here is that the screen is telling the truth. The sketch fills in because
 * the engine reported a stage, not because a timer went off — a customer watching the sofa
 * appear the moment products were chosen is being told something real, and an animation
 * running on its own clock is a lie that eventually desynchronises in front of them.
 *
 * So these check the wiring rather than the appearance: the right words for the stage, and
 * nothing drawn that has not actually happened yet.
 */
describe('RcDesignInProgress', () => {
  it('says what the engine is doing, not what state it is in', () => {
    const panel = mount(RcDesignInProgress, { props: { stage: 'plan', progressBps: 4_500 } })

    // "Yerleşim planlanıyor" is a status. This is somebody telling you what they are doing,
    // which is what a minute of attention is worth spending on.
    expect(panel.text()).toContain('Yerleşimi kuruyoruz')
    expect(panel.text()).toContain('odak noktasını')
  })

  it('draws nothing that has not happened yet', () => {
    const early = mount(RcDesignInProgress, { props: { stage: 'analysis', progressBps: 2_500 } })

    /*
     * The room is being read; no products have been chosen and nothing has been drawn. A
     * sketch showing furniture at this point would be inventing a stage that has not run.
     */
    expect(early.find('.rc-piece').exists()).toBe(false)
    expect(early.find('.rc-light').exists()).toBe(false)
  })

  it('fills the room in as the stages are reached', () => {
    const rendering = mount(RcDesignInProgress, { props: { stage: 'render', progressBps: 8_500 } })

    // By the render everything before it has happened, so everything before it is drawn.
    expect(rendering.find('.rc-plan').exists()).toBe(true)
    expect(rendering.findAll('.rc-piece').length).toBeGreaterThan(0)
    expect(rendering.find('.rc-light').exists()).toBe(true)
  })

  it('falls back to the first stage rather than going blank', () => {
    // A stage the client does not recognise — an engine that grew a step, an old tab — is
    // not a reason to show a customer an empty panel.
    const unknown = mount(RcDesignInProgress, { props: { stage: 'something-new', progressBps: 0 } })

    expect(unknown.text()).toContain('Başlıyoruz')
  })

  it('shows something an interior designer would tell you', () => {
    const panel = mount(RcDesignInProgress, { props: { stage: 'match', progressBps: 6_500 } })

    /*
     * Real craft rather than filler. Somebody who reads three of these has got something
     * out of the minute even if they never buy anything, and they are the quiet argument
     * for what the design costs.
     */
    expect(panel.text()).toContain('İç mimarlıktan')
    expect(panel.text()).toMatch(/\d+/)
  })

  it('keeps the progress figure inside the bar it draws', () => {
    // A provider that reports oddly should not produce a bar wider than its track or a
    // negative percentage under the heading.
    const over = mount(RcDesignInProgress, { props: { stage: 'render', progressBps: 14_000 } })
    const under = mount(RcDesignInProgress, { props: { stage: 'queued', progressBps: -200 } })

    expect(over.text()).toContain('%100')
    expect(under.text()).toContain('%0')
  })
})
