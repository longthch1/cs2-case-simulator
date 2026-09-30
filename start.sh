#!/usr/bin/env bash
# ==============================================================================
# CS2 Case Opening Simulator - Linux/macOS Startup Script
# ==============================================================================

set -e

echo "=== CS2 Case Simulator Platform ==="

# Check Python 3
if ! command -v python3 &> /dev/null; then
    echo "Python 3 is required but not installed."
    exit 1
fi

# Ensure requirements installed
if [ -f "requirements.txt" ]; then
    pip install -q -r requirements.txt || true
fi

echo "Starting server on port 8000..."
exec python3 -m uvicorn app.main:app --host 0.0.0.0 --port 8000 --workers 2
