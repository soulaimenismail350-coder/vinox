FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
      python3 python3-venv libsqlite3-dev libcurl4-openssl-dev libonig-dev \
      util-linux coreutils openssl \
    && docker-php-ext-install pdo_sqlite sqlite3 curl mbstring \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Keep Python packages isolated from Debian's system Python.
RUN python3 -m venv /opt/venv
ENV PATH="/opt/venv/bin:${PATH}"

COPY requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt && rm /tmp/requirements.txt

COPY deploy/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/entrypoint.sh /usr/local/bin/vinox-entrypoint
RUN chmod +x /usr/local/bin/vinox-entrypoint

WORKDIR /var/www/html
COPY . /var/www/html
RUN chown -R www-data:www-data /var/www/html

EXPOSE 10000
CMD ["/usr/local/bin/vinox-entrypoint"]
