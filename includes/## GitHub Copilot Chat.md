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
- DNS ipv4 Lookup: 20.87.245.6 (353 ms)
- DNS ipv6 Lookup: Error (53 ms): getaddrinfo ENOTFOUND api.github.com
- Proxy URL: None (6 ms)
- Electron fetch (configured): HTTP 200 (551 ms)
- Node.js https: HTTP 200 (241 ms)
- Node.js fetch: HTTP 200 (191 ms)

Connecting to https://api.githubcopilot.com/_ping:
- DNS ipv4 Lookup: 140.82.113.22 (189 ms)
- DNS ipv6 Lookup: Error (49 ms): getaddrinfo ENOTFOUND api.githubcopilot.com
- Proxy URL: None (18 ms)
- Electron fetch (configured): HTTP 200 (301 ms)
- Node.js https: HTTP 200 (946 ms)
- Node.js fetch: HTTP 200 (904 ms)

Connecting to https://copilot-proxy.githubusercontent.com/_ping:
- DNS ipv4 Lookup: 4.225.11.192 (89 ms)
- DNS ipv6 Lookup: Error (57 ms): getaddrinfo ENOTFOUND copilot-proxy.githubusercontent.com
- Proxy URL: None (17 ms)
- Electron fetch (configured): HTTP 200 (840 ms)
- Node.js https: HTTP 200 (835 ms)
- Node.js fetch: HTTP 200 (840 ms)

Connecting to https://mobile.events.data.microsoft.com: HTTP 404 (355 ms)
Connecting to https://dc.services.visualstudio.com: HTTP 404 (1374 ms)
Connecting to https://copilot-telemetry.githubusercontent.com/_ping: HTTP 200 (1099 ms)
Connecting to https://copilot-telemetry.githubusercontent.com/_ping: HTTP 200 (941 ms)
Connecting to https://default.exp-tas.com: HTTP 400 (1800 ms)

Number of system certificates: 107

## Documentation

In corporate networks: [Troubleshooting firewall settings for GitHub Copilot](https://docs.github.com/en/copilot/troubleshooting-github-copilot/troubleshooting-firewall-settings-for-github-copilot).