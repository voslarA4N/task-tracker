# nginx obraz pre staging/produkciu: public/, sablony a realip snippet su zapecene.
# Build kontext je koren repozitara: docker build -f docker/nginx/Dockerfile .
FROM nginx:1.30-alpine

COPY docker/nginx/templates /etc/nginx/templates
COPY docker/nginx/realip /etc/nginx/realip.d
COPY public /var/www/html/public
