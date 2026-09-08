# MEENT ️Webhook Usage

This is a simple webhook that can be used by the [Smart-Stuff](https://smart-stuff.nl) P1 dongle to write data to a Solid Pod.

There are five things needed to work with this WebHook:

1. **Pod** A user must have a Solid Pod (including a WebID)
2. **Consent** The Webhook must have consent to write data to the Pod
3. **WebID** The dongle must have a WebId URL
4. **API Key** The dongle must register the WebId URL to receive an API key
5. **Write** The dongle can then use the API key to send data to the Webhook

## Create a Pod

The dongle can create a Solid Pod automatically by providing the webhook with a dongle's MAC Address using [the Pod creation endpoint](/api/pod/).

The MAC Address is used as the password. The username is an email be created from the WebID URL.

For instance, for a Web ID `https://id-a0b1c2d3e4f5a6b7c8d9e0fa0b1c2d3e.solid-01.muze.nl/`,
the email address will be `a0b1c2d3e4f5a6b7c8d9e0fa0b1c2d3e@energiemeent.nl`.

Alternatively, a solid Pod can be created manually by visiting https://solid-01.muze.nl/register/ or any of the providers listed at https://solidproject.org/get_a_pod.

## Consent

For Pods created at https://energiemeent.nl consent does not need to be provided, as the Webhook is configured as a trusted application.

When using another Solid Server, the Webhook can be given consent by providing a WebID URL to [the consent endpoint](/api/consent).

## Web ID

The WebID URL also has to be provided to the Dongle.

For automatically created Pods, the WebID URL is returned when a pod is created, so the dongle can store it for later use.

For manually created Pods, this can be done by filling the WebID URL into the P1 Dongle configuration.

## Retrieve an API Key

With the WebID URL, the dongle can request an API key from the Webhook using [the registration endpoint](/api/register/).

## Write Data

With the API key, the dongle can send data to the Webhook using [the data endpoint](/api/data/).
