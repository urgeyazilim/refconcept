import { defineConfig } from 'vitest/config'

/**
 * Unit tests for the room editor's arithmetic.
 *
 * Scoped to `app/room3d` on purpose. What is worth testing here is not the rendering — a
 * screenshot review answers "does the room look right" far better than any assertion — but
 * the geometry, which is a copy of `App\Domains\Projects\Services\LayoutGeometry` and has to
 * keep agreeing with it. The cases in `CollisionEngine.spec.ts` are deliberately the same
 * cases as `LayoutGeometryTest.php`, in the same order, so a rule changed on one side and
 * not the other fails somewhere rather than shipping as two systems that quietly disagree.
 *
 * A plain node environment: none of this touches a DOM, and none of it should.
 */
export default defineConfig({
  test: {
    environment: 'node',
    include: ['app/room3d/**/*.spec.ts'],
    globals: true,
  },
})
