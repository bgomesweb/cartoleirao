FROM php:8.5-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libcurl4-openssl-dev \
        libonig-dev \
    && docker-php-ext-install pdo_mysql mbstring intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Document root aponta pra public/ (a única pasta que deve ficar acessível
# pelo navegador — ver DEPLOY-CPANEL.md), não pra raiz do projeto.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf \
    && sed -ri -e "s!/var/www/!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

RUN a2enmod rewrite

WORKDIR /var/www/html
