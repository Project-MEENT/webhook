# MEENT Solid P1 Dongle Webhook

Webhook for writing data from a P1 dongle as Linked Data to a Solid Pod.

## Installation

For regular use of the webhook, installation is not required.
Visit the webhook at: https://meent.dev.muze.nl/

## Usage

The webhook consists of [a REST API][1] and accompanying [documentation][2].

There are five things needed to work with this WebHook:

1. **Pod** A user must have a Solid Pod
2. **Consent** A user must grant the Webhook permission to write to their Pod
3. **WebID** A user must provide the Dongle with a WebId URL
4. **API Key** The dongle must register the WebId URL to receive an API key
5. **Write** The dongle can then use the API key to send data to the Webhook

For more details visit the [usage documentation](./docs/usage.md).

## Contribute

At this point, contributions are not expected.

## License

[1]: https://meent.dev.muze.nl/api/
[2]: ./docs/
[3]: https://w3c-cg.github.io/WebID/spec/identity/
[4]: https://solid.github.io/webid-profile/
[5]: https://solidproject.org/TR/oidc#oidc-issuer-discovery
[6]: https://www.w3.org/ns/pim/space#Storage
