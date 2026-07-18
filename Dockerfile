FROM php:8.2-apache

# 必要なパッケージと拡張モジュールのインストール (zip等)
RUN apt-get update && apt-get install -y \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Apacheのmod_rewriteを有効化
RUN a2enmod rewrite

# Composerのインストール
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 作業ディレクトリの設定
WORKDIR /var/www/html

# ソースファイルのコピー
COPY composer.json ./
RUN composer install --no-interaction --no-plugins --no-scripts --prefer-dist

COPY index.php ./
COPY entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080
ENV PORT 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
