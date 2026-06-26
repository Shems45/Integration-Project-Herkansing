from odoo import fields, models


class ProductTemplate(models.Model):
    _inherit = "product.template"

    central_id = fields.Char(index=True, copy=False)
