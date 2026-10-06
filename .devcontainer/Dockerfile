FROM mcr.microsoft.com/devcontainers/php:8.3

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev \
    && docker-php-ext-install intl gettext \
    && rm -rf /var/lib/apt/lists/*
