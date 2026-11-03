FROM php:8.3-cli

# Driver do Postgres (Supabase)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

# Vários processos para atender mais de uma pessoa ao mesmo tempo
ENV PHP_CLI_SERVER_WORKERS=4
EXPOSE 10000

# O Render informa a porta na variável PORT
CMD ["sh", "-c", "php -d display_errors=0 -d log_errors=1 -S 0.0.0.0:${PORT:-10000} router.php"]
