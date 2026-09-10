FROM registry.cn-hangzhou.aliyuncs.com/jcleng/gitbuild-php:8.1-cli

WORKDIR /app

# 复制 composer 文件并安装依赖（利用缓存层）
COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# 复制项目源码
COPY . .

# 确保入口脚本可执行
RUN chmod +x bin/simplecode

ENTRYPOINT ["php", "bin/simplecode"]
