FROM php:8.4-cli-alpine

LABEL maintainer="Oxford Online Pharmacy"
LABEL description="Fake Docman Connect API for local development and testing"

WORKDIR /app

COPY index.php .

# Ensure the history file is writable
RUN touch /tmp/docman-history.json && chmod 666 /tmp/docman-history.json

EXPOSE 8089

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD wget -qO- http://localhost:8089/api/health || exit 1

ENV REQUIRE_AUTH=false
ENV DOCMAN_USERNAME=admin
ENV DOCMAN_PASSWORD=password
ENV TOKEN_EXPIRY_SECONDS=3600
ENV FAIL_RATE=0.0
ENV SLOW_RESPONSE_MS=0
ENV FORCE_ERROR_CODE=
ENV FORCE_HTTP_STATUS=
ENV MAX_FILE_SIZE_MB=10
ENV SIMULATE_DC02=false
ENV CHAOS_INTERVAL=0

CMD ["php", "-S", "0.0.0.0:8089", "index.php"]
