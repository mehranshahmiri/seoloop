FROM php:8.4-cli

# curl, dom, mbstring and openssl ship with the image; intl is the only extra.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev \
 && docker-php-ext-install intl \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app
RUN mkdir -p /tmp/seoloop-cache && chown www-data: /tmp/seoloop-cache

ENV SEOLOOP_CACHE_DIR=/tmp/seoloop-cache \
    SEOLOOP_BASE_URL=http://localhost:8080 \
    PHP_CLI_SERVER_WORKERS=4

USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/up") === "ok\n" ? 0 : 1);'
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
