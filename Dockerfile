FROM php:8.2-apache

ENV DEBIAN_FRONTEND=noninteractive

RUN echo 'Acquire::http::Timeout "60";' > /etc/apt/apt.conf.d/99timeout \
    && echo 'Acquire::http::Pipeline-Depth "0";' >> /etc/apt/apt.conf.d/99timeout \
    && echo 'Acquire::Retries "3";' >> /etc/apt/apt.conf.d/99timeout

RUN apt-get update -qq \
    && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        sqlite3 \
        curl \
        unzip \
    && docker-php-ext-install pdo pdo_sqlite \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

COPY . .

RUN mkdir -p data \
    && touch data/database.sqlite \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 data

EXPOSE 80

CMD ["apache2-foreground"]
