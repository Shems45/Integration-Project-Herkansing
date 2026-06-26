import json
import logging
import os
import time
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET

import pika

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
)
logger = logging.getLogger(__name__)

RABBITMQ_HOST = os.getenv("RABBITMQ_HOST", "rabbitmq")
RABBITMQ_PORT = int(os.getenv("RABBITMQ_PORT", "5672"))
RABBITMQ_USER = os.getenv("RABBITMQ_USER", "admin")
RABBITMQ_PASSWORD = os.getenv("RABBITMQ_PASSWORD", "admin")
RABBITMQ_QUEUE = os.getenv("RABBITMQ_QUEUE", "wordpress.product.events")
RABBITMQ_RETRY_INITIAL_DELAY = int(os.getenv("RABBITMQ_RETRY_INITIAL_DELAY", "2"))
RABBITMQ_RETRY_MAX_DELAY = int(os.getenv("RABBITMQ_RETRY_MAX_DELAY", "30"))

WORDPRESS_URL = os.getenv("WORDPRESS_URL", "http://wordpress")
WORDPRESS_SYNC_ENDPOINT = os.getenv(
    "WORDPRESS_SYNC_ENDPOINT",
    "http://wordpress/wp-json/product-sync/v1/odoo-product-event",
)
WORDPRESS_SYNC_TOKEN = os.getenv("WORDPRESS_SYNC_TOKEN", "school-project-token")


