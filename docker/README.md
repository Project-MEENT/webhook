# Docker Setup

An image of the Webhook `Dockerfile` is built using GitHub Actions (GHA) and pushed to the GitHub Container Registry (ghcr.io).

For details see `.github/workflows/docker-build-push.yml`).

## In production

This image is deployed in production to https://energiemeent.nl using the `docker-compose.yml` file in this directory.

## Local Development

### Webhook

The Webhook can be run locally for development using `docker-compose.dev.yml`.
By default, the `config.php.example` file in the root of the repository is used to configure the Webhook.

The output of the `docker compose up` command shows which IP the webhook is running on.

### Solid Server

As development usually also requires a Solid Server, a docker-compose file is also provided: `docker-compose.solid-server.yml`.
The `solid-server.apache.conf` and `solid-server.config.php` files in this directory are used to configure the Solid Server.

The Solid server will run on https://solid.localhost.

The command to run the docker-compose files from the root of this repository is:

```sh
docker compose \
    --file ./docker/docker-compose.dev.yml \
    --file ./docker/docker-compose.solid-server.yml \
    up
```

## Configuration

For the Webhook to not need user's consent, it must be added to the `TRUSTED_APPS` array in Solid server's config file.
For instance, for locla development, `const TRUSTED_APPS' = ['http://172.19.0.2'];`

For the Webhook to be able to automatically create Solid Pods (based on a MAC address), three things need to be configured:

1. In the Solid Server's config file, `API_KEYS` must contain a URL with en entry containing the URL of the Webhook and an accompanying API key.<br>
   For instance `const API_KEYS = ['http://172.19.0.2' => '0a1b2c3d4e5f6g7h8i9j0k'];`
2. In the Webhook's config file, a `pod_creation_key` entry must be added with the same value as the API key in the Solid Server's config file.<br>
   For instance `'pod_creation_key' => '0a1b2c3d4e5f6g7h8i9j0k',`
3. In the Webhook's config file, a `pod_creation_url` entry must be added with the URL of the Solid Server's API endpoint for creating Solid Pods.<br>
   For instance `'pod_creation_url' => 'https://solid.localhost/api/accounts/create',`

