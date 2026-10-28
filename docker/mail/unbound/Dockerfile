# Vlastny rekurzivny validujuci resolver pre Stalwart (docs/ops/mail.md).
# Hetzner resolvery dostavaju od Spamhaus odpoved 127.255.255.254 (refused), takze DNSBL
# by bez vlastnej rekurzie nefungoval. Balik z Alpine, ziadny obraz tretej strany.
FROM alpine:3.24
RUN apk add --no-cache unbound drill \
 && install -d -o unbound -g unbound /var/lib/unbound \
 && install -o unbound -g unbound -m 0644 /usr/share/dnssec-root/trusted-key.key /var/lib/unbound/root.key
COPY unbound.conf /etc/unbound/unbound.conf
EXPOSE 53/udp 53/tcp
CMD ["unbound", "-d", "-c", "/etc/unbound/unbound.conf"]
