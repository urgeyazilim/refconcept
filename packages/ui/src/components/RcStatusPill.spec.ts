import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import RcStatusPill from './RcStatusPill.vue'

/**
 * The status chip, and the one rule that makes it worth being a component at all.
 *
 * Every surface in RefConcept shows the same handful of lifecycle states — a seller sees
 * "İncelemede" on their listing, a reviewer sees it in a queue, an operator sees it in an
 * audit view. Mapping status to colour in each app is how the three drift until "rejected"
 * is red in one place and grey in another, and a customer-facing screen contradicts the
 * screen the support agent is reading.
 */
describe('RcStatusPill', () => {
  it('takes its colour from the status code, never from the label', () => {
    /*
     * The distinction the whole component exists for. Labels are Turkish prose that changes
     * with a copy edit; a chip that read its colour from the words would turn a wording
     * change into a colour change nobody asked for.
     */
    const approved = mount(RcStatusPill, { props: { status: 'approved', label: 'Reddedildi' } })

    expect(approved.classes().join(' ')).toContain('bg-success-subtle')
    expect(approved.classes().join(' ')).not.toContain('danger')
  })

  it('gives a refusal the same red everywhere', () => {
    for (const status of ['rejected', 'suspended']) {
      const pill = mount(RcStatusPill, { props: { status } })

      expect(pill.classes().join(' '), status).toContain('bg-danger-subtle')
    }
  })

  it('gives a waiting state amber and a finished one green', () => {
    const waiting = ['pending_review', 'in_review', 'submitted', 'paused', 'out_of_stock']

    for (const status of waiting) {
      expect(mount(RcStatusPill, { props: { status } }).classes().join(' '), status)
        .toContain('bg-warning-subtle')
    }

    for (const status of ['approved', 'active']) {
      expect(mount(RcStatusPill, { props: { status } }).classes().join(' '), status)
        .toContain('bg-success-subtle')
    }
  })

  it('renders an unknown status plainly instead of breaking', () => {
    /*
     * A new state added to the API reaches the client before the client knows about it.
     * Falling through to neutral means the page looks unremarkable; throwing, or picking a
     * colour at random, would make a backend deploy into a frontend incident.
     */
    const pill = mount(RcStatusPill, { props: { status: 'brand_new_state' } })

    expect(pill.classes().join(' ')).toContain('bg-bg-muted')
    expect(pill.text()).toBe('brand_new_state')
  })

  it('falls back to the status code when there is no label', () => {
    // Better than an empty chip: an operator seeing a raw code can at least search for it.
    expect(mount(RcStatusPill, { props: { status: 'draft' } }).text()).toBe('draft')
    expect(mount(RcStatusPill, { props: { status: 'draft', label: 'Taslak' } }).text()).toBe('Taslak')
  })
})
