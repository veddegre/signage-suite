#!/usr/bin/env bash
# Download curated photo backgrounds for slide_backgrounds/photos/ (1920×1080 crop).
# Mix of Unsplash + Pexels (both free for commercial use — see photos/CREDITS.md).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/slide_backgrounds/photos"
mkdir -p "$DEST"

fetch() {
  local name="$1" url="$2"
  echo "→ $name"
  curl -fsSL "$url" -o "$DEST/$name"
}

UNSPLASH_Q='?auto=format&fit=crop&w=1920&h=1080&q=82'
PEXELS_Q='?auto=compress&cs=tinysrgb&w=1920&h=1080&fit=crop'

# Unsplash
fetch lake_dusk.jpg       "https://images.unsplash.com/photo-1506905925346-21bda4d32df4${UNSPLASH_Q}"
fetch misty_forest.jpg    "https://images.unsplash.com/photo-1441974231531-c6227db76b6e${UNSPLASH_Q}"
fetch ocean_sunset.jpg    "https://images.unsplash.com/photo-1507525428034-b723cf961d3e${UNSPLASH_Q}"
fetch city_night.jpg      "https://images.unsplash.com/photo-1477959858617-67f85cf4f1df${UNSPLASH_Q}"
fetch winter_trees.jpg    "https://images.unsplash.com/photo-1519682337058-a94d519337bc${UNSPLASH_Q}"

# Pexels
fetch cozy_home.jpg       "https://images.pexels.com/photos/1571460/pexels-photo-1571460.jpeg${PEXELS_Q}"
fetch wildflowers.jpg     "https://images.pexels.com/photos/1563356/pexels-photo-1563356.jpeg${PEXELS_Q}"
fetch celebration.jpg     "https://images.pexels.com/photos/1105666/pexels-photo-1105666.jpeg${PEXELS_Q}"
fetch romantic_dinner.jpg "https://images.pexels.com/photos/941864/pexels-photo-941864.jpeg${PEXELS_Q}"
fetch nursery.jpg         "https://images.pexels.com/photos/3608299/pexels-photo-3608299.jpeg${PEXELS_Q}"
fetch stadium.jpg         "https://images.pexels.com/photos/1884574/pexels-photo-1884574.jpeg${PEXELS_Q}"
fetch mountain_sun.jpg    "https://images.pexels.com/photos/417173/pexels-photo-417173.jpeg${PEXELS_Q}"

echo "Done — $(ls "$DEST"/*.jpg 2>/dev/null | wc -l | tr -d ' ') photos in $DEST"
