FROM wordpress:6.7-php8.2-fpm

# System deps for image processing + healthcheck tooling.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        less \
        bash \
        curl \
        ca-certificates \
        mariadb-client \
        libmagickwand-dev \
        ghostscript \
 && rm -rf /var/lib/apt/lists/*

# PHP extensions: redis (object cache), imagick (media).
RUN pecl install redis imagick \
 && docker-php-ext-enable redis imagick

# wp-cli for the wpcli + backup containers.
RUN curl -fsSL -o /usr/local/bin/wp \
        https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
 && chmod +x /usr/local/bin/wp

WORKDIR /var/www/html
