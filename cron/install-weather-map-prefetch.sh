#!/bin/sh
set -eu

PROJECT_ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
BOOTSTRAP_PYTHON="${PYTHON_BOOTSTRAP:-python3}"
VENV="$PROJECT_ROOT/storage/weather-map-python"

mkdir -p "$PROJECT_ROOT/storage"
"$BOOTSTRAP_PYTHON" -m venv "$VENV"
"$VENV/bin/python" -m pip install --upgrade pip
"$VENV/bin/python" -m pip install -r "$PROJECT_ROOT/cron/requirements-weather-map.txt"

"$VENV/bin/python" -c "import fsspec, omfiles; from omfiles.grids import OmGrid; print('GaugeIQ regional weather-map dependencies installed.')"
