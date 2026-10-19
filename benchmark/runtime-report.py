#!/usr/bin/env python3
import csv, json, pathlib, statistics, sys
if sys.argv[1] == "row":
    datum=json.loads(pathlib.Path(sys.argv[2]).read_text())
    wall,rss=pathlib.Path(sys.argv[3]).read_text().strip().split(",")
    print(",".join(map(str, [
       datum["api"], datum["implementation"], sys.argv[4],
       wall,rss,datum["startup_ms"],datum["ops_ms"],
       datum["us_per_request"],datum["php_peak_mib"],
    ])))
else:
    rows=list(csv.DictReader(open(sys.argv[2],newline="",encoding="utf8")))
    if len(rows)!=20:
        raise RuntimeError(f"Expected 20 measurements, got {len(rows)}")
    def median(a,m,f):
        z=[float(r[f]) for r in rows if r["api"]==a and r["implementation"]==m]
        if len(z)!=5: raise RuntimeError(f"Missing runs for {a}/{m}")
        return statistics.median(z)
    size={}
    for line in pathlib.Path(sys.argv[3]).read_text().splitlines():
        tag,api,impl,n=line.split()
        size[tag,api,impl]=int(n)
    lines=[
        "# PHP SDK runtime performance — Guzzle parity benchmark","",
        "Compared **on the same machine** and with the same OpenAPI 3.0 contract and fake Guzzle HTTP handler.",
        "Our client: PR #11 Guzzle/PSR-18 factory. Competitor: official OpenAPI Generator 7.26.0 PHP/Guzzle.",
        "Each measurement is a **fresh PHP process**, with 12 warmup GETs and **1000 measured GET + DTO hydration** operations.",
        "5 repetitions per API/implementation with alternating execution order. Median shown.",
        "No real network; synchronous single-request calls with small fixture bodies. These are **not** production performance guarantees.",
        "",
        "| API | SDK | GET+DTO microseconds (median) | PHP startup/setup ms | PHP peak MiB | Process max RSS MiB | Installed vendor KiB | Installed packages |",
        "|---|---|---:|---:|---:|---:|---:|---:|",
    ]
    for api in ("accounts","catalog"):
      for impl in ("ours","openapi"):
        med=lambda f:median(api,impl,f)
        lines.append(
          f"| {api} | {impl} | {med('us_per_request'):.2f} | "
          f"{med('startup_ms'):.2f} | {med('php_peak_mib'):.2f} | "
          f"{med('peak_rss_kib')/1024:.2f} | "
          f"{size.get(('VENDOR_KiB',api,impl),'?')} | "
          f"{size.get(('INSTALLED_PACKAGES',api,impl),'?')} |"
        )
    lines+=["","## What the numbers can and cannot mean","",
       "- Both packages are independently installed with Composer; the generated code uses a real Guzzle client with the **same fake PSR-7 handler**.",
       "- The operation is a typed GET, with path and query serialization and checked typed response fields. The full OpenAPI Generated client has broader built-in features and may perform more validation.",
       "- Not yet comparable: automatic authentication, error response decoding, POST body serialization, middleware chains, streaming, pagination, or complex schemas.",
       "- The results are medians on hosted GitHub Actions runners: shared hardware/cache effects remain, and five repeats are exploratory.",
       "- Startup/setup is measured after PHP script begins and includes autoload require and client setup; max RSS includes PHP process startup and dependencies.",
       "- Installed vendor size is the Composer tree size and count; it does not measure time to download/install, and may vary with dependency resolution.",
       "- No assumptions about intuitiveness follow from speed. See INTEGRATION.md for scenario-based steps and examples.",
       "",
       "## Reproducibility","",
       "Raw observations in observations.csv. Individual JSON measurements, process time measurements, installed dependency sizes, full generator output and logs are in the attached Actions artifact.",
    ]
    pathlib.Path(sys.argv[4]).write_text("\n".join(lines)+"\n",encoding="utf8")
