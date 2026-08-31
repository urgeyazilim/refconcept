import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import RcBeforeAfter from './RcBeforeAfter.vue'

/**
 * The customer's room, before and after, under one handle.
 *
 * Side by side was honest and small, and it made the one thing worth seeing hardest: that
 * this is the *same* room. The eye cannot hold one image while reading the other. Under a
 * wipe the walls line up and dragging is the proof.
 *
 * What is worth testing is not how it looks but that the comparison can actually be made —
 * including by somebody who is not using a mouse.
 */
const props = {
  beforeSrc: 'https://example.test/before.png',
  afterSrc: 'https://example.test/after.png',
}

describe('RcBeforeAfter', () => {
  it('shows both pictures at once, one clipped over the other', () => {
    const panel = mount(RcBeforeAfter, { props })

    const images = panel.findAll('img')

    /*
     * Two images in the same box, not one swapped for the other. A component that
     * exchanged the source would have nothing to wipe between and would flicker on every
     * pixel of the drag.
     */
    expect(images).toHaveLength(2)
    expect(images[1]!.attributes('style')).toContain('clip-path')
  })

  it('is a real slider, so a keyboard can compare too', () => {
    const panel = mount(RcBeforeAfter, { props })
    const slider = panel.find('input[type="range"]')

    // A comparison only a mouse can perform is a comparison half the visitors cannot make.
    expect(slider.exists()).toBe(true)
    expect(slider.attributes('aria-label')).toBe('Öncesi ve sonrası arasında geçiş')
  })

  it('moves the wipe when the slider does', async () => {
    const panel = mount(RcBeforeAfter, { props })

    await panel.find('input[type="range"]').setValue(20)

    // 20% along means 80% of the "after" is hidden behind the original.
    expect(panel.findAll('img')[1]!.attributes('style')).toContain('inset(0 80% 0 0)')
  })

  it('asks to open whichever side was tapped', async () => {
    const panel = mount(RcBeforeAfter, { props })

    await panel.findAll('button')[1]!.trigger('click')

    // The labels are a way in, not decoration: a customer who wants to study the render
    // should not have to hunt for the picture they are already looking at.
    expect(panel.emitted('expand')?.[0]).toEqual(['after'])
  })

  it('does not start a drag when the press lands on a label', async () => {
    const panel = mount(RcBeforeAfter, { props, attachTo: document.body })

    /*
     * The bug this exists for. Capturing the pointer retargets everything that follows
     * to the capturing element, so the frame swallowed the click meant for "Son hâli":
     * the label looked like a button, highlighted like a button, and behaved like the
     * wipe. Only an end-to-end run noticed, because every screenshot was correct.
     */
    const label = panel.findAll('button')[1]!

    // Fired on the label so it bubbles to the frame with the label as its target, which
    // is exactly the shape of the press this guards against.
    await label.trigger('pointerdown', { clientX: 10 })
    await label.trigger('click')

    expect(panel.emitted('expand')?.[0]).toEqual(['after'])
  })

  it('names the two sides in the customer own words', () => {
    const panel = mount(RcBeforeAfter, {
      props: { ...props, beforeLabel: 'Şu an', afterLabel: 'Öneri' },
    })

    expect(panel.text()).toContain('Şu an')
    expect(panel.text()).toContain('Öneri')
  })
})
