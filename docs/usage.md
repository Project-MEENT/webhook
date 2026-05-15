   # MEENT ️Webhook Usage

This is a simple webhook that can be used by the [Smart-Stuff](https://smart-stuff.nl) P1 dongle to write data to a Solid Pod.

There are five things needed to work with this WebHook:

1. **Pod** A user must have a Solid Pod
2. **Consent** A user must grant the Webhook permission to write to their Pod
3. **WebID** A user must provide the Dongle with a WebId URL
4. **API Key** The dongle must register the WebId URL to receive an API key
5. **Write** The dongle can then use the API key to send data to the Webhook

## Create a Pod

A solid Pod can be created by visiting https://solid-01.muze.nl/register/ or any of the providers listed at https://solidproject.org/get_a_pod.

As part of Pod creation, the user will receive a WebID URL.

## Provide Consent

Once a user has a WebID URL, they can provide consent to the Webhook to write data to their Pod.

This can be done by providing a WebID URL to [the consent endpoint](/api/consent).

## Configure the Dongle

The WebID URL also has to be provided to the Dongle. This can be done by filling the WebID URL into the P1 Dongle configuration.

## Retrieve an API Key

With the WebID URL, the dongle can request an API key from the Webhook using [the registration endpoint](/api/register/). A form to do this with is also present at the registration endpoint.

## Write Data

With the API key, the dongle can send data to the Webhook using [the data endpoint](/api/data/). This endpoint also has a form that provides this functionality.
