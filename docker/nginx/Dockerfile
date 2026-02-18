FROM nginx:1.28-alpine

WORKDIR /var/www/html

COPY public ./public
COPY docker/nginx/nginx.prod.conf /etc/nginx/conf.d/default.conf
