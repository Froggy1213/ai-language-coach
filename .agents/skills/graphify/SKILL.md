---
name: graphify
description: >-
  Code knowledge graph of this repository. Reach for it before exploring an unfamiliar
  area, before a refactor, or when a question spans several files: `graphify explain`,
  `affected`, `path`, `query` answer "who calls what", "what breaks if I change this",
  "how do these two connect" from `graphify-out/graph.json` — no grepping, no reading
  whole files. Local tree-sitter AST, no API key, rebuild in ~5 s.
whenToUse: You are about to grep for a symbol, open more than two files to understand one flow, plan a change whose blast radius is unclear, or orient yourself in a subsystem you have not touched this session.
---

# graphify — query the code graph instead of grepping

`graphify-out/graph.json` is this repository as a directed graph: files, classes, functions,
methods, imports, calls, references, and constants. Every node carries `file:line`. Built by
local tree-sitter AST parsing (PHP, TS/Vue, Python) — no LLM, no tokens, nothing leaves the
machine. Roughly 3.6k nodes / 7.7k edges.

The CLI is installed globally as a `uv tool` (`graphify`, version on PATH). Do **not** call
`python3 -c "import graphify"` — the package lives in the tool's private venv, so imports fail.
Use the CLI only.

## Keep it fresh

```bash
graphify update .                # re-extract changed files, ~5 s, zero tokens
graphify extract . --code-only   # full rebuild, ~9 s (after a branch switch)
graphify update . --force        # after deletions/renames, else stale nodes linger
```

The graph is gitignored and can be older than the working tree. Rebuild when
`graphify-out/graph.json` predates the last commit or the files you are about to ask about —
stale `file:line` is the usual symptom.

## Queries that pay for themselves

| Question | Command |
|---|---|
| What is this symbol, and what touches it? | `graphify explain "VoiceSessionLifecycle"` |
| What breaks if I change it? | `graphify affected "VoiceSessionLifecycle.php" --depth 2` |
| How do A and B connect? | `graphify path "A" "B"` (`--undirected` when direction hides the path) |
| What lives in this area? (broad) | `graphify query "daily voice limit" --budget 800` |
| Architectural hubs | `graphify god-nodes --top 10` |

`explain` returns the node, its `file:line`, community, degree, and each neighbour with its
relation and `[EXTRACTED]`/`[INFERRED]` provenance — 30–50 lines, one call instead of four
greps and two reads. `affected` is the reverse traversal: callers and tests that break.
`query` is the weakest of the four — it dumps nodes around keyword matches and truncates
hard, so use it to orient and follow up with `explain` on a single node.

## Limits you must respect

- **A map, not the territory.** The graph yields paths and edges, never file contents. Read
  the file before editing it — the repo rule does not change.
- **Labels must resolve uniquely.** `No unique node match` or `No directed path found` means
  the label is ambiguous (class vs file vs method). Pass something more specific —
  `VoiceSessionLifecycle.php`, or the method as `.finished()` — or add `--undirected`.
- **`graphify explain --help` is not help**: unknown arguments are taken as node names. Use
  `graphify --help`.
- **16 `.tf` files are absent** from the graph (`tree_sitter_hcl` is not installed) — answer
  infra questions by reading the Terraform directly. The same goes for the 13 `backend/graphql/**/*.graphql`
  files: the SDL is not parsed, so schema questions are answered from the schema itself.
- Upstream skill snippets that import the Python module do not work here; the CLI keeps
  `graphify-out/` as the only state.

## Outputs

`graphify-out/graph.html` (interactive; open in a browser), `GRAPH_REPORT.md` (hubs,
communities, suggested questions), `graph.json` (the source for every command above).
`graphify export callflow-html` renders an architecture / call-flow page for one subsystem.
