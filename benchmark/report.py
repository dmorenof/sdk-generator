#!/usr/bin/env python3
import csv, pathlib, statistics, sys
rows=list(csv.DictReader(open(sys.argv[1],newline="",encoding="utf8")))
if len(rows)!=12: raise RuntimeError(f"Expected 12 runs, got {len(rows)}")
def med(api, tool, column):
    data=[float(r[column]) for r in rows if r["api"]==api and r["implementation"]==tool]
    if len(data)!=3: raise RuntimeError(f"Missing trials for {api}/{tool}")
    return statistics.median(data)
lines=[
 "# PHP SDK generator benchmark - generation baseline","",
 "OpenAPI Generator 7.26.0 pinned, PHP target with modern=true and library=guzzle, no custom templates.",
 "Identical OpenAPI 3.0 JSON file for each API; Accounts and Catalog are two synthetic APIs.",
 "Three fresh-process runs per tool/API on the same GitHub Actions runner; tool order alternates.",
 "Time includes CLI startup and code emission; RSS is generator-process memory, not runtime.","",
 "| API | Generator | Wall s median | Peak RSS MiB | PHP files | PHP KiB | PHP LOC | Full generated KiB | Composer direct deps excluding PHP |",
 "|---|---|---:|---:|---:|---:|---:|---:|---:|",
]
for api in ("accounts","catalog"):
  for tool in ("ours","openapi"):
    m=lambda k:med(api,tool,k)
    lines.append(f"| {api} | {tool} | {m('wall_seconds'):.3f} | {m('peak_rss_kib')/1024:.1f} | {m('php_files'):.0f} | {m('php_source_bytes')/1024:.1f} | {m('php_source_lines'):.0f} | {m('package_total_bytes')/1024:.1f} | {m('composer_require_count'):.0f} |")
lines+=["",
 "## Important limitations","",
 "- These measurements are for the generators, NOT execution of the generated SDKs.",
 "- PHP size/LOC counts production sources only under src/ or lib/, excluding generated tests and docs. Full size excludes installed vendor dependencies.",
 "- Upstream Guzzle target implements the HTTP stack and supports far more features; this prototype requires a custom injected HTTP transport. Smaller emitted sources do not establish feature parity.",
 "- Composer direct deps are counts in composer.json, NOT installed total dependency sizes.",
 "- Three medians on a hosted/shared runner are initial observations, not precise performance guarantees.",
 "- Runtime benchmarks must install both produced SDKs, exercise equivalent operations and payloads, and measure loaded PHP memory and throughput.",
 "- Usability must measure steps/config/custom glue/diagnostics and document genuine capabilities, not an arbitrary score.",
 "",
 "## Evidence","",
 "This Actions artifact contains raw.csv, per-run timing/logs, and full generated source trees for both generators and APIs."
]
pathlib.Path(sys.argv[2]).write_text("\n".join(lines)+"\n",encoding="utf8")
