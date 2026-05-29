FROM php:8.4-fpm-alpine

# Установка системных зависимостей
RUN apk add --no-cache \
    git \
    unzip \
    libpq-dev \
    oniguruma-dev \
    libxml2-dev \
    curl \
    && docker-php-ext-install \
    pdo_pgsql \
    mbstring \
    ctype

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Настройка рабочей директории
WORKDIR /app

# Создание пользователя с тем же UID/GID, что и у хоста
ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} symfony && \
    adduser -D -u ${UID} -G symfony symfony

# ВАЖНО: Даем права на запись в директорию
RUN mkdir -p /app && \
    chown -R symfony:symfony /app

RUN git config --system --add safe.directory /app

USER symfony

CMD ["php-fpm", "-F"]