# Infinity System FB — imagem para Coolify (PHP 8.3 + Apache).
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev default-mysql-client \
 && docker-php-ext-install pdo_mysql zip \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-sistema-fb.ini
COPY docker/apache.conf /etc/apache2/conf-enabled/zz-sistema-fb.conf
COPY docker/entrypoint.sh /usr/local/bin/sistema-fb-entrypoint
COPY . /var/www/html/

RUN chmod +x /usr/local/bin/sistema-fb-entrypoint \
 && mkdir -p /var/www/html/storage/imports /var/www/html/storage/templates /var/www/html/storage/logs \
 && chown -R www-data:www-data /var/www/html/storage

# Planilhas importadas e o modelo FOR 024 ficam aqui: monte um volume persistente no Coolify.
VOLUME ["/var/www/html/storage"]
EXPOSE 80
ENTRYPOINT ["sistema-fb-entrypoint"]
CMD ["apache2-foreground"]
