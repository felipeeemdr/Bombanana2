FROM php:8.4-apache

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev; \
    docker-php-ext-install pdo_pgsql; \
    rm -rf /var/lib/apt/lists/*; \
    a2enmod headers rewrite; \
    printf '%s\n' \
      '<Directory /var/www/html>' \
      '    Options -Indexes' \
      '    AllowOverride All' \
      '    Require all granted' \
      '</Directory>' \
      > /etc/apache2/conf-available/nexo-directory.conf; \
    a2enconf nexo-directory; \
    sed -ri 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf; \
    sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf; \
    printf '%s\n' 'ServerSignature Off' 'ServerTokens Prod' > /etc/apache2/conf-available/nexo-hardening.conf; \
    a2enconf nexo-hardening

WORKDIR /var/www/html
COPY . /var/www/html
RUN chown -R www-data:www-data /var/www/html

ENV PORT=10000
EXPOSE 10000
CMD ["apache2-foreground"]
