ARG BASE_IMAGE=wordpress:7.1.3-php8.3-fpm-alpine@sha256:82d93a279778e5307902813c402b9118521e8104459a0e350ed719f6d6c4d1ab
FROM ${BASE_IMAGE}
ARG SECURITY_REFRESH=local
RUN test -n "$SECURITY_REFRESH" \
    && apk upgrade --no-cache \
    && apk add --no-cache apache2 apache2-proxy \
    && sed -i 's/^www-data:x:82:82:/www-data:x:33:33:/' /etc/passwd \
    && sed -i 's/^www-data:x:82:/www-data:x:33:/' /etc/group \
    && test "$(id -u www-data)" = 33 \
    && mkdir -p /run/apache2 /etc/apache2/conf-enabled
COPY ci/apache-alpine.conf /etc/apache2/httpd.conf
COPY ci/php-fpm.conf /usr/local/etc/php-fpm.d/zzzz-ascla.conf
COPY ci/wordpress-entrypoint.sh /usr/local/bin/ascla-wordpress
COPY wp-content/plugins/ascla-core/ /usr/src/wordpress/wp-content/plugins/ascla-core/
COPY config/uploads.ini /usr/local/etc/php/conf.d/ascla-uploads.ini
COPY config/security.ini /usr/local/etc/php/conf.d/ascla-security.ini
COPY config/security.conf /etc/apache2/conf-enabled/ascla-security.conf
RUN chmod 755 /usr/local/bin/ascla-wordpress \
    && chmod 644 /etc/apache2/httpd.conf /usr/local/etc/php-fpm.d/zzzz-ascla.conf \
       /etc/apache2/conf-enabled/ascla-security.conf /usr/local/etc/php/conf.d/ascla-*.ini \
    && httpd -t && php-fpm -t
EXPOSE 80
ENTRYPOINT ["ascla-wordpress"]
CMD ["ascla-web"]
