ARG PHP_VERSION=8.5

FROM php:${PHP_VERSION}-apache

# cURL for Xendit and Google sign-in requests, PDO MySQL for the database.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite and .htaccess overrides.
RUN a2enmod rewrite \
    && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Only the application code is copied, path by path. Secrets (.env), customer uploads
# (public/uploads, storage/private), runtime caches and logs, tests, database dumps and git
# history never enter the image. Configuration comes from environment variables at run time,
# and uploads live on mounted volumes. .dockerignore excludes the same paths as a second guard.
COPY app/ app/
COPY scheme/ scheme/
COPY console/ console/
COPY lava ./
COPY public/index.php public/.htaccess public/
COPY storage/private/.htaccess storage/private/index.html storage/private/
COPY runtime/index.html runtime/

# Empty, writable directories for runtime data and uploads (mount volumes over the upload paths).
RUN mkdir -p runtime/cache runtime/logs runtime/session \
        public/uploads storage/private/user-verification storage/private/assessment-evidence \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Apache serves only public/.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot ${APACHE_DOCUMENT_ROOT}|g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|<Directory /var/www/html>|<Directory ${APACHE_DOCUMENT_ROOT}>|g' /etc/apache2/apache2.conf

# Production by default; secrets are passed with `docker run --env-file` or Compose, never baked in.
ENV APP_ENV=production

EXPOSE 80
