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
        """Parse XML product event message from WordPress."""
        try:
            root = ET.fromstring(xml_data)
            product = {
                "action": root.findtext("action", "").strip(),
                "id": root.findtext("id", "").strip(),
                "central_id": root.findtext("central_id", "").strip(),
                "name": root.findtext("name", "").strip(),
                "price": root.findtext("price", "").strip(),
                "quantity": root.findtext("quantity", "").strip(),
                "description": root.findtext("description", "").strip(),
            }
            logger.info(
                f"✓ Parsed XML message - Action: {product['action']}, "
                f"Name: {product['name']}, ID: {product['id']}, "
                f"Central ID: {product['central_id']}"
            )
            return product
        except ET.ParseError as e:
            logger.error(f"✗ Failed to parse XML message: {e}")
            raise

    def find_product_in_odoo(
        self, central_id: str, wordpress_id: str, product_name: str
    ) -> Optional[int]:
        """
        Find a product in Odoo by central ID (stored in default_code).

        Searches by central ID first, then WordPress ID, and finally by name.
        Returns the product.template ID or None if not found.
        """
        try:
            uid = self._authenticate_odoo()
            models = xmlrpc.client.ServerProxy(f"{ODOO_URL}/xmlrpc/2/object")

            # First try to find by central ID (stored in default_code)
            if central_id:
                product_ids = models.execute_kw(
                    ODOO_DB,
                    uid,
                    ODOO_PASSWORD,
                    "product.template",
                    "search",
                    [[("default_code", "=", f"CID-{central_id}")]],
                )

                if product_ids:
                    logger.info(
                        f"✓ Found product by central ID: {central_id} "
                        f"(Odoo ID: {product_ids[0]})"
                    )
                    return product_ids[0]

            # Backward-compatible fallback for older records
            if wordpress_id:
                product_ids = models.execute_kw(
                    ODOO_DB,
                    uid,
                    ODOO_PASSWORD,
                    "product.template",
                    "search",
                    [[("default_code", "=", f"WP-{wordpress_id}")]],
                )

                if product_ids:
                    logger.info(
                        f"✓ Found product by WordPress ID: {wordpress_id} "
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

    def create_product(self, product: Dict[str, str]) -> Optional[int]:
        """Create a new product in Odoo with central ID stored in default_code."""
        try:
            uid = self._authenticate_odoo()
            models = xmlrpc.client.ServerProxy(f"{ODOO_URL}/xmlrpc/2/object")

            wordpress_id = product.get("id", "")
            central_id = product.get("central_id", "")

            # Prepare product data for Odoo
            product_data = {
                "name": product.get("name", "Unknown Product"),
                "type": "product",  # Standard product type
                "list_price": float(product.get("price", 0)) or 0.0,
                "description": product.get("description", ""),
                "available_in_pos": True,  # Make available in POS
                # Store central ID in default_code field (e.g., "CID-<uuid>")
                "default_code": (
                    f"CID-{central_id}"
                    if central_id
                    else (f"WP-{wordpress_id}" if wordpress_id else "")
                ),
            }

            product_id = models.execute_kw(
                ODOO_DB,
                uid,
                ODOO_PASSWORD,
                "product.template",
                "create",
                [product_data],
            )

            logger.info(
                f"✓ Created product in Odoo: {product['name']} "
                f"(Odoo ID: {product_id}, WordPress ID: {wordpress_id}, "
                f"Central ID: {central_id}, "
                f"code: {product_data['default_code']})"
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
                "list_price": float(product.get("price", 0)) or 0.0,
                "description": product.get("description", ""),
            }

            central_id = product.get("central_id", "")
            if central_id:
                update_data["default_code"] = f"CID-{central_id}"

            models.execute_kw(
                ODOO_DB,
                uid,
                ODOO_PASSWORD,
                "product.template",
                "write",
                [[odoo_product_id], update_data],
            )

            logger.info(
                f"✓ Updated product in Odoo: {product['name']} "
                f"(Odoo ID: {odoo_product_id}, WordPress ID: {product.get('id')}, "
                f"Central ID: {product.get('central_id')})"
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
            wordpress_id = product.get("id", "")
            central_id = product.get("central_id", "")
            product_name = product.get("name", "")

            if action == "created":
                # Create new product in Odoo
                self.create_product(product)

            elif action == "updated":
                # Find and update existing product
                odoo_product_id = self.find_product_in_odoo(
                    central_id, wordpress_id, product_name
                )
                if odoo_product_id:
                    self.update_product(odoo_product_id, product)
                else:
                    logger.warning(
                        f"Cannot update: product not found in Odoo "
                        f"(Central ID: {central_id}, WordPress ID: {wordpress_id}, "
                        f"Name: {product_name})"
                    )

            elif action == "deleted":
                # Archive (soft delete) the product
                odoo_product_id = self.find_product_in_odoo(
                    central_id, wordpress_id, product_name
                )
                if odoo_product_id:
                    self.archive_product(odoo_product_id, product_name)
                else:
                    logger.warning(
                        f"Cannot delete: product not found in Odoo "
                        f"(Central ID: {central_id}, WordPress ID: {wordpress_id}, "
                        f"Name: {product_name})"
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
