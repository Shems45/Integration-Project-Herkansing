import os
import hmac
import hashlib
import logging
import xml.etree.ElementTree as ET

import pika
from flask import Flask, jsonify, request
from lxml import etree

app = Flask(__name__)

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
)
logger = logging.getLogger(__name__)

RABBITMQ_HOST = os.getenv("RABBITMQ_HOST", "rabbitmq")
RABBITMQ_PORT = int(os.getenv("RABBITMQ_PORT", "5672"))
RABBITMQ_USER = os.getenv("RABBITMQ_USER", "admin")
RABBITMQ_PASS = os.getenv("RABBITMQ_PASS", "admin")
RABBITMQ_EXCHANGE = os.getenv("RABBITMQ_EXCHANGE", "product.events")
RABBITMQ_QUEUE = os.getenv("RABBITMQ_QUEUE", "odoo.product.events")
INTEGRATION_SECRET = os.getenv("INTEGRATION_SECRET", "change-me-school-secret")
INTEGRATION_HTTP_TOKEN = os.getenv("INTEGRATION_HTTP_TOKEN", "school-project-token")
XSD_PATH = os.getenv("PRODUCT_EVENT_XSD_PATH", "/app/schemas/product_event.xsd")

ROUTING_KEYS = {
    "created": "wordpress.product.created",
    "updated": "wordpress.product.updated",
    "deleted": "wordpress.product.deleted",
}


def load_xml_schema():
    with open(XSD_PATH, "rb") as schema_file:
        schema_doc = etree.parse(schema_file)
    return etree.XMLSchema(schema_doc)


XML_SCHEMA = load_xml_schema()


def as_bool(value, default=True):
    if value is None:
        return default
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return bool(value)
    if isinstance(value, str):
        return value.strip().lower() in {"1", "true", "yes", "on"}
    return default


def as_decimal_string(value, default="0.00"):
    try:
        if isinstance(value, str):
            value = value.replace(",", ".")
        return f"{float(value):.2f}"
    except (TypeError, ValueError):
        return default


def product_event_to_xml(payload):
    # Canonical schema shared by both systems: productEvent -> product -> productCentralId
    root = ET.Element("productEvent")
    ET.SubElement(root, "source").text = "wordpress"
    ET.SubElement(root, "action").text = str(payload.get("action", ""))

    product = ET.SubElement(root, "product")
    ET.SubElement(product, "productCentralId").text = str(payload.get("product_central_id", ""))
    ET.SubElement(product, "name").text = str(payload.get("name", ""))

    price = ET.SubElement(product, "price")
    price.set("currency", "EUR")
    price.text = as_decimal_string(payload.get("price", 0), default="0.00")

    ET.SubElement(product, "quantity").text = as_decimal_string(payload.get("quantity", 0), default="0.00")
    ET.SubElement(product, "description").text = str(payload.get("description", ""))
    ET.SubElement(product, "availableInPos").text = "true" if as_bool(payload.get("available_in_pos", True), default=True) else "false"
    ET.SubElement(product, "active").text = "true" if as_bool(payload.get("active", True), default=True) else "false"

    return ET.tostring(root, encoding="utf-8", xml_declaration=True)


def validate_xml_message(xml_message):
    try:
        xml_doc = etree.fromstring(xml_message)
        XML_SCHEMA.assertValid(xml_doc)
        logger.info("XML validation success (wp_sender)")
        return True, ""
    except (etree.XMLSyntaxError, etree.DocumentInvalid) as exc:
        logger.error("XML validation failure (wp_sender): %s", exc)
        return False, str(exc)


def create_signature(xml_message):
    signature = hmac.new(
        INTEGRATION_SECRET.encode("utf-8"),
        xml_message,
        hashlib.sha256,
    ).hexdigest()
    logger.info("HMAC signature created (wp_sender)")
    return signature


def publish_event(action, xml_message):
    credentials = pika.PlainCredentials(RABBITMQ_USER, RABBITMQ_PASS)
    parameters = pika.ConnectionParameters(
        host=RABBITMQ_HOST,
        port=RABBITMQ_PORT,
        credentials=credentials,
    )

    routing_key = ROUTING_KEYS[action]

    connection = pika.BlockingConnection(parameters)
    channel = connection.channel()

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

    signature = create_signature(xml_message)

    channel.basic_publish(
        exchange=RABBITMQ_EXCHANGE,
        routing_key=routing_key,
        body=xml_message,
        properties=pika.BasicProperties(
            content_type="application/xml",
            delivery_mode=2,
            headers={
                "x-signature": signature,
                "x-source": "wordpress",
                "x-message-type": "productEvent",
            },
        ),
    )

    logger.info("Published message with signature headers (wp_sender)")

    connection.close()


@app.post("/product-event")
def product_event():
    provided_token = request.headers.get("X-Integration-Token", "")
    if not provided_token:
        logger.error("Missing token on /product-event")
        return jsonify({"error": "Missing integration token"}), 403

    if not hmac.compare_digest(provided_token, INTEGRATION_HTTP_TOKEN):
        logger.error("Invalid token on /product-event")
        return jsonify({"error": "Invalid integration token"}), 403

    data = request.get_json(silent=True) or {}

    action = str(data.get("action", "")).strip().lower()
    if action not in ROUTING_KEYS:
        return jsonify({"error": "Invalid action. Use created, updated, or deleted."}), 400

    payload = {
        "action": action,
        "product_central_id": data.get("product_central_id", ""),
        "name": data.get("name", ""),
        "price": data.get("price", ""),
        "quantity": data.get("quantity", ""),
        "description": data.get("description", ""),
        "available_in_pos": data.get("available_in_pos", True),
        "active": data.get("active", True),
    }

    try:
        xml_message = product_event_to_xml(payload)
        is_valid, validation_error = validate_xml_message(xml_message)
        if not is_valid:
            return jsonify({"error": "XML validation failed", "details": validation_error}), 400
        publish_event(action, xml_message)
    except Exception as exc:
        return jsonify({"error": "Failed to publish event", "details": str(exc)}), 500

    return jsonify({"status": "ok", "routing_key": ROUTING_KEYS[action]}), 200


@app.get("/health")
def health():
    return jsonify({"status": "ok"}), 200


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8000)
