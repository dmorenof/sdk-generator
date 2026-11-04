#!/usr/bin/env python3
"""Compare PHP source (not vendor, tests or generated docs) for the same spec."""
import json
import re
import statistics
import sys
import zipfile
from pathlib import Path

root = Path(sys.argv[1])
names = {
    "ours": "sdk-generator 0.2.0",
    "php": "OpenAPI Generator 7.27 / php",
    "php-nextgen": "OpenAPI Generator 7.27 / php-nextgen",
}
results = {}
for key, label in names.items():
    base = root / key
    sources = sorted(p for folder in ("src", "lib") for p in (base / folder).rglob("*.php"))
    status_path = root / (key + ".status")
    status = status_path.read_text().strip() if status_path.exists() else "0"
    if not sources:
        log = root / (key + ".log")
        results[key] = {"label": label, "success": False, "status": status,
                        "error": log.read_text(errors="replace")[-2500:] if log.exists() else ""}
        continue
    code = {str(p.relative_to(base)): p.read_bytes() for p in sources}
    api = {k: v for k,v in code.items() if re.search(r"(^|/)(Api|API)/.+[.]php$", k)
           or (key == "ours" and re.match(r"^src/[A-Za-z][A-Za-z0-9]*Api(V[0-9]+)?[.]php$", k))}
    models = {k: v for k,v in code.items() if "/DTOs/" in k or re.search(r"(^|/)Model/",k)}
    enums = {k: v for k,v in code.items() if "/Enums/" in k}
    arrays = {k: v for k,v in code.items() if "/Collections/" in k}
    lines = lambda data: len(data.splitlines())
    biggest = sorted(((len(v),lines(v),k) for k,v in api.items()), reverse=True)
    compressed = root / (key + "-source.zip")
    with zipfile.ZipFile(compressed,"w",zipfile.ZIP_DEFLATED,compresslevel=6) as out:
        for k,v in sorted(code.items()):
            out.writestr(k,v)
    timing = root / (key + ".time")
    t, peak = None, None
    if timing.exists():
        try:
            t, peak = timing.read_text().strip().split(",")
            t, peak = float(t), int(peak)
        except (ValueError,TypeError):
            pass
    results[key] = {
        "label": label, "success": status == "0",
        "files":len(code),"bytes":sum(map(len,code.values())),
        "lines":sum(map(lines,code.values())),
        "zip_bytes":compressed.stat().st_size,
        "api_files":len(api),"api_bytes":sum(map(len,api.values())),
        "api_lines":sum(map(lines,api.values())),
        "api_median_lines":statistics.median([lines(v) for v in api.values()]) if api else None,
        "models":len(models),"enums":len(enums),"collections":len(arrays),
        "largest_api": {"path":biggest[0][2], "lines":biggest[0][1],
                        "bytes":biggest[0][0]} if biggest else None,
        "largest_five_api": [{"path":p,"lines":n,"bytes":size} for size,n,p in biggest[:5]],
        "generation_seconds_once":t,"generation_rss_kib_once":peak,
        "status":status,
    }
(root/"report.json").write_text(json.dumps(results,indent=2,ensure_ascii=False)+"\n")
columns = ["ours","php","php-nextgen"]
def fmt(n):
    return "N/D" if n is None else f"{n:,.0f}".replace(",", ".")
def kv(k):
    return [fmt(results[x].get(k)) for x in columns]
table = [
    "# Casafari 46 operations — generated PHP source benchmark",
    "",
    "SHA-256: " + (root / "spec.sha256").read_text().split()[0],
    "",
    "Source files under src/ and lib/ only. Excludes vendor/, tests, docs.",
    "These measurements are not a functional feature-parity certification.",
    "",
    "| Measure | Our generator | OpenAPI classic PHP | OpenAPI php-nextgen |",
    "|---|---:|---:|---:|",
]
for field, name in [
    ("files","PHP files"),("lines","PHP lines"),("bytes","PHP bytes"),
    ("zip_bytes","Source ZIP bytes"),("api_files","API classes"),
    ("api_lines","API lines"),("api_bytes","API bytes"),
    ("api_median_lines","Median API class lines"),
    ("models","DTO/model files"),("enums","enum files"),("collections","typed collection files"),
]:
    table.append("| "+name+" | "+" | ".join(kv(field))+" |")
table += ["","## Generator resources (one process each; NOT SDK runtime memory)","",
          "| Generator | Elapsed seconds | Peak RSS KiB |","|---|---:|---:|"]
for x in columns:
    r=results[x]
    table.append("| "+r["label"]+" | "+str(r.get("generation_seconds_once","N/D"))+
                 " | "+fmt(r.get("generation_rss_kib_once"))+" |")
table += ["", "## Largest API files",""]
for x in columns:
    r=results[x]
    table.append("### "+r["label"])
    if not r.get("success"):
        table += ["Failed or incomplete: "+r.get("status","?"),r.get("error","")[-1800:]]
    else:
        for item in r["largest_five_api"]:
            table.append("- "+item["path"]+" — "+str(item["lines"])+
                         " lines, "+str(item["bytes"])+" bytes")
    table.append("")
(root/"REPORT.md").write_text("\n".join(table)+"\n")
print("\n".join(table))
