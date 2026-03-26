# MEENT Webhook

Webhook for writing data from a P1 dongle as Linked Data to a Solid Pod.

## Installation

For regular use of the webhook, installation is not required.
Visit the webhook at: https://meent.dev.muze.nl/

## Usage

The webhook consists of [a REST API][1] and accompanying [documentation][2].

To send data to the Meent Webhook, an API key is required.

Such an API key can be retrieved by registering a WebID.

The API key is coupled to the provided WebID URL. The WebID URL can be updated if it changes.
It is currently not possible to connect more than one WebID to an API key, or to update (or delete) an API key.

A [WebID][3] is a URL where a WebID Document can be found.
In the WebID Document, as specified by the [Solid WebID Profile][4] specification,
information can be found as to which Identity Provider ([`solid:oidcIssuer`][5]) and Storage Provider ([`pim:storage`][6]) to use for data storage.

To store data in a Solid Pod, a POST request can be sent to the data endpoint, using the API key as the Authorization header.
The posted data can also be retrieved, which also requires the API key.
Currently, data cannot be updated or deleted.

[1]: https://meent.dev.muze.nl/api/
[2]: https://meent.dev.muze.nl/docs/
[3]: https://w3c-cg.github.io/WebID/spec/identity/
[4]: https://solid.github.io/webid-profile/
[5]: https://solidproject.org/TR/oidc#oidc-issuer-discovery
[6]: https://www.w3.org/ns/pim/space#Storage
