#!/usr/bin/env python3
import json, pathlib, sys
api, impl, trial, directory, time_file = sys.argv[1:]
root = pathlib.Path(directory)
wall, user, system, rss = pathlib.Path(time_file).read_text().strip().split(",")
sources = [f for part in ("src", "lib") for f in (root / part).rglob("*.php") if f.is_file()]
meta = json.loads((root / "composer.json").read_text())
distribution = [f for f in root.rglob("*") if f.is_file() and not ({".git","vendor"} & set(f.parts))]
values = [
  api,impl,trial,wall,user,system,rss,len(sources),
  sum(f.stat().st_size for f in sources),
  sum(len(f.read_bytes().splitlines()) for f in sources),
  sum(f.stat().st_size for f in distribution),
  sum(key != "php" for key in meta.get("require",{})),
]
print(",".join(map(str,values)))
