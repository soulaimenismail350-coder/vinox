
RUN apt-get update && apt-get install -y --no-install-recommends \
    python3 \
    python3-venv \
    libcurl4-openssl-dev \
    libonig-dev \
    util-linux \
    coreutils \
    openssl \
    && docker-php-ext-install -j"$(nproc)" curl mbstring \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*
