FROM dunglas/frankenphp:php8.5

ARG UID=1000
ARG GID=1000

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# zip lets composer unpack dist archives.
RUN install-php-extensions zip

# Run as the host user so files created by composer stay writable outside the container.
RUN groupadd -g "${GID}" app || groupmod -n app "$(getent group "${GID}" | cut -d: -f1)" \
 && useradd -u "${UID}" -g "${GID}" -m -s /bin/bash app \
 && mkdir -p /data/caddy /config/caddy \
 && chown -R app:app /data /config /app

USER app
WORKDIR /app

ENV COMPOSER_HOME=/home/app/.composer
