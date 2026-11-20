# Etapa 1: compilar os arquivos do frontend
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package*.json ./

RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

COPY . .

RUN npm run build


# Etapa 2: aplicação Laravel + Node.js + Chromium (Playwright)
FROM php:8.3-apache

ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
ENV LINKEDIN_HEADLESS=true

RUN apt-get update && apt-get install -y \
    ca-certificates \
    curl \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        mbstring \
        intl \
        zip \
        bcmath \
        pcntl \
        opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Configurar Apache para servir a pasta public
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' \
    /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -ri 's/<VirtualHost *:80>/<VirtualHost *:10000>/' \
    /etc/apache2/sites-available/000-default.conf

# Permitir que o Laravel use o public/.htaccess (incluindo a rota de health check /up).
RUN cat > /etc/apache2/conf-available/laravel-public.conf <<'EOF'
<Directory /var/www/html/public>
    Options FollowSymLinks
    AllowOverride All
    Require all granted
    DirectoryIndex index.php
</Directory>
EOF
RUN a2enconf laravel-public

COPY . .

# Dependências PHP e Node de produção
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-progress \
    && npm ci --omit=dev --no-audit --no-fund \
    && npx playwright install chromium --with-deps \
    && chmod -R a+rX /ms-playwright

# Arquivos compilados do frontend
COPY --from=frontend /app/public/build ./public/build

# Permissões do Laravel
RUN mkdir -p storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 10000

# Mantém o agendador Laravel ativo junto com o Apache.
CMD ["sh", "-c", "php artisan migrate --force && php artisan geo:import-ibge --if-empty && (php artisan schedule:work --no-interaction >> storage/logs/scheduler.log 2>&1 &) && apache2-foreground"]
