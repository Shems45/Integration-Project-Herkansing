# Integration-Project-Herkansing

## Project Description

This project is a local integration setup for Odoo, WordPress, and RabbitMQ. The full solution will run on one Linux virtual machine with Docker.

Communication between the systems will later happen through RabbitMQ using XML messages.

## Requirements

- One Linux virtual machine
- Docker
- Docker Compose
- Git
- SSH client
- Web browser

## Local VM Setup

1. Start your Linux virtual machine.
2. Find the VM IP address with `ip a`.
3. Connect from the host machine with `ssh <username>@<vm-ip>`.
4. Clone the repository on the VM.
5. Start the containers with `docker compose up -d`.

Example service URLs:

- WordPress: `http://<vm-ip>:8080`
- Odoo: `http://<vm-ip>:8069`
- RabbitMQ Management UI: `http://<vm-ip>:15672`

## Initial Structure

This repository contains a simple local Docker Compose setup for Odoo, WordPress, and RabbitMQ.

Run it from the project root with:

```bash
docker compose up -d
```

## Docker Compose Stack

The local stack runs on one Linux VM and includes:

- Odoo with a PostgreSQL database
- WordPress with a MySQL database
- RabbitMQ with the management UI enabled

All services share one Docker network and use volumes for persistent data.

## Odoo Sender Flow

Product events from Odoo follow this path:

1. Odoo product hook in custom addon (`product_event_hooks`)
2. HTTP POST to internal service `odoo_sender` (`/odoo-product-event`)
3. `odoo_sender` converts payload to XML
4. `odoo_sender` publishes XML to RabbitMQ topic exchange `product.events`
5. Routing keys:
	- `odoo.product.created`
	- `odoo.product.updated`
	- `odoo.product.deleted`
6. All keys are bound to queue `wordpress.product.events`

Sync identifier rules:

- `product_central_id` is the stable sync identifier.
- In Odoo, `product.template.default_code` stores `product_central_id`.
- XML contains only `<productCentralId>...</productCentralId>` as identifier.
- Odoo internal database `product.id` is never sent in XML.

Currency rule:

- Prices are sent with `currency="EUR"` in XML.
- Set your Odoo company currency to EUR in the Odoo UI.

## Canonical Product XML Schema

Both senders now publish the same XML schema to RabbitMQ.

- `wp_sender` sets `<source>wordpress</source>`
- `odoo_sender` sets `<source>odoo</source>`

Canonical XML example:

```xml
<?xml version='1.0' encoding='utf-8'?>
<productEvent>
	<source>wordpress</source>
	<action>created</action>
	<product>
		<productCentralId>WP-000001</productCentralId>
		<name>Example product</name>
		<price currency="EUR">12.00</price>
		<quantity>5.00</quantity>
		<description>Example description</description>
		<availableInPos>true</availableInPos>
		<active>true</active>
	</product>
</productEvent>
```

Identifier rule:

- The only sync identifier is `product_central_id`.
- In XML this is always `<productCentralId>...</productCentralId>`.
- Local system IDs (WordPress ID, Odoo ID) are not used as sync identifiers in XML.

ID generation rule:

- Odoo-created products use `ODOO-000001` style IDs generated with `ir.sequence` (`product.central.id`), which is safe for concurrent creation.
- WordPress-created products use `WP-000001` style IDs generated from the inserted row `id` plus a unique DB constraint.
- Prefixes `ODOO-` and `WP-` avoid collisions between products created in different systems.

## WordPress Receiver Flow

Incoming Odoo XML messages for WordPress follow this path:

1. RabbitMQ queue `wordpress.product.events`
2. `wp_receiver` service consumes XML messages
3. `wp_receiver` parses XML `productEvent`
4. `wp_receiver` sends JSON to WordPress plugin endpoint:
	`/wp-json/product-sync/v1/odoo-product-event`
5. WordPress plugin updates product table by `product_central_id`

Security:

- `wp_receiver` sends header `X-Product-Sync-Token`.
- WordPress validates token before applying create, update, or delete.

Loop prevention:

- Odoo-origin updates are applied directly in WordPress table through the REST endpoint.
- These integration updates skip outbound sync to `wp_sender`, preventing ping-pong loops.

Main ports:

- WordPress: `8080`
- Odoo: `8069`
- RabbitMQ AMQP: `5672`
- RabbitMQ Management UI: `15672`

## XML Validation And Message Security

This project now adds a simple security layer while keeping the same architecture:

- Sender services (`wp_sender`, `odoo_sender`) generate canonical XML and validate it against `schemas/product_event.xsd`.
- If XML validation fails in a sender, the event is not published and the endpoint returns HTTP 400.
- Senders sign the exact XML bytes using HMAC SHA256 with `INTEGRATION_SECRET`.
- RabbitMQ messages include headers:
	- `x-signature`
	- `x-source`
	- `x-message-type=productEvent`
- Receiver services (`wp_receiver`, `odoo_receiver`) verify HMAC signature first, then validate XML against the XSD.
- Invalid or unsigned messages are not processed and are rejected without requeue.
- Sender HTTP endpoints are protected with `X-Integration-Token`, validated against `INTEGRATION_HTTP_TOKEN`.

Environment variables used:

- `INTEGRATION_SECRET=change-me-school-secret`
- `INTEGRATION_HTTP_TOKEN=school-project-token`

Sync identifier rule remains unchanged:

- `product_central_id` is still the only shared sync identifier between systems.