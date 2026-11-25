
FROM php:8.3-apache

RUN apt-get update

RUN apt-get install -y --no-install-recommends \
    python3 \
    python3-venv \
    libsqlite3-dev \
    libcurl4-openssl-dev \
    libonig-dev \
    util-linux \
    coreutils \
    openssl \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install -j"$(nproc)" \
    pdo_sqlite \
    sqlite3 \
    curl \
    mbstring

RUN a2enmod rewrite
