{
    "name": "Product Event Hooks",
    "version": "17.0.1.0.0",
    "summary": "Send Odoo product events to odoo_sender",
    "license": "LGPL-3",
    "depends": ["product"],
    "data": [
        "data/ir_sequence_data.xml",
        "views/product_template_views.xml",
    ],
    "installable": True,
    "application": False,
}
