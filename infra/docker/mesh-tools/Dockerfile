# Mesh post-processing for generated product models.
#
# A generator answers with whatever it likes — Tripo ignored a 20k face limit and sent 101k —
# and the planner has to draw ten of these in one room on a laptop. This service takes a glTF
# binary and returns one the browser can afford: decimated, welded, meshopt-compressed, with
# its textures resized and re-encoded. Node because the tooling (gltf-transform, meshoptimizer)
# is Node; a separate container because the API image is PHP and should stay that way.
FROM node:22-alpine

WORKDIR /app

COPY package.json ./
RUN npm install --omit=dev --no-audit --no-fund

COPY server.mjs ./

ENV PORT=8080
EXPOSE 8080

USER node

CMD ["node", "server.mjs"]
