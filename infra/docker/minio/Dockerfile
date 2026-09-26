# MinIO and `mc`, built here because they can no longer be fetched.
#
# On 2026-09-26 every published copy stopped answering an anonymous pull at once:
# `minio/minio` on Docker Hub reports the repository does not exist, `quay.io/minio/minio`
# answers 401 for the whole repository rather than for one tag, ghcr.io answers `denied`, and
# MinIO's own download server returns `410 Gone` for both the server and the client binaries.
# The Bitnami mirrors that used to stand in for such things have gone the same way. It took
# the entire stack down mid-deploy, because Coolify pulls the images a compose file names
# before it stops the containers it is replacing — so a registry going quiet is not a slow
# deploy, it is an outage.
#
# What is still public is the source, under AGPL, on GitHub. So the images are made from it.
#
# Both binaries live in one image on purpose. `mc` is only wanted for the few seconds it
# takes to create the buckets and open the public one, and a second build context to
# maintain for that is worse than one image that carries both.
#
# The tags are pinned rather than tracking a branch. The point of this file is that a deploy
# two months from now builds exactly what today's build produced, and a moving target is how
# we got here.
FROM golang:1.24-alpine AS build

ARG MINIO_RELEASE=RELEASE.2025-10-15T17-29-55Z
ARG MC_RELEASE=RELEASE.2025-08-13T08-35-41Z

RUN apk add --no-cache git

# Cloned and built one at a time so a change to either tag only rebuilds that one.
RUN git clone --depth 1 --branch "${MINIO_RELEASE}" https://github.com/minio/minio.git /src/minio

# Static, so the runtime stage needs nothing but certificates; trimmed, because the build
# path of a throwaway container is not worth carrying in every layer.
#
# The version is written in by hand because MinIO's own release script does it, and a binary
# built without it introduces itself as `DEVELOPMENT.GOGET` in every log line — which is not
# what anybody wants to read while working out which release is running.
RUN cd /src/minio && CGO_ENABLED=0 go build -trimpath \
      -ldflags "-s -w \
        -X github.com/minio/minio/cmd.Version=${MINIO_RELEASE} \
        -X github.com/minio/minio/cmd.ReleaseTag=${MINIO_RELEASE}" \
      -o /out/minio .

RUN git clone --depth 1 --branch "${MC_RELEASE}" https://github.com/minio/mc.git /src/mc

RUN cd /src/mc && CGO_ENABLED=0 go build -trimpath \
      -ldflags "-s -w \
        -X github.com/minio/mc/cmd.Version=${MC_RELEASE} \
        -X github.com/minio/mc/cmd.ReleaseTag=${MC_RELEASE}" \
      -o /out/mc .

FROM alpine:3.20

# Certificates because both talk to the outside; `wget` comes from busybox and is what the
# health check uses, so that answering "is it up yet" needs no configured `mc` alias.
RUN apk add --no-cache ca-certificates

COPY --from=build /out/minio /usr/bin/minio
COPY --from=build /out/mc /usr/bin/mc

RUN mkdir -p /data

EXPOSE 9000 9001

ENTRYPOINT ["/usr/bin/minio"]
CMD ["server", "/data"]
