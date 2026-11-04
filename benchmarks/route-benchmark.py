#!/usr/bin/env python3
"""Measure comparable generated PHP source (never vendored code).

Four generators use the same pinned Casafari 3.1 OpenAPI document.
This is not a claim of feature parity or PHP client runtime benchmarking.
"""
import json
import re
import statistics
import sys
import zipfile
from pathlib import Path

root = Path(sys.argv[1])
names = {
    "before": "Our generator before route refactor",
    "ours": "Our generator with route namespaces",
    "php": "OpenAPI Generator 7.27 php",
    "php-nextgen": "OpenAPI Generator 7.27 php-nextgen",
}
results = {}

def api_class(kind, path, data):
    if kind == "before":
        return (
            path.startswith(("src/Api/", "src/StaticApi/"))
            or b"extends CasafariAPISDK" in data
        )
    if kind == "ours":
        return (
            path.startswith("src/Resources/")
            or b"extends \\Casafari\\GeneratedSdk\\CasafariAPISDK" in data
        )
    return path.startswith(("src/Api/", "lib/Api/"))

for key, label in names.items():
    base = root / key
    sources = sorted(p for folder in ("src", "lib")
                     for p in (base / folder).rglob("*.php"))
    status_path = root / (key + ".status")
    status = status_path.read_text().strip() if status_path.exists() else "0"
    if not sources:
        log = root / (key + ".log")
        results[key] = {"name":label, "success":False, "status":status,
                        "error":log.read_text(errors="replace")[-1800:] if log.exists() else ""}
        continue
    code = {str(p.relative_to(base)):p.read_bytes() for p in sources}
    api = {p:b for p,b in code.items() if api_class(key,p,b)}
    models = {p:b for p,b in code.items() if "/DTOs/" in p or re.search(r"(^|/)Model/",p)}
    params = {p:b for p,b in code.items() if re.search(
        r"^src/(GetParams|PostParams|PutParams|PatchParams|DeleteParams|PathParams)/",p)}
    enums = {p:b for p,b in code.items() if "/Enums/" in p}
    arrays = {p:b for p,b in code.items() if "/Collections/" in p}
    lines = lambda b:len(b.splitlines())
    largest = sorted(((len(b),lines(b),p) for p,b in api.items()), reverse=True)
    zip_path = root / (key + "-php-source.zip")
    with zipfile.ZipFile(zip_path,"w",zipfile.ZIP_DEFLATED,compresslevel=6) as archive:
        for p,b in sorted(code.items()):
            archive.writestr(p,b)
    time_file = root / (key + ".time")
    secs, rss = None, None
    if time_file.exists():
        try:
            raw = time_file.read_text().strip().split(",")
            secs, rss = float(raw[0]),int(raw[1])
        except (ValueError, IndexError):
            pass
    results[key] = {
        "name":label,"success":status=="0","status":status,
        "files":len(code),"lines":sum(map(lines,code.values())),
        "bytes":sum(map(len,code.values())),
        "zip_bytes":zip_path.stat().st_size,
        "api_files":len(api),"api_lines":sum(map(lines,api.values())),
        "api_bytes":sum(map(len,api.values())),
        "api_median_lines":statistics.median(map(lines,api.values())) if api else 0,
        "models":len(models),"params":len(params),
        "enums":len(enums),"collections":len(arrays),
        "largest_api":{"path":largest[0][2],"lines":largest[0][1],
                       "bytes":largest[0][0]} if largest else None,
        "largest_five_api":[{"path":p,"lines":n,"bytes":size} for size,n,p in largest[:5]],
        "generation_seconds_single_run":secs,
        "generation_rss_kib_single_run":rss,
    }

(root/"report.json").write_text(json.dumps(results,indent=2,ensure_ascii=False)+"\n")
def number(x):
    if x is None:return "N/D"
    return f"{x:,.0f}".replace(",", ".")
cols = list(names)
rows = [
    "# Casafari SDK — before/after route namespaces vs OpenAPI Generator 7.27",
    "",
    "Pinned original SHA256: " + (root/"spec.sha256").read_text().split()[0],
    "",
    "All generators consume the same 46-operation OpenAPI 3.1 specification.",
    "Measure only generated PHP in src/ or lib/ (exclude dependencies, tests, docs).",
    "This is a source-size comparison, not a feature parity or SDK runtime-memory test.",
    "",
    "| Metric | Our before | Our after | OpenAPI php | OpenAPI php-nextgen |",
    "|---|---:|---:|---:|---:|",
]
for k,name in [
    ("files","PHP files"),("lines","PHP source lines"),("bytes","PHP bytes"),
    ("zip_bytes","Compressed PHP source bytes"),("api_files","API class files"),
    ("api_lines","API class lines"),("api_bytes","API class bytes"),
    ("api_median_lines","Median API class lines"),
    ("models","DTO/model files"),("params","Verb/path Params files"),
    ("enums","Enum files"),("collections","Typed collection files"),
]:
    rows.append("| "+name+" | "+" | ".join(number(results[c].get(k)) for c in cols)+" |")
rows += ["", "## Generator process resources: one run each (not SDK runtime RAM)",
         "", "| Generator | Seconds | Peak RSS (KiB) |", "|---|---:|---:|"]
for k in cols:
    obj=results[k]
    rows.append("| "+obj["name"]+" | "+
                str(obj.get("generation_seconds_single_run"))+" | "+
                number(obj.get("generation_rss_kib_single_run"))+" |")
rows += ["", "## Largest generated API classes", ""]
for k in cols:
    obj=results[k]
    rows.append("### "+obj["name"])
    if not obj.get("success"):
        rows.append("Failed or incomplete (status "+obj.get("status","?")+")")
        rows.append(obj.get("error","")[-1700:])
    else:
        for f in obj["largest_five_api"]:
            rows.append("- "+f["path"]+": "+str(f["lines"])+
                        " lines / "+str(f["bytes"])+" bytes")
    rows.append("")
(root/"REPORT.md").write_text("\n".join(rows)+"\n")
print("\n".join(rows))
