import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import RcField from './RcField.vue'

/**
 * A labelled control, and the accessibility that is easy to leave out.
 *
 * The interesting part is the *association*: a red border and a message underneath look
 * like an error to somebody who can see the form, and are nothing at all to somebody using
 * a screen reader. `aria-describedby` and `aria-invalid` are what turn a visual arrangement
 * into information.
 */
describe('RcField', () => {
  it('joins its label to its input', () => {
    const field = mount(RcField, { props: { label: 'E-posta', name: 'email' } })

    expect(field.find('label').attributes('for')).toBe('email')
    expect(field.find('input').attributes('id')).toBe('email')
  })

  it('associates an error with the input that caused it', () => {
    const field = mount(RcField, {
      props: { label: 'E-posta', name: 'email', errors: ['Geçerli bir e-posta girin.'] },
    })

    const input = field.find('input')

    /*
     * A red border is not an error message. Without the association a screen reader reads
     * "E-posta, edit text" and stops — the person is told the field exists and never told
     * it is wrong.
     */
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(input.attributes('aria-describedby')).toBe('email-error')
    expect(field.find('#email-error').text()).toBe('Geçerli bir e-posta girin.')
  })

  it('shows the first error rather than all of them', () => {
    // Laravel returns an array per field. Five messages under one input is a wall nobody
    // reads; the first one is the one to act on.
    const field = mount(RcField, {
      props: { label: 'Parola', name: 'password', errors: ['Çok kısa.', 'Rakam içermeli.'] },
    })

    expect(field.find('#password-error').text()).toBe('Çok kısa.')
    expect(field.text()).not.toContain('Rakam içermeli.')
  })

  it('describes the input by its hint when there is no error', () => {
    const field = mount(RcField, {
      props: { label: 'IBAN', name: 'iban', hint: 'TR ile başlayan 26 hane.' },
    })

    expect(field.find('input').attributes('aria-describedby')).toBe('iban-hint')
    expect(field.find('input').attributes('aria-invalid')).toBe('false')
  })

  it('lets an error replace the hint rather than stacking them', () => {
    const field = mount(RcField, {
      props: { label: 'IBAN', name: 'iban', hint: 'TR ile başlayan 26 hane.', errors: ['Geçerli bir IBAN girin.'] },
    })

    // The error is the more urgent of the two and says everything the hint would have.
    expect(field.find('input').attributes('aria-describedby')).toBe('iban-error')
    expect(field.text()).not.toContain('TR ile başlayan 26 hane.')
  })

  it('emits a number from a number input, and an empty string for an empty one', async () => {
    const field = mount(RcField, { props: { label: 'Adet', name: 'quantity', type: 'number' } })

    await field.find('input').setValue('7')

    /*
     * A number input that emits a string turns arithmetic into concatenation, silently:
     * a total of "7" and "3" becomes "73" and nobody notices until an invoice.
     */
    expect(field.emitted('update:modelValue')?.at(-1)).toEqual([7])

    await field.find('input').setValue('')

    // And empty stays empty rather than becoming zero — "no answer" is not "none".
    expect(field.emitted('update:modelValue')?.at(-1)).toEqual([''])
  })

  it('emits a boolean from a checkbox', async () => {
    const field = mount(RcField, { props: { label: 'Kabul ediyorum', name: 'consent', type: 'checkbox' } })

    await field.find('input').setValue(true)

    expect(field.emitted('update:modelValue')?.at(-1)).toEqual([true])
  })
})
