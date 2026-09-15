import http from 'node:http'
import { NodeIO } from '@gltf-transform/core'
import { ALL_EXTENSIONS } from '@gltf-transform/extensions'
import {
  dedup,
  flatten,
  join,
  meshopt,
  prune,
  simplify,
  textureCompress,
  weld,
} from '@gltf-transform/functions'
import { MeshoptEncoder, MeshoptSimplifier } from 'meshoptimizer'
import sharp from 'sharp'

/**
 * POST /optimise  — body: a glTF binary; query: faces (target triangles), texture (max px)
 * GET  /health
 *
 * What comes back is the same model the browser can afford: welded, decimated towards the
 * target, meshopt-compressed, textures resized and stored as WebP. The mesh's size and
 * position are untouched — the planner scales it to the SKU either way.
 */
const PORT = Number(process.env.PORT ?? 8080)
const MAX_BYTES = 64 * 1024 * 1024

const io = new NodeIO().registerExtensions(ALL_EXTENSIONS).registerDependencies({
  'meshopt.encoder': MeshoptEncoder,
})

function triangleCount(document) {
  let total = 0

  for (const mesh of document.getRoot().listMeshes()) {
    for (const primitive of mesh.listPrimitives()) {
      const indices = primitive.getIndices()
      const position = primitive.getAttribute('POSITION')
      const count = indices ? indices.getCount() : (position ? position.getCount() : 0)

      total += Math.floor(count / 3)
    }
  }

  return total
}

async function optimise(bytes, faces, texture) {
  await MeshoptEncoder.ready
  await MeshoptSimplifier.ready

  const document = await io.readBinary(new Uint8Array(bytes))
  const before = triangleCount(document)

  await document.transform(
    dedup(),
    flatten(),
    join(),
    weld(),
  )

  /*
   * Decimated only as far as needed, and never below what the generator gave. A ratio of
   * one is a no-op; the error bound keeps the silhouette — a sofa at 20k faces still reads
   * as the sofa, which is all a room seen across a room asks of it.
   */
  if (before > faces) {
    await document.transform(
      simplify({ simplifier: MeshoptSimplifier, ratio: faces / before, error: 0.001, lockBorder: true }),
    )
  }

  await document.transform(
    textureCompress({ encoder: sharp, targetFormat: 'webp', resize: [texture, texture], quality: 82 }),
    prune(),
    meshopt({ encoder: MeshoptEncoder, level: 'medium' }),
  )

  const after = triangleCount(document)
  const out = await io.writeBinary(document)

  return { out, before, after }
}

function readBody(request) {
  return new Promise((resolve, reject) => {
    const chunks = []
    let size = 0

    request.on('data', (chunk) => {
      size += chunk.length

      if (size > MAX_BYTES) {
        reject(new Error('too large'))
        request.destroy()

        return
      }

      chunks.push(chunk)
    })
    request.on('end', () => resolve(Buffer.concat(chunks)))
    request.on('error', reject)
  })
}

const server = http.createServer(async (request, response) => {
  const url = new URL(request.url ?? '/', 'http://localhost')

  if (request.method === 'GET' && url.pathname === '/health') {
    response.writeHead(200, { 'Content-Type': 'application/json' })
    response.end(JSON.stringify({ ok: true }))

    return
  }

  if (request.method !== 'POST' || url.pathname !== '/optimise') {
    response.writeHead(404)
    response.end()

    return
  }

  try {
    const faces = Math.max(1000, Number(url.searchParams.get('faces') ?? 20000))
    const texture = Math.max(256, Number(url.searchParams.get('texture') ?? 1024))
    const body = await readBody(request)

    if (body.length < 12 || body.toString('ascii', 0, 4) !== 'glTF') {
      response.writeHead(422, { 'Content-Type': 'application/json' })
      response.end(JSON.stringify({ error: 'not a glTF binary' }))

      return
    }

    const started = Date.now()
    const { out, before, after } = await optimise(body, faces, texture)

    response.writeHead(200, {
      'Content-Type': 'model/gltf-binary',
      'Content-Length': out.byteLength,
      'X-Triangles-Before': String(before),
      'X-Triangles-After': String(after),
      'X-Elapsed-Ms': String(Date.now() - started),
    })
    response.end(Buffer.from(out))
  }
  catch (error) {
    response.writeHead(500, { 'Content-Type': 'application/json' })
    response.end(JSON.stringify({ error: error instanceof Error ? error.message : String(error) }))
  }
})

server.listen(PORT, () => {
  console.log(`mesh-tools listening on ${PORT}`)
})
