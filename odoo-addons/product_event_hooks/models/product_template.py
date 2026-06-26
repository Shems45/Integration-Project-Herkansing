import json
import logging
import re
import urllib.error
import urllib.request

from odoo import api, models
from odoo.exceptions import ValidationError

_logger = logging.getLogger(__name__)

ODOO_SENDER_URL = "http://odoo_sender:8000/odoo-product-event"
NUMERIC_ONLY_RE = re.compile(r"^\d+$")


class ProductTemplate(models.Model):
    _inherit = "product.template"

    def _generate_product_central_id(self):
        """Generate product_central_id from sequence; never from Odoo product.id."""
        return self.env["ir.sequence"].next_by_code("product.central.id") or False

    def _validate_product_central_id(self, code):
        if not code:
            raise ValidationError("product_central_id/default_code is required")

        if NUMERIC_ONLY_RE.fullmatch(code):
            raise ValidationError(
                "default_code cannot be only numeric because product_central_id must be different from the Odoo database id"
            )

        duplicate = self.search(
            [
                ("default_code", "=", code),
                ("id", "!=", self.id),
            ],
            limit=1,
        )
        if duplicate:
            raise ValidationError(
                "product_central_id/default_code already exists for another product"
            )

    def _ensure_product_central_id(self):
        """Ensure default_code exists and is a valid unique product_central_id."""
        for product in self:
            if not product.default_code:
                generated_code = product._generate_product_central_id()
                if not generated_code:
                    raise ValidationError("Could not generate product_central_id")

                # Allow internal addon write for generated product_central_id.
                product.with_context(
                    allow_product_central_id_write=True,
                    skip_product_event_hook=True,
                ).write({"default_code": generated_code})

            product._validate_product_central_id(product.default_code)

    def _build_product_payload(self, action):
        """Build JSON payload for odoo_sender without using Odoo id as sync id."""
        self.ensure_one()

        quantity = 0.0
        if "qty_available" in self._fields:
            quantity = float(self.qty_available)

        description = self.description or ""
        if not description and "description_sale" in self._fields:
            description = self.description_sale or ""

        available_in_pos = False
        if "available_in_pos" in self._fields:
            available_in_pos = bool(self.available_in_pos)

        return {
            "action": action,
            "product_central_id": self.default_code,
            "name": self.name or "",
            "price": float(self.list_price or 0.0),
            "quantity": quantity,
            "description": description,
            "available_in_pos": available_in_pos,
            "active": bool(self.active),
        }

    def _send_event_to_odoo_sender(self, action):
        """Send product event to odoo_sender: Odoo hook -> odoo_sender -> XML -> RabbitMQ -> wordpress.product.events."""
        for product in self:
            payload = product._build_product_payload(action)
            request_data = json.dumps(payload).encode("utf-8")
            req = urllib.request.Request(
                ODOO_SENDER_URL,
                data=request_data,
                headers={"Content-Type": "application/json"},
                method="POST",
            )

            _logger.info(
                "Sending product event to odoo_sender: action=%s product_id=%s product_central_id=%s",
                action,
                product.id,
                product.default_code,
            )

            try:
                with urllib.request.urlopen(req, timeout=5) as response:
                    status = response.getcode()
                    body = response.read().decode("utf-8", errors="ignore")
                    if status < 200 or status >= 300:
                        raise ValidationError(
                            f"odoo_sender returned HTTP {status}: {body}"
                        )
            except urllib.error.HTTPError as exc:
                raise ValidationError(
                    f"Failed to send product event to odoo_sender: HTTP {exc.code}"
                ) from exc
            except urllib.error.URLError as exc:
                raise ValidationError(
                    f"Failed to send product event to odoo_sender: {exc.reason}"
                ) from exc

    @api.model_create_multi
    def create(self, vals_list):
        if not self.env.context.get("allow_product_central_id_write"):
            for vals in vals_list:
                if vals.get("default_code"):
                    raise ValidationError(
                        "default_code/product_central_id is managed automatically by integration hooks"
                    )

        products = super().create(vals_list)
        products._ensure_product_central_id()
        products._send_event_to_odoo_sender("created")
        return products

    def write(self, vals):
        if self.env.context.get("skip_product_event_hook"):
            return super().write(vals)

        if "default_code" in vals and not self.env.context.get("allow_product_central_id_write"):
            raise ValidationError(
                "default_code/product_central_id is managed automatically by integration hooks"
            )

        was_active = {record.id: bool(record.active) for record in self}

        result = super().write(vals)
        self._ensure_product_central_id()

        action = "updated"
        if "active" in vals:
            # Treat archive as delete event for integration flow.
            if any(was_active.get(record.id, True) and not record.active for record in self):
                action = "deleted"

        self._send_event_to_odoo_sender(action)
        return result

    def unlink(self):
        if self.env.context.get("skip_product_event_hook"):
            return super().unlink()

        self._ensure_product_central_id()
        self._send_event_to_odoo_sender("deleted")
        return super().unlink()
