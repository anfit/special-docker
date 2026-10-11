# Edge integration: what Special needs from the load balancer / proxy

Special does not provide, own or renew TLS. In a corporate deployment the **Application Load Balancer, the ACM certificate, the DNS name and the WAF belong to the team that runs the edge**. This page is the contract between the two sides: what the edge team must provide, what Special exposes to make that easy, and a request you can hand over as is.

## What Special gives the edge

| Item | Value |
|---|---|
| Protocol / port | plain **HTTP on TCP 80** of the host (container port 80 published on the host) |
| Health check | `GET /health` → **200** with JSON `{"status":"ok","version":...,"commit":...}`; **503** when storage or configuration is broken. Add `"warnings":["insecure_http"]` while plain-HTTP mode is on |
| Target | the EC2 instance (instance id / private IP). One host, one availability zone |
| Network | the instance security group admits nothing by default; the edge team's security group (or CIDR) is added as the source for TCP 80 |
| Stickiness | not needed (sessions are on the host; there is only one host) |

## What Special needs from the edge

1. **Terminate TLS** with a certificate valid for the final host name, and redirect HTTP to HTTPS.
2. **Forward to the instance on TCP 80** and health-check `GET /health` expecting 200 (interval 15–30 s, 2–3 healthy / unhealthy thresholds is fine).
3. **Set `X-Forwarded-For`** with the real client address. An ALB appends it. Special trusts the entry `SPECIAL_TRUSTED_PROXY_HOPS` places from the right (default 1 = the proxy adjacent to Special). If another trusted proxy sits in front of the ALB, tell the Special team so the hop count is raised; if the edge does *not* set the header, login throttling and audit will see the proxy's address for every user.
4. **Pass requests through unmodified**: methods `GET`, `POST` (and `OPTIONS` for `/mcp`), the `Authorization` header, query strings, bodies up to **4 MB**, and no path rewriting or prefixing. The application must be served at the **root** of the host name.
5. **Do not cache** `/mcp`, `/oauth/*`, `/.well-known/*`, `/share/*` or any authenticated page. Static assets under `/` (for example `/spec-copy.js`) may be cached.
6. **Idle/response timeout ≥ 60 s** (requests are short; the default 60 s of an ALB is fine).
7. **Use the agreed host name** for users *and* for MCP clients. It must equal `SPECIAL_PUBLIC_BASE_URL` exactly (scheme + host, no path, no trailing slash). OAuth tokens, MCP resource binding and share links are derived from it, so changing it later signs MCP clients out and breaks handed-out share links.
8. **Do not route `/metrics`.** It is for the internal Prometheus only, which scrapes the instance directly (see [`METRICS.md`](METRICS.md)).
9. **WAF / rate limiting** (optional): allow the paths above; avoid rules that block JSON-RPC `POST` bodies on `/mcp` or the OAuth `POST`s.
10. **MCP clients in the vendor's cloud** (hosted chat assistants) need an internet-facing listener with a publicly trusted certificate. Clients running on corporate machines (desktop/CLI tools) can use an internal listener and a corporate CA.

## What the Special side does once the edge exists

1. Set `SPECIAL_PUBLIC_BASE_URL=https://<agreed name>` and `SPECIAL_INSECURE_HTTP=0` (and `SPECIAL_TRUSTED_PROXY_HOPS` if not 1) in `/opt/special/instance.env`, then `sudo special-deploy`. With `INSECURE_HTTP=0` the application refuses to run on an `http://` base URL.
2. Verify `https://<name>/health` returns 200 without a `warnings` entry, target health is *healthy*, and a login works.
3. Register MCP clients (see [`AWS_SETUP.md`](AWS_SETUP.md), "Connect an MCP client").

While the edge does not exist yet, use the closed-by-default host through an SSM port-forward (`docs/AWS_SETUP.md`, section 6) with `SPECIAL_INSECURE_HTTP=1`.

## Tie-ins provided by the infrastructure code

| Where | Tie-in |
|---|---|
| CloudFormation stack (`deploy/aws/special-stack.yaml`) | outputs `PrivateIp`, `InstanceId`, `SecurityGroupId`, `PortForwardCommand`; parameters `IngressCidr` and `AlbSecurityGroupId` (admit the edge team's security group on TCP 80) |
| `special-docker-infra` (Terraform) | outputs `private_ip`, `instance_id`, `security_group_id`, `edge_integration` (a ready summary); variables `ingress_security_group_ids`, `ingress_cidrs`, and `target_group_arns` to register the instance with a target group the edge team created |

## Request template for the team that owns the edge

> Please publish **Special** behind our standard HTTPS entry point.
>
> * Public host name: `special.<our domain>` (certificate and DNS record on your side).
> * Target: EC2 instance `<instance id>` (`<private IP>`), **HTTP port 80**, VPC `<vpc id>`. Health check `GET /health`, expect `200`.
> * Please allow your load balancer's security group (`<sg id>`) as a source for TCP 80 on our security group `<sg id of the instance>` (or tell us the CIDR), **or** give us the ARN of a target group and we will register the instance ourselves.
> * Forward `X-Forwarded-For`, `Authorization` and request bodies up to 4 MB unmodified; no path rewriting; do not cache `/mcp`, `/oauth/*`, `/.well-known/*`, `/share/*`; **do not route `/metrics`**.
> * Number of trusted proxies in front of our app that append to `X-Forwarded-For`: `<n>` (a single ALB = 1).
> * Internet-facing listener with a publicly trusted certificate is required if hosted AI assistants must reach `/mcp`; an internal listener is fine for corporate desktop/CLI clients.
>
> Tell us when it is live; we will switch the application to HTTPS mode (`SPECIAL_INSECURE_HTTP=0`).
