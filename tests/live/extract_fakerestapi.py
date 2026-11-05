#!/usr/bin/env python3
"""Keep two safe GET operations from the REAL downloaded Swagger document.

The original OpenAPI is always retained unchanged. We only adapt the vendor's
versioned JSON media-type name ("application/json; v=1.0") to plain JSON and
remove unrelated endpoints. Never invent parameter types, schemas or responses.
"""
import copy
import json
import sys
from pathlib import Path

original = json.loads(Path(sys.argv[1]).read_text())
activity_schema = original["components"]["schemas"]["Activity"]
paths = {}
for path in ("/api/v1/Activities", "/api/v1/Activities/{id}"):
    op = copy.deepcopy(original["paths"][path]["get"])
    for response in op["responses"].values():
        content = response.get("content", {})
        if content:
            json_types = [media for media in content if media.lower().split(";")[0].strip() == "application/json"]
            if len(json_types) != 1:
                raise ValueError(f"Expected one JSON variant in {path}: {list(content)}")
            response["content"] = {"application/json": content[json_types[0]]}
    paths[path] = {"get": op}
subset = {
    "openapi": original["openapi"],
    "info": original["info"],
    "servers": [{"url": "https://fakerestapi.azurewebsites.net"}],
    "paths": paths,
    "components": {"schemas": {"Activity": activity_schema}},
}
Path(sys.argv[2]).write_text(json.dumps(subset, ensure_ascii=False, indent=2)+"\n")
print(f"Derived two read-only GET operations from original {original['openapi']} document.")
