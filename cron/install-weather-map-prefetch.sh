#!/bin/sh
set -eu

PROJECT_ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
VENV="$PROJECT_ROOT/storage/weather-map-python"
GET_PIP="$PROJECT_ROOT/storage/get-pip-weather-map.py"

mkdir -p "$PROJECT_ROOT/storage"

# omfiles 1.2.x requires Python 3.10+. Do not silently fall back to the
# host's often-obsolete "python3" (which may be Python 3.6 on shared hosting).
if [ -n "${PYTHON_BOOTSTRAP:-}" ]; then
    BOOTSTRAP_PYTHON="$PYTHON_BOOTSTRAP"
else
    BOOTSTRAP_PYTHON=""
    for candidate in python3.13 python3.12 python3.11 python3.10; do
        if command -v "$candidate" >/dev/null 2>&1 &&
           "$candidate" -c 'import sys; raise SystemExit(0 if sys.version_info >= (3, 10) else 1)' >/dev/null 2>&1; then
            BOOTSTRAP_PYTHON="$candidate"
            break
        fi
    done
fi

if [ -z "$BOOTSTRAP_PYTHON" ] ||
   ! "$BOOTSTRAP_PYTHON" -c 'import sys; raise SystemExit(0 if sys.version_info >= (3, 10) else 1)' >/dev/null 2>&1; then
    echo "GaugeIQ regional weather-map prefetch requires Python 3.10 or newer. The available default Python is too old; configure PYTHON_BOOTSTRAP to the hosting account's Python 3.10+ executable." >&2
    exit 1
fi

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
