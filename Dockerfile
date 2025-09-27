# Use an official Python runtime
FROM python:3.11-slim

# Set work directory
WORKDIR /app

# Install dependencies
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy project files
COPY . .

# Set environment variable for Render
ENV PYTHONUNBUFFERED=1

# Expose the port Render uses
EXPOSE 10000

# Command to run your app
CMD ["python", "main.py"]
