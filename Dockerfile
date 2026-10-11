# syntax=docker/dockerfile:1

# ---- dependencies -----------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

# ---- runtime ----------------------------------------------------------------
FROM php:8.3-apache

RUN a2enmod headers \
 && a2dissite 000-default \
 && rm -f /etc/apache2/conf-enabled/other-vhosts-access-log.conf

COPY docker/apache-vhost.conf /etc/apache2/sites-available/special.conf
RUN a2ensite special
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-special.ini
RUN docker-php-ext-install opcache > /dev/null

WORKDIR /var/www/html
COPY public ./public
COPY src ./src
COPY views ./views
COPY scripts ./scripts
COPY --from=vendor /app/vendor ./vendor

# The image carries its own deployment descriptor so an EC2 host only has to know the image name.
COPY deploy/aws/compose.prod.yml deploy/aws/Caddyfile deploy/aws/deploy.sh /opt/special-deploy/

COPY docker/entrypoint.sh /usr/local/bin/special-entrypoint
RUN chmod +x /usr/local/bin/special-entrypoint /opt/special-deploy/deploy.sh

ENV SPECIAL_ENV=production \
    SPECIAL_STORE=dynamodb \
    SPECIAL_SESSION_PATH=/var/lib/special/sessions \
    SPECIAL_TRUST_PROXY=1

VOLUME ["/var/lib/special"]
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD php -r '$c=stream_context_create(["http"=>["timeout"=>4,"ignore_errors"=>true]]); $r=@file_get_contents("http://127.0.0.1/health",false,$c); exit($r!==false && str_contains($r,"\"ok\"")?0:1);'

ENTRYPOINT ["special-entrypoint"]
CMD ["apache2-foreground"]
