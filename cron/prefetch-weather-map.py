#!/usr/bin/env python3
"""Prefetch only the ECMWF .om data chunks intersecting GaugeIQ's map region.

Reads through GaugeIQ's byte-range proxy so every upstream range is stored
in its existing server-side cache.
"""
from __future__ import annotations

import argparse
import json
import sys
from urllib.parse import quote, urlparse

import fsspec
from omfiles import OmFileReader
from omfiles.grids import OmGrid

LON_MIN, LON_MAX = -25.0, 60.0
LAT_MIN, LAT_MAX = -45.0, 5.0
VARIABLES = ("cloud_cover", "precipitation")
BLOCK_SIZE = 65536


def regional_indices(grid: OmGrid, shape: tuple[int, ...]) -> tuple[int, int, int, int]:
    """Find a conservative grid-index rectangle from sampled geographic edges."""
    if len(shape) != 2:
        raise RuntimeError(f"Expected a 2-D weather field, got shape {shape!r}")

    points: list[tuple[int, int]] = []
    # Sample every half degree along all four edges. The two-cell margin keeps
    # interpolation/tile requests at the boundary inside the prefetched region.
    samples = 0
    lon = LON_MIN
    while lon <= LON_MAX + 1e-9:
        for lat in (LAT_MIN, LAT_MAX):
            point = grid.find_point_xy(float(lat), float(lon))
            samples += 1
            if point is not None:
                points.append((int(round(point.x)), int(round(point.y))))
        lon += 0.5

    lat = LAT_MIN
    while lat <= LAT_MAX + 1e-9:
        for lon in (LON_MIN, LON_MAX):
            point = grid.find_point_xy(float(lat), float(lon))
            samples += 1
            if point is not None:
                points.append((int(round(point.x)), int(round(point.y))))
        lat += 0.5

    if len(points) < samples // 2:
        raise RuntimeError("The agreed map bounds could not be mapped reliably to the ECMWF grid.")

    width, height = shape[1], shape[0]
    x0 = max(0, min(x for x, _ in points) - 2)
    x1 = min(width, max(x for x, _ in points) + 3)
    y0 = max(0, min(y for _, y in points) - 2)
    y1 = min(height, max(y for _, y in points) + 3)
    if x1 <= x0 or y1 <= y0:
        raise RuntimeError("The calculated regional ECMWF grid window is empty.")
    return y0, y1, x0, x1


def proxy_url(proxy_base: str, upstream_url: str) -> str:
    parsed = urlparse(upstream_url)
    if (
        parsed.scheme != "https"
        or parsed.netloc != "openmeteo.s3.amazonaws.com"
        or not parsed.path.startswith("/data_spatial/ecmwf_ifs/")
        or not parsed.path.endswith(".om")
    ):
        raise RuntimeError(f"Rejected unexpected ECMWF frame URL: {upstream_url}")
    return (
        proxy_base.rstrip("/")
        + "/api/weather-map-proxy.php?url="
        + quote(upstream_url, safe="")
    )


def prefetch_frame(frame: dict, proxy_base: str) -> None:
    url = proxy_url(proxy_base, str(frame["url"]))
    # OmFileReader uses random-access reads. The HTTP block size matches the
    # PHP proxy's 64 KiB maximum range; the proxy persists every fetched block.
    backend = fsspec.open(
        url,
        mode="rb",
        http={"block_size": BLOCK_SIZE},
    )

    with OmFileReader(backend) as root:
        crs = root.get_child_by_name("crs_wkt").read_scalar()
        first = root.get_child_by_name(VARIABLES[0])
        y0, y1, x0, x1 = regional_indices(OmGrid(crs, first.shape), first.shape)

        for variable in VARIABLES:
            field = root.get_child_by_name(variable)
            if tuple(field.shape) != tuple(first.shape):
                raise RuntimeError(
                    f"{variable} grid shape {field.shape!r} differs from {first.shape!r}"
                )
            # Materialising the slice causes the .om reader to request only
            # compressed chunks intersecting this regional window.
            regional_data = field[y0:y1, x0:x1]
            if getattr(regional_data, "size", 0) == 0:
                raise RuntimeError(f"{variable} returned an empty regional slice.")
            del regional_data

    print(
        f"Prefetched frame {frame.get('slot')} ({frame.get('type')}) "
        f"{frame.get('time')}: grid rows {y0}:{y1}, columns {x0}:{x1}",
        flush=True,
    )


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--proxy-base-url", required=True)
    args = parser.parse_args()

    if not args.proxy_base_url.startswith("https://"):
        raise RuntimeError("The GaugeIQ proxy base URL must use HTTPS.")

    frames = json.load(sys.stdin)
    if not isinstance(frames, list) or len(frames) != 12:
        raise RuntimeError("The prefetch input must contain exactly 12 scheduled frames.")

    for frame in frames:
        if not isinstance(frame, dict) or not frame.get("url"):
            raise RuntimeError("The scheduled frame list contains an invalid entry.")
        prefetch_frame(frame, args.proxy_base_url)

    print("Regional ECMWF prefetch completed for all 12 frames.", flush=True)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"Regional ECMWF prefetch failed: {exc}", file=sys.stderr, flush=True)
        raise SystemExit(1)
