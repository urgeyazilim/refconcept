import { describe, expect, it } from 'vitest'

import { paintColor } from './RoomMaterials'

/**
 * The colour a room is painted, as the reading reports it.
 *
 * Whether the paint ends up on the right mesh is a question a screenshot answers better than
 * an assertion, and this file is a node environment with no canvas in it. What is worth
 * pinning here is the decision: which answers are used, which are refused, and what is done
 * to the ones that are used.
 */
describe('the colour a room is painted', () => {
  it('takes a hex the reading gave', () => {
    expect(paintColor('#8f8f8f')).not.toBeNull()
    expect(paintColor('#8F8F8F')).toBe(paintColor('#8f8f8f'))
    expect(paintColor('  #8f8f8f  ')).toBe(paintColor('#8f8f8f'))
  })

  it('refuses anything that is not one', () => {
    // A model that answers "beyaz" should leave the room its default, not paint it black.
    for (const said of ['beyaz', 'white', '#fff', '8f8f8f', '', 'rgb(1,2,3)', null, undefined]) {
      expect(paintColor(said)).toBeNull()
    }
  })

  it('lifts a colour read in shadow', () => {
    /*
     * A photograph read in shadow reports a wall far darker than anybody would call it, and
     * a room drawn nearly black is one nobody recognises either. Lifted towards white by a
     * fixed fraction, so the relationship between the surfaces survives.
     */
    expect(paintColor('#000000')).toBe(0x2e2e2e)
    expect(paintColor('#8f8f8f')).toBe(0xa3a3a3)

    // White stays white: there is nothing to lift it towards.
    expect(paintColor('#ffffff')).toBe(0xffffff)
  })
})
