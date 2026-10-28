<?php
// Doplnkova konfiguracia Roundcube (mount /var/roundcube/config, docker entrypoint ju includuje).
// Ziadne tajomstvo tu nie je: des_key ide z ROUNDCUBEMAIL_DES_KEY (.env na serveri).

// Za nginx (web) v sieti mail: dovery X-Forwarded-For iba od tejto podsiete.
$config['proxy_whitelist'] = ['10.250.251.0/24'];
// Verejny pristup je iba HTTPS (Cloudflare -> edge -> web); cookies dostanu flag secure.
$config['use_https'] = true;
$config['login_rate_limit'] = 3;

$config['product_name'] = 'vostack.dev webmail';
$config['enable_installer'] = false;
$config['skin'] = 'elastic';
$config['language'] = 'sk_SK';

// Prihlasenie = cela adresa; SMTP sa autentifikuje rovnakymi udajmi.
$config['smtp_user'] = '%u';
$config['smtp_pass'] = '%p';

// TLS overenie vzdy zapnute; cert mail.vostack.dev sa overuje voci aliasu v sieti mail.
$tls = [
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => 'mail.vostack.dev',
    ],
];
$config['imap_conn_options'] = $tls;
$config['smtp_conn_options'] = $tls;

// ManageSieve nie je publikovany, takze iba tieto pluginy.
$config['plugins'] = ['archive', 'zipdownload'];
