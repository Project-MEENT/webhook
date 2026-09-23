<?php

const STORAGE_ROOT = '/app/data/solid-server';

const CLEANUP_FILE = STORAGE_ROOT .'/db/lastcleanup';
const DBPATH = STORAGE_ROOT .'/db/solid.db';
const KEYDIR = STORAGE_ROOT .'/keys/';
const PROFILEBASE = STORAGE_ROOT .'/profiles/';
const STORAGEBASE = STORAGE_ROOT .'/pods/';

const FRONTENDDIR = '/opt/solid/frontend/';
const LIBDIR = '/opt/solid/lib/';

const BANNED_PASSWORDS = [];
const BASEDOMAIN = 'solid.localhost';
const BASEURL = 'https://' . BASEDOMAIN;
const MAILER = ['host' => 'mailpit', 'port' => 1025, 'from' => 'accounts@example.com'];
const MAILSTYLES = [
    'call-to-action' => ['backgroundColor' => '#fff', 'color' => '#111', 'colorMuted' => '#333', 'buttonTextColor' => '#fff', 'buttonBackgroundColor' => '#369'],
    'container' => ['backgroundColor' => '#eeeeee'],
    'footer' => ['backgroundColor' => '#333', 'color' => '#e8ecf0'],
    'header' => ['backgroundColor' => '#333'],
];
const MINIMUM_PASSWORD_ENTROPY = 1;
const PODPRO_COMPATIBILITY = false;
const PUBSUB_SERVER = 'wss://pubsub:8080';
const TRUSTED_APPS = [
    'http://172.19.0.2',
    'http://172.19.0.3',
    'http://172.19.0.4',
    'http://172.19.0.4/client_id.json',
];
const TRUSTED_IPS = [];

const API_KEYS = [
    'http://172.19.0.2' => '0a1b2c3d4e5f6g7h8i9j0k', // Local Development
];
