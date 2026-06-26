# Odoo Receiver Service
# Consumes product events from RabbitMQ and updates Odoo via XML-RPC API

FROM python:3.11-slim

WORKDIR /app

# Install dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy application code
COPY app.py .

# Run the receiver service
CMD ["python", "app.py"]
