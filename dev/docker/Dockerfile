FROM 192.168.18.237/pvmed/php:8.3-apache-bullseye

# 替换传统格式源文件（/etc/apt/sources.list）
RUN echo "deb https://mirrors.aliyun.com/debian/ bookworm main contrib non-free\n\
deb https://mirrors.aliyun.com/debian-security/ bookworm-security main\n\
deb https://mirrors.aliyun.com/debian/ bookworm-updates main contrib non-free\n\
deb https://mirrors.aliyun.com/debian/ bookworm-backports main contrib non-free" > /etc/apt/sources.list

# 替换DEB822格式源文件（/etc/apt/sources.list.d/debian.sources）
RUN echo "Types: deb\n\
URIs: https://mirrors.aliyun.com/debian\n\
Suites: bookworm bookworm-updates bookworm-backports\n\
Components: main contrib non-free\n\
Signed-By: /usr/share/keyrings/debian-archive-keyring.gpg\n\
\n\
Types: deb\n\
URIs: https://mirrors.aliyun.com/debian-security\n\
Suites: bookworm-security\n\
Components: main contrib non-free\n\
Signed-By: /usr/share/keyrings/debian-archive-keyring.gpg" > /etc/apt/sources.list.d/debian.sources

# Install basic dependencies first
RUN apt-get update && \
    apt-get install -y \
        git \
        pandoc \
        zip \
        wget \
        vim \
        unzip \
        libfreetype-dev \
        libjpeg62-turbo-dev \
        libldap2-dev \
        libpng-dev \
        libzip-dev \
        wait-for-it && \
    rm -rf /var/lib/apt/lists/*

# Install wkhtmltopdf dependencies separately
RUN apt-get update && \
    apt-get install -y \
        xfonts-75dpi \
        xfonts-base \
        xfonts-utils \
        libxrender1 \
        libx11-dev \
        libxext6 \
        libx11-6 \
        libssl3 && \
    rm -rf /var/lib/apt/lists/*

# Install Chinese fonts separately
RUN apt-get update && \
    apt-get install -y \
        fonts-wqy-microhei \
        fonts-wqy-zenhei \
        fonts-noto-cjk \
        fonts-noto-color-emoji && \
    rm -rf /var/lib/apt/lists/*

COPY ./dev/docker/wkhtmltopdf.deb /tmp/
RUN apt-get update && \
    apt-get install -y /tmp/wkhtmltopdf.deb

# 验证wkhtmltopdf安装
RUN wkhtmltopdf --version

# 备份原始wkhtmltopdf二进制文件
RUN mv /usr/local/bin/wkhtmltopdf /usr/local/bin/wkhtmltopdf.orig

# 创建包装脚本直接替换原始命令
RUN echo '#!/bin/bash' > /usr/local/bin/wkhtmltopdf && \
    echo '/usr/local/bin/wkhtmltopdf.orig --encoding UTF-8 "$@"' >> /usr/local/bin/wkhtmltopdf && \
    chmod +x /usr/local/bin/wkhtmltopdf

RUN ln -sf /usr/local/bin/wkhtmltopdf /usr/bin/wkhtmltopdf

# 刷新字体缓存
RUN fc-cache -fv

# Install PHP extensions
RUN docker-php-ext-configure ldap --with-libdir="lib/$(gcc -dumpmachine)" && \
    docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install -j$(nproc) pdo_mysql gd ldap zip && \
    pecl install xdebug && \
    docker-php-ext-enable xdebug

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Configure apache
RUN a2enmod rewrite && \
    sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf && \
    sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Use the default production configuration and update it as required
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    sed -i 's/memory_limit = 128M/memory_limit = 512M/g' "$PHP_INI_DIR/php.ini"

ENV APACHE_DOCUMENT_ROOT="/app/public"

WORKDIR /app
