import logging
import os
import xml.etree.ElementTree as ET

import pika
from flask import Flask, jsonify, request

app = Flask(__name__)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
)
logger = logging.getLogger(__name__)

RABBITMQ_HOST = os.getenv("RABBITMQ_HOST", "rabbitmq")
RABBITMQ_PORT = int(os.getenv("RABBITMQ_PORT", "5672"))
RABBITMQ_USER = os.getenv("RABBITMQ_USER", "admin")
RABBITMQ_PASSWORD = os.getenv("RABBITMQ_PASSWORD", os.getenv("RABBITMQ_PASS", "admin"))
RABBITMQ_EXCHANGE = os.getenv("RABBITMQ_EXCHANGE", "product.events")
RABBITMQ_QUEUE = os.getenv("RABBITMQ_QUEUE", "wordpress.product.events")

ROUTING_KEYS = {
    "created": "odoo.product.created",
    "updated": "odoo.product.updated",
    "deleted": "odoo.product.deleted",
}


def as_bool(value):
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return bool(value)
    if isinstance(value, str):
        return value.strip().lower() in {"1", "true", "yes", "on"}
    return False


def as_decimal_string(value, default="0.00"):
    try:
        if isinstance(value, str):
            value = value.replace(",", ".")
        return f"{float(value):.2f}"
    except (TypeError, ValueError):
        return default


def product_event_to_xml(payload):
    root = ET.Element("productEvent")
    ET.SubElement(root, "source").text = "odoo"
    ET.SubElement(root, "action").text = str(payload.get("action", ""))

    product = ET.SubElement(root, "product")
    ET.SubElement(product, "productCentralId").text = str(payload.get("product_central_id", ""))
    ET.SubElement(product, "name").text = str(payload.get("name", ""))

    price = ET.SubElement(product, "price")
    price.set("currency", "EUR")
    price.text = as_decimal_string(payload.get("price", 0), default="0.00")

    ET.SubElement(product, "quantity").text = as_decimal_string(payload.get("quantity", 0), default="0.00")
    ET.SubElement(product, "description").text = str(payload.get("description", ""))
    ET.SubElement(product, "availableInPos").text = "true" if as_bool(payload.get("available_in_pos", False)) else "false"
    ET.SubElement(product, "active").text = "true" if as_bool(payload.get("active", True)) else "false"

    return ET.tostring(root, encoding="utf-8", xml_declaration=False)


def publish_event(action, xml_message):
    credentials = pika.PlainCredentials(RABBITMQ_USER, RABBITMQ_PASSWORD)
    parameters = pika.ConnectionParameters(
        host=RABBITMQ_HOST,
        port=RABBITMQ_PORT,
        credentials=credentials,
    )

    routing_key = ROUTING_KEYS[action]

    connection = pika.BlockingConnection(parameters)
    channel = connection.channel()

    # Flow: Odoo hook -> odoo_sender -> XML -> RabbitMQ topic exchange -> wordpress.product.events queue
    channel.exchange_declare(
        exchange=RABBITMQ_EXCHANGE,
        exchange_type="topic",
        durable=True,
    )
    channel.queue_declare(queue=RABBITMQ_QUEUE, durable=True)

    for key in ROUTING_KEYS.values():
        channel.queue_bind(
            exchange=RABBITMQ_EXCHANGE,
            queue=RABBITMQ_QUEUE,
            routing_key=key,
        )

    channel.basic_publish(
        exchange=RABBITMQ_EXCHANGE,
        routing_key=routing_key,
        body=xml_message,
        properties=pika.BasicProperties(
            content_type="application/xml",
            delivery_mode=2,
        ),
    )

    connection.close()


@app.post("/odoo-product-event")
def odoo_product_event():
    data = request.get_json(silent=True) or {}

    action = str(data.get("action", "")).strip().lower()
    if action not in ROUTING_KEYS:
        return jsonify({"error": "Invalid action. Use created, updated, or deleted."}), 400

    product_central_id = str(data.get("product_central_id", "")).strip()
    if not product_central_id:
        return jsonify({"error": "product_central_id is required."}), 400

    payload = {
        "action": action,
        "product_central_id": product_central_id,
        "name": data.get("name", ""),
        "price": data.get("price", 0),
        "quantity": data.get("quantity", 0),
        "description": data.get("description", ""),
        "available_in_pos": data.get("available_in_pos", False),
        "active": data.get("active", True),
    }

    try:
        logger.info(
            "Received Odoo product event: action=%s product_central_id=%s",
            action,
            product_central_id,
        )
        xml_message = product_event_to_xml(payload)
        logger.info("Generated XML for product_central_id=%s", product_central_id)
        publish_event(action, xml_message)
        logger.info(
            "Published RabbitMQ message: exchange=%s routing_key=%s queue=%s",
            RABBITMQ_EXCHANGE,
            ROUTING_KEYS[action],
            RABBITMQ_QUEUE,
        )
    except Exception as exc:
        logger.exception("Failed to publish Odoo product event")
        return jsonify({"error": "Failed to publish event", "details": str(exc)}), 500

    return jsonify({"status": "ok", "routing_key": ROUTING_KEYS[action]}), 200


@app.get("/health")
def health():
    return jsonify({"status": "ok"}), 200


if __name__ == "__main__":
    logger.info("Starting odoo_sender service...")
    app.run(host="0.0.0.0", port=8000)
