# RefConcept Nuxt application image (storefront / seller-portal / admin-panel).
#
# Build from the repository root so the workspace and @refconcept/ui are available:
#   docker build -f infra/docker/nuxt/Dockerfile --build-arg APP=storefront -t refconcept-storefront .
#
# Local development does not use this image (Nuxt runs on the host, see ADR-0002);
# CI builds it on every push so the containerised path never rots.

ARG NODE_VERSION=22

# ---------- dependencies ----------
FROM node:${NODE_VERSION}-alpine AS deps
WORKDIR /repo

COPY package.json package-lock.json ./
COPY packages/ui/package.json packages/ui/
COPY apps/storefront/package.json apps/storefront/
COPY apps/seller-portal/package.json apps/seller-portal/
COPY apps/admin-panel/package.json apps/admin-panel/

# Nuxt's postinstall needs the app sources, so skip lifecycle scripts here and
# run `nuxt prepare` in the build stage instead.
RUN npm ci --ignore-scripts

# ---------- build ----------
FROM node:${NODE_VERSION}-alpine AS build
ARG APP
WORKDIR /repo

COPY --from=deps /repo/node_modules ./node_modules
COPY package.json package-lock.json ./
COPY packages ./packages
COPY apps/${APP} ./apps/${APP}

WORKDIR /repo/apps/${APP}
RUN npm run build

# ---------- runtime ----------
FROM node:${NODE_VERSION}-alpine AS runtime
ARG APP
ENV NODE_ENV=production \
    NITRO_PORT=3000 \
    NITRO_HOST=0.0.0.0

WORKDIR /app

# Nitro output is self-contained: no node_modules, no source.
COPY --from=build /repo/apps/${APP}/.output ./

USER node
EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD node -e "fetch('http://127.0.0.1:3000/').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", "server/index.mjs"]
