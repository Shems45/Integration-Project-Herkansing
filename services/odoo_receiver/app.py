import logging
import os
import time
import xml.etree.ElementTree as ET
from typing import Dict, Optional

import pika
import xmlrpc.client

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(name)s - %(levelname)s - %(message)s",
)
logger = logging.getLogger(__name__)

# Environment variables
RABBITMQ_HOST = os.getenv("RABBITMQ_HOST", "rabbitmq")
RABBITMQ_PORT = int(os.getenv("RABBITMQ_PORT", "5672"))
RABBITMQ_USER = os.getenv("RABBITMQ_USER", "admin")
RABBITMQ_PASS = os.getenv("RABBITMQ_PASS", "admin")
RABBITMQ_QUEUE = os.getenv("RABBITMQ_QUEUE", "odoo.product.events")

ODOO_URL = os.getenv("ODOO_URL", "http://odoo:8069")
ODOO_DB = os.getenv("ODOO_DB", "odoo")
ODOO_USERNAME = os.getenv("ODOO_USERNAME", "admin")
ODOO_PASSWORD = os.getenv("ODOO_PASSWORD", "admin")


class OdooReceiver:
    """Consumes product events from RabbitMQ and updates Odoo."""

    def __init__(self):
        """Initialize Odoo receiver with RabbitMQ and Odoo connections."""
        self.rabbitmq_connection = None
        self.rabbitmq_channel = None
        self.odoo_client = None
        self._product_central_id_field_supported = None
        self._product_template_fields = None

    def supports_product_central_id_field(self) -> bool:
        """Check whether product.template.product_central_id is available in Odoo."""
        if self._product_central_id_field_supported is not None:
            return self._product_central_id_field_supported

        try:
            uid = self._authenticate_odoo()
            models = xmlrpc.client.ServerProxy(f"{ODOO_URL}/xmlrpc/2/object")
            fields_info = models.execute_kw(
                ODOO_DB,
                uid,
                ODOO_PASSWORD,
                "product.template",
                "fields_get",
                [["product_central_id"]],
            )
            self._product_central_id_field_supported = "product_central_id" in fields_info
        except Exception:
            self._product_central_id_field_supported = False

        return self._product_central_id_field_supported

    def connect_rabbitmq(self):
        """Connect to RabbitMQ broker."""
        try:
            credentials = pika.PlainCredentials(RABBITMQ_USER, RABBITMQ_PASS)
            parameters = pika.ConnectionParameters(
                host=RABBITMQ_HOST,
                port=RABBITMQ_PORT,
                credentials=credentials,
            )
            self.rabbitmq_connection = pika.BlockingConnection(parameters)
            self.rabbitmq_channel = self.rabbitmq_connection.channel()
            logger.info(
                f"✓ Connected to RabbitMQ at {RABBITMQ_HOST}:{RABBITMQ_PORT}"
            )
        except Exception as e:
            logger.error(f"✗ Failed to connect to RabbitMQ: {e}")
            raise

    def connect_odoo(self, max_retries=10, initial_delay=2):
        """
        Connect to Odoo via XML-RPC API with exponential backoff retry.
        
        Args:
            max_retries: Maximum number of connection attempts
            initial_delay: Initial delay in seconds before first retry
        """
        attempt = 0
        delay = initial_delay
        
        while attempt < max_retries:
            try:
                # Test connection by attempting authentication
                common = xmlrpc.client.ServerProxy(f"{ODOO_URL}/xmlrpc/2/common")
                uid = common.authenticate(ODOO_DB, ODOO_USERNAME, ODOO_PASSWORD, {})
                if not uid:
                    raise Exception("Authentication failed: invalid credentials")
                
                # Initialize models proxy for later use
                self.odoo_client = xmlrpc.client.ServerProxy(f"{ODOO_URL}/xmlrpc/2/object")
                logger.info(f"✓ Connected to Odoo at {ODOO_URL}, authenticated as {ODOO_USERNAME}")
                return
            except Exception as e:
                attempt += 1
                if attempt < max_retries:
                    logger.warning(
                        f"⚠ Odoo connection attempt {attempt}/{max_retries} failed: {e}. "
                        f"Retrying in {delay}s..."
                    )
                    time.sleep(delay)
                    delay = min(delay * 2, 30)  # Exponential backoff, max 30s
                else:
                    logger.error(f"✗ Failed to connect to Odoo after {max_retries} attempts: {e}")
                    raise

    def parse_product_event_xml(self, xml_data: bytes) -> Dict[str, str]:
        """Parse canonical XML product event message."""
        try:
            root = ET.fromstring(xml_data)

            if root.tag != "productEvent":
                raise ValueError("Unexpected root element, expected productEvent")

            product_node = root.find("product")
            if product_node is None:
                raise ValueError("Missing product node")

            price_node = product_node.find("price")
            price_text = (price_node.text if price_node is not None else "0") or "0"

            product = {
                "action": root.findtext("action", "").strip(),
                "source": root.findtext("source", "").strip().lower(),
                "product_central_id": (product_node.findtext("productCentralId", "").strip()),
                "name": product_node.findtext("name", "").strip(),
                "price": price_text.strip(),
                "quantity": product_node.findtext("quantity", "").strip(),
                "description": product_node.findtext("description", "").strip(),
                "available_in_pos": product_node.findtext("availableInPos", "").strip(),
                "active": product_node.findtext("active", "").strip(),
            }
            logger.info(
                f"✓ Parsed XML message - Action: {product['action']}, "
                f"Name: {product['name']}, Source: {product['source']}, "
                f"Product Central ID: {product['product_central_id']}"
            )
            return product
        except (ET.ParseError, ValueError) as e:
            logger.error(f"✗ Failed to parse XML message: {e}")
            raise

    def find_product_in_odoo(self, product_central_id: str, product_name: str) -> Optional[int]:
        """
        Find a product in Odoo by product_central_id field.

        Searches by product_central_id first and then by name.
        Returns the product.template ID or None if not found.
        """
        try:
            uid = self._authenticate_odoo()
            models = xmlrpc.client.ServerProxy(f"{ODOO_URL}/xmlrpc/2/object")

            # First try to find by product_central_id field.
            if product_central_id and self.supports_product_central_id_field():
                product_ids = models.execute_kw(
                    ODOO_DB,
                    uid,
                    ODOO_PASSWORD,
                    "product.template",
                    "search",
                    [[("product_central_id", "=", product_central_id)]],
                )

                if product_ids:
                    logger.info(
                        f"✓ Found product by product central ID: {product_central_id} "
                        f"(Odoo ID: {product_ids[0]})"
                    )
                    return product_ids[0]

            # Fallback when central ID was stored only in internal reference.
            if product_central_id:
                product_ids = models.execute_kw(
                    ODOO_DB,
                    uid,
                    ODOO_PASSWORD,
                    "product.template",
                    "search",
                    [[("default_code", "=", product_central_id)]],
                )

                if product_ids:
                    logger.info(
                        f"✓ Found product by default_code central ID: {product_central_id} "
                        f"(Odoo ID: {product_ids[0]})"
                    )
                    return product_ids[0]

            # Fallback: search by product name
            product_ids = models.execute_kw(
                ODOO_DB,
                uid,
                ODOO_PASSWORD,
                "product.template",
                "search",
                [[("name", "=", product_name)]],
            )

            if product_ids:
                logger.info(
                    f"✓ Found product by name: {product_name} (Odoo ID: {product_ids[0]})"
                )
                return product_ids[0]

            logger.info(f"Product not found in Odoo: {product_name}")
            return None

        except Exception as e:
            logger.error(f"✗ Error finding product in Odoo: {e}")
            return None

    def _authenticate_odoo(self) -> int:
        """Authenticate with Odoo and return user ID."""
        common = xmlrpc.client.ServerProxy(f"{ODOO_URL}/xmlrpc/2/common")
        uid = common.authenticate(ODOO_DB, ODOO_USERNAME, ODOO_PASSWORD, {})
        if not uid:
            raise Exception("Failed to authenticate with Odoo")
        return uid

    def supports_product_template_field(self, field_name: str) -> bool:
        """Check whether a product.template field exists in current Odoo database."""
        if self._product_template_fields is None:
            try:
                uid = self._authenticate_odoo()
                models = xmlrpc.client.ServerProxy(f"{ODOO_URL}/xmlrpc/2/object")
                fields_info = models.execute_kw(
                    ODOO_DB,
                    uid,
                    ODOO_PASSWORD,
                    "product.template",
                    "fields_get",
                    [],
                    {"attributes": ["type"]},
                )
                self._product_template_fields = set(fields_info.keys())
            except Exception:
                self._product_template_fields = set()

        return field_name in self._product_template_fields

    def _safe_float(self, value: str, default: float = 0.0) -> float:
        """Parse a numeric string to float, accepting comma decimals."""
        try:
            if isinstance(value, str):
                value = value.replace(",", ".")
            return float(value)
        except (TypeError, ValueError):
            return default

    def _safe_int(self, value: str, default: int = 0) -> int:
        """Parse numeric string to int via float fallback."""
        try:
            if isinstance(value, str):
                value = value.replace(",", ".")
            return int(float(value))
        except (TypeError, ValueError):
            return default

    def set_on_hand_quantity(self, product_template_id: int, quantity: int) -> None:
        """Set on-hand inventory in an internal location for product variant."""
        try:
            uid = self._authenticate_odoo()
            models = xmlrpc.client.ServerProxy(f"{ODOO_URL}/xmlrpc/2/object")

            product_variant_ids = models.execute_kw(
                ODOO_DB,
                uid,
                ODOO_PASSWORD,
                "product.product",
                "search",
                [[("product_tmpl_id", "=", product_template_id)]],
                {"limit": 1},
            )
            if not product_variant_ids:
                return

            internal_location_ids = models.execute_kw(
                ODOO_DB,
                uid,
                ODOO_PASSWORD,
                "stock.location",
                "search",
                [[("usage", "=", "internal")]],
                {"limit": 1},
            )
            if not internal_location_ids:
                return

            quant_ids = models.execute_kw(
                ODOO_DB,
                uid,
                ODOO_PASSWORD,
                "stock.quant",
                "search",
                [[
                    ("product_id", "=", product_variant_ids[0]),
                    ("location_id", "=", internal_location_ids[0]),
                ]],
                {"limit": 1},
            )

            if quant_ids:
                models.execute_kw(
                    ODOO_DB,
                    uid,
                    ODOO_PASSWORD,
                    "stock.quant",
                    "write",
                    [[quant_ids[0]], {
                        "quantity": quantity,
                        "inventory_quantity": quantity,
                    }],
                )
            else:
                models.execute_kw(
                    ODOO_DB,
                    uid,
                    ODOO_PASSWORD,
                    "stock.quant",
                    "create",
                    [{
                        "product_id": product_variant_ids[0],
                        "location_id": internal_location_ids[0],
                        "quantity": quantity,
                        "inventory_quantity": quantity,
                    }],
                )

            logger.info(
                f"✓ Updated on-hand quantity to {quantity} "
                f"(Odoo template ID: {product_template_id})"
            )
        except Exception as e:
            logger.warning(f"Could not update on-hand quantity: {e}")

    def create_product(self, product: Dict[str, str]) -> Optional[int]:
        """Create a new product in Odoo with product_central_id stored in DB."""
        try:
            uid = self._authenticate_odoo()
            models = xmlrpc.client.ServerProxy(f"{ODOO_URL}/xmlrpc/2/object")

            product_central_id = product.get("product_central_id", "")

            # Prepare product data for Odoo
            product_data = {
                "name": product.get("name", "Unknown Product"),
                "type": "product",  # Standard product type
                "list_price": self._safe_float(product.get("price", 0), 0.0),
                # Keep shared sync identifier in default_code.
                "default_code": product_central_id,
            }

            description = product.get("description", "")
            if self.supports_product_template_field("description"):
                product_data["description"] = description
            if self.supports_product_template_field("description_sale"):
                product_data["description_sale"] = description
            if self.supports_product_template_field("available_in_pos"):
                product_data["available_in_pos"] = self._to_bool(product.get("available_in_pos"), default=True)
            if self.supports_product_template_field("active"):
                product_data["active"] = self._to_bool(product.get("active"), default=True)

            if product_central_id and self.supports_product_central_id_field():
                product_data["product_central_id"] = product_central_id

            product_id = models.execute_kw(
                ODOO_DB,
                uid,
                ODOO_PASSWORD,
                "product.template",
                "create",
                [product_data],
                {"context": {"allow_product_central_id_write": True}},
            )

            logger.info(
                f"✓ Created product in Odoo: {product['name']} "
                f"(Odoo ID: {product_id}, "
                f"Product Central ID: {product_central_id}, "
                f"code: {product_data['default_code']})"
            )

            self.set_on_hand_quantity(
                product_id,
                self._safe_int(product.get("quantity", 0), 0),
            )
            return product_id

        except Exception as e:
            logger.error(f"✗ Failed to create product in Odoo: {e}")
            return None

    def update_product(
        self, odoo_product_id: int, product: Dict[str, str]
    ) -> bool:
        """Update an existing product in Odoo."""
        try:
            uid = self._authenticate_odoo()
            models = xmlrpc.client.ServerProxy(f"{ODOO_URL}/xmlrpc/2/object")

            # Prepare update data
            update_data = {
                "name": product.get("name", ""),
                "list_price": self._safe_float(product.get("price", 0), 0.0),
            }

            description = product.get("description", "")
            if self.supports_product_template_field("description"):
                update_data["description"] = description
            if self.supports_product_template_field("description_sale"):
                update_data["description_sale"] = description
            if self.supports_product_template_field("available_in_pos"):
                update_data["available_in_pos"] = self._to_bool(product.get("available_in_pos"), default=True)
            if self.supports_product_template_field("active"):
                update_data["active"] = self._to_bool(product.get("active"), default=True)

            product_central_id = product.get("product_central_id", "")
            if product_central_id and self.supports_product_central_id_field():
                update_data["product_central_id"] = product_central_id
                update_data["default_code"] = product_central_id

            models.execute_kw(
                ODOO_DB,
                uid,
                ODOO_PASSWORD,
                "product.template",
                "write",
                [[odoo_product_id], update_data],
                {"context": {"allow_product_central_id_write": True}},
            )

            logger.info(
                f"✓ Updated product in Odoo: {product['name']} "
                f"(Odoo ID: {odoo_product_id}, Product Central ID: {product.get('product_central_id')})"
            )

            self.set_on_hand_quantity(
                odoo_product_id,
                self._safe_int(product.get("quantity", 0), 0),
            )
            return True

        except Exception as e:
            logger.error(f"✗ Failed to update product in Odoo: {e}")
            return False

    def archive_product(self, odoo_product_id: int, product_name: str) -> bool:
        """Archive (soft delete) a product in Odoo by setting active=False."""
        try:
            uid = self._authenticate_odoo()
            models = xmlrpc.client.ServerProxy(f"{ODOO_URL}/xmlrpc/2/object")

            models.execute_kw(
                ODOO_DB,
                uid,
                ODOO_PASSWORD,
                "product.template",
                "write",
                [[odoo_product_id], {"active": False}],
                {"context": {"allow_product_central_id_write": True}},
            )

            logger.info(
                f"✓ Archived product in Odoo: {product_name} "
                f"(Odoo ID: {odoo_product_id})"
            )
            return True

        except Exception as e:
            logger.error(f"✗ Failed to archive product in Odoo: {e}")
            return False

    def handle_message(self, ch, method, properties, body: bytes):
        """Handle a received message from RabbitMQ."""
        try:
            logger.info(
                f"Received message from RabbitMQ "
                f"(routing_key: {method.routing_key})"
            )

            # Parse the XML message
            product = self.parse_product_event_xml(body)

            action = product.get("action", "").lower()
            product_central_id = product.get("product_central_id", "")
            product_name = product.get("name", "")

            if action == "created":
                # Idempotent create: if already exists, apply update instead of creating duplicate.
                odoo_product_id = self.find_product_in_odoo(product_central_id, product_name)
                if odoo_product_id:
                    self.update_product(odoo_product_id, product)
                else:
                    self.create_product(product)

            elif action == "updated":
                # Find and update existing product
                odoo_product_id = self.find_product_in_odoo(product_central_id, product_name)
                if odoo_product_id:
                    self.update_product(odoo_product_id, product)
                else:
                    logger.warning(
                        f"Cannot update: product not found in Odoo "
                        f"(Product Central ID: {product_central_id}, Name: {product_name})"
                    )

            elif action == "deleted":
                # Archive (soft delete) the product
                odoo_product_id = self.find_product_in_odoo(product_central_id, product_name)
                if odoo_product_id:
                    self.archive_product(odoo_product_id, product_name)
                else:
                    logger.warning(
                        f"Cannot delete: product not found in Odoo "
                        f"(Product Central ID: {product_central_id}, Name: {product_name})"
                    )

            else:
                logger.warning(f"Unknown action: {action}")

            # Acknowledge the message
            ch.basic_ack(delivery_tag=method.delivery_tag)

        except Exception as e:
            logger.error(f"✗ Error handling message: {e}")
            # Requeue the message on error
            ch.basic_nack(delivery_tag=method.delivery_tag, requeue=True)

    def start_consuming(self):
        """Start consuming messages from the RabbitMQ queue."""
        try:
            # Declare exchange (ensure it exists and is durable)
            self.rabbitmq_channel.exchange_declare(
                exchange="product.events",
                exchange_type="topic",
                durable=True,
                passive=False,
            )

            # Declare queue (ensure it exists and is durable)
            self.rabbitmq_channel.queue_declare(
                queue=RABBITMQ_QUEUE,
                durable=True,
                passive=False,
            )

            # Bind queue to exchange with all routing keys
            routing_keys = [
                "wordpress.product.created",
                "wordpress.product.updated",
                "wordpress.product.deleted",
            ]
            for routing_key in routing_keys:
                self.rabbitmq_channel.queue_bind(
                    exchange="product.events",
                    queue=RABBITMQ_QUEUE,
                    routing_key=routing_key,
                )

            # Set up consumer
            self.rabbitmq_channel.basic_consume(
                queue=RABBITMQ_QUEUE,
                on_message_callback=self.handle_message,
                auto_ack=False,
            )

            logger.info(
                f"✓ Started consuming messages from queue: {RABBITMQ_QUEUE}"
            )
            logger.info("Waiting for product events...")

            self.rabbitmq_channel.start_consuming()

        except Exception as e:
            logger.error(f"✗ Error starting consumer: {e}")
            raise

    def _to_bool(self, value: str, default: bool = False) -> bool:
        """Parse bool-like strings used in canonical XML."""
        if value is None:
            return default
        text = str(value).strip().lower()
        if text == "":
            return default
        return text in {"1", "true", "yes", "on"}

    def run(self):
        """Main entry point for the service."""
        try:
            logger.info("Starting Odoo Receiver Service...")
            self.connect_rabbitmq()
            self.connect_odoo()
            self.start_consuming()
        except KeyboardInterrupt:
            logger.info("Shutting down gracefully...")
            if self.rabbitmq_connection:
                self.rabbitmq_connection.close()
        except Exception as e:
            logger.error(f"✗ Service error: {e}")
            raise


if __name__ == "__main__":
    receiver = OdooReceiver()
    receiver.run()
