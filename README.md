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

Main ports:

- WordPress: `8080`
- Odoo: `8069`
- RabbitMQ AMQP: `5672`
- RabbitMQ Management UI: `15672`