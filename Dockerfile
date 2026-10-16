FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# A cópia fora do directório publicado permite restaurar a regra de protecção
# quando o volume de uploads é criado pela primeira vez no Codespaces.
RUN mkdir -p /usr/local/share/oportuniza /var/www/html/uploads/products
COPY uploads/products/.htaccess /usr/local/share/oportuniza/products.htaccess
COPY docker/oportuniza-entrypoint.sh /usr/local/bin/oportuniza-entrypoint
RUN chmod 0755 /usr/local/bin/oportuniza-entrypoint \
    && cp /usr/local/share/oportuniza/products.htaccess /var/www/html/uploads/products/.htaccess \
    && chown -R www-data:www-data /var/www/html/uploads

WORKDIR /var/www/html
ENTRYPOINT ["oportuniza-entrypoint"]
CMD ["apache2-foreground"]
