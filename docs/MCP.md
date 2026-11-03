# Local MCP interface

ProofPatcher exposes a local, read-only MCP server over the existing Hari skills. Run it from the application directory:

```bash
php artisan mcp:start proofpatch
```

The server uses standard input and output. It does not listen on an HTTP port and has the same local trust boundary as the process running it.

## Tools

| Tool | Purpose |
| --- | --- |
| Budget status | Read local model-request counters |
| Review result | Read saved automatic-review outcomes |
| Evidence verification | Check the integrity of a saved receipt |

The exact tool names and input schemas are provided by `tools/list`; their registrations are in `app/Mcp/Servers/ProofPatchServer.php`. The server does not create patches, execute candidate code, publish comments or mutate GitHub. Receipt integrity is not proof that the underlying change is correct.
