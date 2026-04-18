## GitHub Copilot Chat

- Extension: 0.39.1 (prod)
- VS Code: 1.111.0 (ce099c1ed25d9eb3076c11e4a280f3eb52b4fbeb)
- OS: win32 10.0.22631 x64
- GitHub Account: Signed Out

## Network

User Settings:
```json
  "http.systemCertificatesNode": true,
  "github.copilot.advanced.debug.useElectronFetcher": true,
  "github.copilot.advanced.debug.useNodeFetcher": false,
  "github.copilot.advanced.debug.useNodeFetchFetcher": true
```

Connecting to https://api.github.com:
- DNS ipv4 Lookup: 20.87.245.6 (513 ms)
- DNS ipv6 Lookup: Error (43 ms): getaddrinfo ENOTFOUND api.github.com
- Proxy URL: None (1 ms)
- Electron fetch (configured): HTTP 200 (880 ms)
- Node.js https: HTTP 200 (541 ms)
- Node.js fetch: HTTP 200 (798 ms)

Connecting to https://api.githubcopilot.com/_ping:
- DNS ipv4 Lookup: 140.82.113.22 (85 ms)
- DNS ipv6 Lookup: Error (16 ms): getaddrinfo ENOTFOUND api.githubcopilot.com
- Proxy URL: None (26 ms)
- Electron fetch (configured): HTTP 200 (342 ms)
- Node.js https: HTTP 200 (1063 ms)
- Node.js fetch: HTTP 200 (1067 ms)

Connecting to https://copilot-proxy.githubusercontent.com/_ping:
- DNS ipv4 Lookup: 20.199.39.224 (84 ms)
- DNS ipv6 Lookup: Error (78 ms): getaddrinfo ENOTFOUND copilot-proxy.githubusercontent.com
- Proxy URL: None (19 ms)
- Electron fetch (configured): HTTP 200 (799 ms)
- Node.js https: HTTP 200 (788 ms)
- Node.js fetch: HTTP 200 (967 ms)

Connecting to https://mobile.events.data.microsoft.com: HTTP 404 (580 ms)
Connecting to https://dc.services.visualstudio.com: HTTP 404 (2442 ms)
Connecting to https://copilot-telemetry.githubusercontent.com/_ping: HTTP 200 (1090 ms)
Connecting to https://copilot-telemetry.githubusercontent.com/_ping: HTTP 200 (1126 ms)
Connecting to https://default.exp-tas.com: HTTP 400 (1063 ms)

Number of system certificates: 107

## Documentation

In corporate networks: [Troubleshooting firewall settings for GitHub Copilot](https://docs.github.com/en/copilot/troubleshooting-github-copilot/troubleshooting-firewall-settings-for-github-copilot).