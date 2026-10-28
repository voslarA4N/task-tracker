# nginx obraz pre server (prod aj staging): public/, sablony, realip a http.d snippety su zapecene.
# Build kontext je koren repozitara: docker build -f docker/nginx/Dockerfile .
FROM nginx:1.30-alpine

COPY docker/nginx/templates /etc/nginx/templates
COPY docker/nginx/realip /etc/nginx/realip.d
COPY docker/nginx/http.d /etc/nginx/http.d
COPY public /var/www/html/public
