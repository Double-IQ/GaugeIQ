#!/bin/sh
set -eu

PROJECT_ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
BOOTSTRAP_PYTHON="${PYTHON_BOOTSTRAP:-python3}"
VENV="$PROJECT_ROOT/storage/weather-map-python"
GET_PIP="$PROJECT_ROOT/storage/get-pip-weather-map.py"

mkdir -p "$PROJECT_ROOT/storage"

# Some shared-host Python builds omit or break ensurepip. Create the venv
# without invoking ensurepip, then bootstrap pip directly into that venv.
"$BOOTSTRAP_PYTHON" -m venv --without-pip "$VENV"

cleanup() {
    rm -f "$GET_PIP"
}
trap cleanup EXIT HUP INT TERM

if command -v curl >/dev/null 2>&1; then
    curl --fail --location --silent --show-error --retry 2 \
        https://bootstrap.pypa.io/get-pip.py --output "$GET_PIP"
elif command -v wget >/dev/null 2>&1; then
    wget -q https://bootstrap.pypa.io/get-pip.py -O "$GET_PIP"
else
    echo "Cannot bootstrap pip: neither curl nor wget is available." >&2
    exit 1
fi

"$VENV/bin/python" "$GET_PIP"
"$VENV/bin/python" -m pip install --upgrade pip
"$VENV/bin/python" -m pip install -r "$PROJECT_ROOT/cron/requirements-weather-map.txt"

"$VENV/bin/python" -c "import fsspec, omfiles; from omfiles.grids import OmGrid; print('GaugeIQ regional weather-map dependencies installed.')"
