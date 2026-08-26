import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import RcButton from './RcButton.vue'

/**
 * The button, and the two things it must never do.
 *
 * It must never be pressable while it is already working — a second click on "Öde" is a
 * second payment attempt — and it must never render as a link that does nothing, which is
 * a real defect this component has already had: `<component is="NuxtLink">` resolved to an
 * unknown element, the button looked perfect and did nothing when clicked, and only an
 * end-to-end test noticed because every screenshot was correct.
 */
const NuxtLinkStub = { name: 'NuxtLink', props: ['to'], template: '<a :href="to"><slot /></a>' }

describe('RcButton', () => {
  it('cannot be pressed while it is loading', () => {
    const button = mount(RcButton, { props: { loading: true }, slots: { default: 'Öde' } })

    // The whole point: a second click on a payment button is a second payment attempt.
    expect(button.find('button').attributes('disabled')).toBeDefined()
  })

  it('shows that it is working, not only that it is dead', () => {
    const button = mount(RcButton, { props: { loading: true }, slots: { default: 'Öde' } })

    // A disabled button with no spinner reads as broken rather than as busy, and the
    // customer's next move is to reload the page mid-payment.
    expect(button.find('svg').exists()).toBe(true)
    expect(button.text()).toContain('Öde')
  })

  it('renders a real link when it is given a destination', () => {
    const button = mount(RcButton, {
      props: { to: '/catalog' },
      slots: { default: 'Ürünler' },
      global: { components: { NuxtLink: NuxtLinkStub } },
    })

    /*
     * The regression this test exists for. A button that renders an unknown element looks
     * exactly right in a screenshot and does nothing at all when clicked.
     */
    expect(button.find('a').exists()).toBe(true)
    expect(button.find('a').attributes('href')).toBe('/catalog')
    expect(button.find('button').exists()).toBe(false)
  })

  it('carries no type attribute when it is a link', () => {
    const button = mount(RcButton, {
      props: { to: '/catalog', type: 'submit' },
      slots: { default: 'Ürünler' },
      global: { components: { NuxtLink: NuxtLinkStub } },
    })

    // `type="submit"` on an anchor is meaningless and confuses assistive technology.
    expect(button.find('a').attributes('type')).toBeUndefined()
  })

  it('defaults to type=button so it cannot submit a form by accident', () => {
    /*
     * An HTML button inside a form submits it unless told otherwise. A "Favorilere ekle"
     * button that quietly submits the checkout form around it is the kind of bug that is
     * found by a customer.
     */
    expect(mount(RcButton, { slots: { default: 'Ekle' } }).find('button').attributes('type'))
      .toBe('button')
  })

  it('keeps a disabled button disabled whether or not it is loading', () => {
    const button = mount(RcButton, { props: { disabled: true }, slots: { default: 'Kaydet' } })

    expect(button.find('button').attributes('disabled')).toBeDefined()
    expect(button.find('svg').exists()).toBe(false)
  })
})
