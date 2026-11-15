# Offline wrapper only. Pass an already audited, locally installed Linux base
# by immutable image ID or repository digest. Never build from the app root.
ARG BASE_IMAGE
FROM ${BASE_IMAGE}
USER 0:0
# No downloads, package installation, application COPY, host bind mounts or
# build secrets. Keep optional previously baked npm modules outside /tmp,
# which is intentionally replaced by fresh tmpfs on every compilation/run.
RUN test -x /usr/bin/env && test -x /usr/bin/timeout && test -x /bin/sh \
    && mkdir -p /work /opt/judge \
    && if [ -d /tmp/node_modules ]; then mv /tmp/node_modules /opt/judge/node_modules; fi \
    && chmod 0755 /work /opt/judge \
    && if [ -d /opt/judge/node_modules ]; then chmod -R a+rX /opt/judge/node_modules; fi
ENV HOME=/tmp TMPDIR=/tmp NODE_PATH=/opt/judge/node_modules
WORKDIR /work
USER 65534:65534
ENTRYPOINT []
CMD ["/bin/false"]
