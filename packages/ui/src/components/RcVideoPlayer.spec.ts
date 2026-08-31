import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RcVideoPlayer from './RcVideoPlayer.vue'

/**
 * Eight seconds of somebody's own living room, and the controls to actually look at it.
 *
 * What is worth testing here is not how the player looks but that the things a customer
 * came to do are reachable — pause on a frame, step to the next one, zoom in on the fabric
 * — and reachable without a mouse. A player whose only route to any of that is a hover is a
 * player half the visitors cannot use.
 *
 * happy-dom implements no media pipeline, so `play()` and `pause()` are stubbed. Everything
 * asserted below is state this component owns rather than anything the browser decides.
 */
const props = { src: 'https://example.test/oda.mp4', poster: 'https://example.test/oda.png' }

beforeEach(() => {
  // Neither exists in happy-dom, and an unhandled rejection from `play()` would fail a test
  // for a reason that has nothing to do with the component.
  HTMLMediaElement.prototype.play = vi.fn().mockResolvedValue(undefined)
  HTMLMediaElement.prototype.pause = vi.fn()
})

describe('RcVideoPlayer', () => {
  it('shows the render until the film has been started', () => {
    const player = mount(RcVideoPlayer, { props })

    // The poster is the frame the film was made from, so the first thing on screen is
    // never black — and never a different room from the one below it on the page.
    expect(player.find('video').attributes('poster')).toBe(props.poster)
    expect(player.find('[aria-label="Videoyu oynat"]').exists()).toBe(true)
  })

  it('loops by default, because eight seconds is a loop', () => {
    const player = mount(RcVideoPlayer, { props })

    // Asking somebody to press play again every eight seconds is asking them to stop
    // watching.
    expect(player.find('video').attributes('loop')).toBeDefined()
  })

  it('offers a real slider for the position', () => {
    const player = mount(RcVideoPlayer, { props })
    const slider = player.find('input[type="range"]')

    expect(slider.exists()).toBe(true)
    expect(slider.attributes('aria-label')).toBe('Video konumu')
  })

  it('skips five seconds either way', async () => {
    const player = mount(RcVideoPlayer, { props })
    const video = player.find('video').element as HTMLVideoElement

    Object.defineProperty(video, 'duration', { value: 8, configurable: true })
    video.currentTime = 4

    await player.find('[aria-label="5 saniye ileri"]').trigger('click')

    // Clamped to the end rather than running past it: a scrubber that reports nine seconds
    // of an eight-second film is a scrubber nobody trusts again.
    expect(video.currentTime).toBe(8)

    await player.find('[aria-label="5 saniye geri"]').trigger('click')

    expect(video.currentTime).toBe(3)
  })

  it('zooms in steps and says how far in it is', async () => {
    const player = mount(RcVideoPlayer, { props })

    await player.find('[aria-label="Yakınlaştır (şu an 1×)"]').trigger('click')

    // The label is the whole reason zoom is a cycle rather than a toggle: the customer
    // needs to know they are at 1.5× and that pressing again goes further in.
    expect(player.text()).toContain('1.5×')
    expect(player.find('video').attributes('style')).toContain('scale(1.5)')
  })

  it('returns to the middle when the zoom comes back to one', async () => {
    const player = mount(RcVideoPlayer, { props })
    const zoomButton = () => player.find('button[aria-label^="Yakınlaştır"]')

    // 1.5, 2, 3, 4, and back to 1.
    for (let press = 0; press < 5; press++) {
      await zoomButton().trigger('click')
    }

    // Leaving the pan where it was would show a corner of the room with black beside it.
    expect(player.find('video').attributes('style')).toContain('translate(0px, 0px)')
    expect(player.find('video').attributes('style')).toContain('scale(1)')
  })

  it('plays and pauses from the keyboard', async () => {
    const player = mount(RcVideoPlayer, { props })

    await player.trigger('keydown', { key: ' ' })

    // Bound on the frame rather than the document: a player that steals the space bar from
    // somebody scrolling the shopping list has decided it is the most important thing on
    // the page.
    expect(HTMLMediaElement.prototype.play).toHaveBeenCalled()
  })

  it('offers the film as a download under a name somebody would recognise', () => {
    const player = mount(RcVideoPlayer, {
      props: { ...props, downloadName: 'salon-video.mp4' },
    })

    const link = player.find('a[download]')

    expect(link.attributes('download')).toBe('salon-video.mp4')
    expect(link.attributes('href')).toBe(props.src)
  })

  it('starts a new film from the beginning', async () => {
    const player = mount(RcVideoPlayer, { props })

    await player.find('[aria-label="Videoyu oynat"]').trigger('click')
    await player.setProps({ src: 'https://example.test/baska-oda.mp4' })

    // A second film in the same player must not inherit the first one's zoom, position or
    // "already started" state — that would open somebody's new video mid-way, zoomed in on
    // a corner of the old one.
    expect(player.find('[aria-label="Videoyu oynat"]').exists()).toBe(true)
    expect(player.find('video').attributes('style')).toContain('scale(1)')
  })
})
