FROM php:8.3-apache-trixie

ARG UID=1000
ARG GID=1000

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pgsql intl gettext gd zip ldap bcmath imagick opcache

# fop: labels generation, postgresql-client: backups, msmtp-mta: sendmail for mail()
RUN mkdir -p /usr/share/man/man1 \
    && apt-get update \
    && apt-get install -y --no-install-recommends fop ghostscript postgresql-client msmtp-mta locales \
    && rm -rf /var/lib/apt/lists/*

RUN sed -i -E 's/^# ((en_GB|en_US|fr_FR)\.UTF-8)/\1/' /etc/locale.gen && locale-gen

# allow ImageMagick to read and write PDF files
RUN sed -i 's/rights="none" pattern="PDF"/rights="read|write" pattern="PDF"/' /etc/ImageMagick-*/policy.xml

# run Apache with the host user ids, so files written in the bind mount stay editable
RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g ${GID} www-data

RUN a2enmod rewrite
COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY php.ini /usr/local/etc/php/conf.d/collec.ini
COPY msmtprc /etc/msmtprc
COPY --chmod=755 entrypoint.sh /usr/local/bin/entrypoint.sh

WORKDIR /var/www/collec2App/collec-science

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
