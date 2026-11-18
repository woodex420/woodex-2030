# WOODEX OS: the web server of the Hostinger rehearsal in CI.
#
# Apache with PHP 8.4 as a module, the extensions the installer asks for, .htaccess files switched on, and PHP
# running as a hosting account ("u123456789") whose home folder holds the website's folder, as on shared hosting:
#
#   /home/u123456789/domains/staging.woodex.test/public_html     the website's folder (the release goes here)
#   /home/u123456789/woodex-os/staging.woodex.test/              where the installer keeps settings and uploads
#
# Built and used by the job "hostinger" of .github/workflows/os-ci.yml. Nothing of it is deployed anywhere.
# Hostinger's own web server is LiteSpeed, which reads the same .htaccess files; Apache is the reference for what
# such a file means. The proof on the account itself is master list T-131.
FROM php:8.4-apache

ARG ACCOUNT=u123456789
ARG ACCOUNT_UID=1001
ARG ACCOUNT_GID=1001

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" exif gd intl pdo_mysql zip; \
    rm -rf /var/lib/apt/lists/*; \
    a2enmod rewrite headers; \
    sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    ln -s /usr/local/bin/php /usr/bin/php; \
    groupadd --non-unique --gid "$ACCOUNT_GID" "$ACCOUNT"; \
    useradd --non-unique --uid "$ACCOUNT_UID" --gid "$ACCOUNT_GID" --home-dir "/home/$ACCOUNT" --no-create-home --shell /bin/sh "$ACCOUNT"

ENV APACHE_RUN_USER=${ACCOUNT} APACHE_RUN_GROUP=${ACCOUNT}

COPY apache-site.conf /etc/apache2/sites-available/000-default.conf
