# ========== 第一阶段：Composer 依赖安装 ==========
FROM registry.cn-hangzhou.aliyuncs.com/jcleng/library-composer:2 AS composer-builder
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# ========== 第二阶段：项目源码构建 ==========
FROM registry.cn-hangzhou.aliyuncs.com/jcleng/library-alpine:3.20.1 AS runtime
WORKDIR /app
RUN apk add --no-cache curl tar \
    && curl -fsSL https://dl.static-php.dev/static-php-cli/common/php-8.4.1-cli-linux-x86_64.tar.gz | tar -xz -C /usr/local/bin \
    && rm -rf /var/cache/apk/*
# 复制项目源码
COPY . .
COPY --from=composer-builder /app/vendor ./vendor

# 确保入口脚本可执行
RUN chmod +x bin/simplecode

ENTRYPOINT ["php", "bin/simplecode"]
