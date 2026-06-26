import os
import xml.etree.ElementTree as ET

import pika
from flask import Flask, jsonify, request

app = Flask(__name__)

RABBITMQ_HOST = os.getenv("RABBITMQ_HOST", "rabbitmq")
RABBITMQ_PORT = int(os.getenv("RABBITMQ_PORT", "5672"))
RABBITMQ_USER = os.getenv("RABBITMQ_USER", "admin")
RABBITMQ_PASS = os.getenv("RABBITMQ_PASS", "admin")
RABBITMQ_EXCHANGE = os.getenv("RABBITMQ_EXCHANGE", "product.events")
RABBITMQ_QUEUE = os.getenv("RABBITMQ_QUEUE", "odoo.product.events")

ROUTING_KEYS = {
    "created": "wordpress.product.created",
    "updated": "wordpress.product.updated",
    "deleted": "wordpress.product.deleted",
}


def product_event_to_xml(payload):
    root = ET.Element("wordpress_product_event")

    ET.SubElement(root, "action").text = str(payload.get("action", ""))
    ET.SubElement(root, "id").text = str(payload.get("id", ""))
    ET.SubElement(root, "name").text = str(payload.get("name", ""))
    ET.SubElement(root, "price").text = str(payload.get("price", ""))
    ET.SubElement(root, "quantity").text = str(payload.get("quantity", ""))
    ET.SubElement(root, "description").text = str(payload.get("description", ""))

    return ET.tostring(root, encoding="utf-8", xml_declaration=True)


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


@app.post("/product-event")
def product_event():
    data = request.get_json(silent=True) or {}

    action = str(data.get("action", "")).strip().lower()
    if action not in ROUTING_KEYS:
        return jsonify({"error": "Invalid action. Use created, updated, or deleted."}), 400

    payload = {
        "action": action,
        "id": data.get("id", ""),
        "name": data.get("name", ""),
        "price": data.get("price", ""),
        "quantity": data.get("quantity", ""),
        "description": data.get("description", ""),
    }

    try:
        xml_message = product_event_to_xml(payload)
        publish_event(action, xml_message)
    except Exception as exc:
        return jsonify({"error": "Failed to publish event", "details": str(exc)}), 500

    return jsonify({"status": "ok", "routing_key": ROUTING_KEYS[action]}), 200


@app.get("/health")
def health():
    return jsonify({"status": "ok"}), 200


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8000)
