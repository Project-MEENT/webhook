<?php

/**
 * In the finished product, the P1 dongle will write linked-data directly to the Solid Pod.
 *
 * However, for development purposes, we will create a webhook endpoint that the P1 dongle can write to.
 * This endpoint will then process the incoming data and write it to the Solid Pod on behalf of the user.
 *
 * This gives us a working space to develop an ontology in and provides a working example of the logic the dongle will eventually have to implement.
 *
 * As this webhook is only short-lived, it should not receive more than minimal attention.
 * It should also, where possible, mirror the behavior of the real endpoint (see the Solid Specs for more info).
 */

// Receive incoming data

// Check Authentication

// @TODO: Convert to Linked-Data once ontology is decided upon

// Check which Solid Pod to write to

// Connect to Solid Pod (using ?)

// Write data to Solid Pod

// Output response