class WordPressReceiver:
    """RabbitMQ XML message -> wp_receiver -> WordPress REST endpoint -> WordPress product table."""

    def __init__(self):
        self.connection = None
        self.channel = None

    def connect(self):
        credentials = pika.PlainCredentials(RABBITMQ_USER, RABBITMQ_PASSWORD)
        parameters = pika.ConnectionParameters(
            host=RABBITMQ_HOST,
            port=RABBITMQ_PORT,
            credentials=credentials,
            heartbeat=30,
            blocked_connection_timeout=60,
        )

        self.connection = pika.BlockingConnection(parameters)
        self.channel = self.connection.channel()
        self.channel.queue_declare(queue=RABBITMQ_QUEUE, durable=True)

        logger.info(
            "Connected to RabbitMQ host=%s queue=%s and waiting for messages",
            RABBITMQ_HOST,
            RABBITMQ_QUEUE,
        )

    def connect_with_retry(self):
        delay = max(1, RABBITMQ_RETRY_INITIAL_DELAY)
        max_delay = max(delay, RABBITMQ_RETRY_MAX_DELAY)

        while True:
            try:
                self.connect()
                return
            except pika.exceptions.AMQPError as exc:
                logger.warning(
                    "RabbitMQ connect failed (%s). Retrying in %ss",
                    exc,
                    delay,
                )
                time.sleep(delay)
                delay = min(delay * 2, max_delay)

    def parse_xml_message(self, body):
        root = ET.fromstring(body)

        if root.tag != "productEvent":
            raise ValueError("Unexpected root element, expected productEvent")

        action = (root.findtext("action") or "").strip().lower()
        product_node = root.find("product")
        if product_node is None:
            raise ValueError("Missing product node")

        product_central_id = (product_node.findtext("productCentralId") or "").strip()
        if not product_central_id:
            raise ValueError("Missing productCentralId")

        price_node = product_node.find("price")
        price_currency = (price_node.attrib.get("currency", "") if price_node is not None else "").upper()
        price_text = (price_node.text if price_node is not None else "0") or "0"

        # Prices are treated as EUR in integration flow.
        if price_currency and price_currency != "EUR":
            logger.warning("Received non-EUR price currency=%s; still forwarding numeric value", price_currency)

        payload = {
            "action": action,
            "product_central_id": product_central_id,
            "name": (product_node.findtext("name") or "").strip(),
            "price": self._to_float(price_text, 0.0),
            "quantity": self._to_float((product_node.findtext("quantity") or "0").strip(), 0.0),
            "description": (product_node.findtext("description") or "").strip(),
            "available_in_pos": self._to_bool(product_node.findtext("availableInPos")),
            "active": self._to_bool(product_node.findtext("active"), default=True),
        }

        logger.info(
            "Parsed XML message action=%s product_central_id=%s",
            payload["action"],
            payload["product_central_id"],
        )
        return payload

    def send_to_wordpress(self, payload):
        request_data = json.dumps(payload).encode("utf-8")
        request = urllib.request.Request(
            WORDPRESS_SYNC_ENDPOINT,
            data=request_data,
            headers={
                "Content-Type": "application/json",
                "X-Product-Sync-Token": WORDPRESS_SYNC_TOKEN,
            },
            method="POST",
        )

        logger.info(
            "Sending payload to WordPress endpoint action=%s product_central_id=%s",
            payload.get("action"),
            payload.get("product_central_id"),
        )

        with urllib.request.urlopen(request, timeout=10) as response:
            body = response.read().decode("utf-8", errors="ignore")
            status = response.getcode()
            if status < 200 or status >= 300:
                raise RuntimeError(f"WordPress sync returned HTTP {status}: {body}")

            content_type = (response.headers.get("Content-Type") or "").lower()
            if "application/json" not in content_type:
                raise RuntimeError(
                    f"WordPress sync returned unexpected content type '{content_type}': {body[:200]}"
                )

            try:
                response_json = json.loads(body)
            except json.JSONDecodeError as exc:
                raise RuntimeError(f"WordPress sync returned invalid JSON: {body[:200]}") from exc

            if not isinstance(response_json, dict) or not response_json.get("ok"):
                raise RuntimeError(f"WordPress sync returned non-ok payload: {body[:200]}")

            logger.info("WordPress sync succeeded with HTTP %s", status)

    def on_message(self, channel, method, properties, body):
        logger.info("Received RabbitMQ message routing_key=%s", method.routing_key)

        try:
            payload = self.parse_xml_message(body)
            self.send_to_wordpress(payload)
            channel.basic_ack(delivery_tag=method.delivery_tag)
        except (ET.ParseError, ValueError) as exc:
            logger.error("XML parse/validation error: %s", exc)
            channel.basic_nack(delivery_tag=method.delivery_tag, requeue=False)
        except (urllib.error.HTTPError, urllib.error.URLError, RuntimeError) as exc:
            logger.error("WordPress request error: %s", exc)
            channel.basic_nack(delivery_tag=method.delivery_tag, requeue=False)
        except Exception as exc:
            logger.exception("Unexpected error while processing message: %s", exc)
            channel.basic_nack(delivery_tag=method.delivery_tag, requeue=False)

    def consume(self):
        self.channel.basic_qos(prefetch_count=1)
        self.channel.basic_consume(
            queue=RABBITMQ_QUEUE,
            on_message_callback=self.on_message,
            auto_ack=False,
        )
        logger.info("Started consuming queue=%s", RABBITMQ_QUEUE)
        self.channel.start_consuming()

    def close(self):
        if self.channel and getattr(self.channel, "is_open", False):
            try:
                self.channel.close()
            except Exception:
                logger.debug("Channel close ignored during cleanup", exc_info=True)

        if self.connection and getattr(self.connection, "is_open", False):
            try:
                self.connection.close()
            except Exception:
                logger.debug("Connection close ignored during cleanup", exc_info=True)

        self.channel = None
        self.connection = None

    def run(self):
        while True:
            try:
                self.connect_with_retry()
                self.consume()
            except KeyboardInterrupt:
                logger.info("wp_receiver interrupted, shutting down")
                self.close()
                break
            except pika.exceptions.AMQPError as exc:
                logger.warning("RabbitMQ connection interrupted: %s", exc)
                self.close()
                time.sleep(2)
            except Exception:
                logger.exception("Unexpected fatal error in receiver loop")
                self.close()
                time.sleep(2)

    @staticmethod
    def _to_float(value, default=0.0):
        try:
            if isinstance(value, str):
                value = value.replace(",", ".")
            return float(value)
        except (TypeError, ValueError):
            return default

    @staticmethod
    def _to_bool(value, default=False):
        if value is None:
            return default
        text = str(value).strip().lower()
        return text in {"1", "true", "yes", "on"}


if __name__ == "__main__":
    logger.info(
        "Starting wp_receiver service with WORDPRESS_URL=%s endpoint=%s",
        WORDPRESS_URL,
        WORDPRESS_SYNC_ENDPOINT,
    )
    receiver = WordPressReceiver()
    receiver.run()
