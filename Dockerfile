FROM php:8.4-cli-alpine

WORKDIR /app
COPY index.php .

EXPOSE 8089

CMD ["php", "-S", "0.0.0.0:8089", "index.php"]
