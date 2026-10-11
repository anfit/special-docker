# AWS setup guide: from nothing to a running Special

This guide takes you from "I have no AWS account" to Special running on one EC2 host, backed by DynamoDB, ready to sit behind the Application Load Balancer and ACM certificate that your organisation's edge team provides, with logs, backups and a way to update and roll back. Commands are shown for **Windows PowerShell** first; bash equivalents are noted where they differ.

> **Status of this guide.** Executed: the whole application, including every DynamoDB operation, runs against DynamoDB Local (service tests plus HTTP end-to-end tests), and the CloudFormation template parses. **Not** executed: the CloudFormation deployment, IAM permissions and the EC2 first-boot script, because the author had no AWS account available. Treat your first run as a shakedown and fix the template or this guide if a step disagrees with reality. The `special-docker-infra` repository automates the same layout in Terraform; this guide is the manual route and the explanation.

## 0. What you will end up with

```text
   users / MCP clients
          |  HTTPS :443  (ACM certificate on the ALB)
  +-------v---------------------+          AWS account / one region
  | ALB + ACM certificate       |
  | (the edge team's, not ours) |
  +-------+---------------------+
          | HTTP :80, X-Forwarded-For appended by the ALB
  +-------v---------+                      +-----------------------+
  |  EC2 t3.small   +--------------------->|  DynamoDB table       |  (all data, PITR backups)
  |  Docker         |                      +-----------------------+
  |  +-----------+  |                      +-----------------------+
  |  |  Special  +--+--------------------->|  SSM Parameter Store  |  (SPECIAL_APP_KEY)
  |  | PHP/Apache|  |                      +-----------------------+
  |  +-----------+  |                      +-----------------------+
  +------^----------+--------------------->|  CloudWatch Logs      |
         |                                 +-----------------------+
  you: SSM Session Manager (no SSH)        +-----------------------+
         image pulled from GHCR / ECR ---->|  container registry   |
                                           +-----------------------+
```

**Special never handles certificates.** The container speaks plain HTTP on port 80 and trusts the load balancer in front of it, so there is nothing to renew or provision in the application. You can deploy first, look at the app through a tunnel, and attach the ALB afterwards without changing the application.

| Piece | Why |
|---|---|
| **ALB + ACM certificate** (provided by the edge team) | Terminates TLS, health-checks `/health`, forwards HTTP to the instance. See [`EDGE_INTEGRATION.md`](EDGE_INTEGRATION.md). |
| **EC2** instance (Amazon Linux 2023) | Runs Docker. No SSH: shell and port-forwarding go through SSM Session Manager. IMDSv2 only. The instance accepts traffic **only from the edge team's load balancer**. |
| **Special** container | PHP 8.3 + Apache. Stateless apart from PHP session files on a Docker volume. |
| **DynamoDB** table | The only database. On-demand billing, point-in-time recovery, deletion protection. |
| **Image registry** | GHCR (public package) in the default setup, or this account's ECR. |
| **IAM** instance role | Read/write *only* this table, read *only* its SSM parameters, write its log group, be managed by SSM (and pull from ECR if used). No access keys. |
| **SSM Parameter Store** | Holds the application key (`SecureString`). |
| **CloudWatch Logs** | Container logs (30 days by default), including the one-time setup token. |

**Time:** about an hour. **Cost of what this guide creates:** roughly **US$20/month**: t3.small ≈ $15, 20 GB gp3 ≈ $1.60, a public IPv4 for outbound access ≈ $3.65, DynamoDB/CloudWatch ≈ cents. The load balancer and certificate are billed to whoever owns the edge. Check current pricing for your region.

---

## 1. Prepare your workstation

1. **AWS CLI v2**: <https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html>. Verify: `aws --version`.
2. **Session Manager plugin**: <https://docs.aws.amazon.com/systems-manager/latest/userguide/session-manager-working-with-install-plugin.html>. Verify: `session-manager-plugin`.
3. **Docker Desktop** only if you build or push the image yourself (the default flow uses the image published by CI).

## 2. Create and secure the AWS account

If you already have an account with an administrator identity usable from the CLI (typical in a corporate setting: ask for an account and a role), skip to section 3.

1. **Sign up** at <https://aws.amazon.com/>; this creates the **root user**.
2. **Protect the root user**: enable **multi-factor authentication**, create no access keys, use it only for the few tasks that require it.
3. **Set a budget alert**: *Billing and Cost Management → Budgets → Cost budget*, monthly, e.g. $50, email at 80%.
4. **Create an administrator identity** with **IAM Identity Center**: enable it in the region you will use, add yourself as a user, create a permission set from the predefined **AdministratorAccess**, assign it to your account, note the **access portal URL**.
5. **Sign in from the CLI.**

   ```powershell
   aws configure sso            # session name, start URL, region, account/role, default region, profile name
   $env:AWS_PROFILE = "special-admin"
   aws sso login
   aws sts get-caller-identity
   ```

6. **Pick one region** and stay in it (this guide uses `eu-central-1`).

## 3. Agree the name with the edge team

You need a **host name** users and MCP clients will use, e.g. `special.corp.example`. The DNS record, the certificate for it and the load balancer are provisioned by the edge team (section 7); you only have to agree the name with them.

**The host name is part of the application's identity.** OAuth tokens and MCP resource bindings are tied to `https://<name>/mcp`, share links embed it, and it is the OAuth issuer. Decide it before users start using the system.

MCP clients that run in a vendor's cloud need an **internet-facing** listener with a publicly trusted certificate; an internal listener or internal CA works only for clients running inside your network. Say which you need when you ask.

## 4. Create the host and the data

```powershell
aws cloudformation deploy `
  --stack-name special `
  --template-file deploy/aws/special-stack.yaml `
  --capabilities CAPABILITY_IAM `
  --parameter-overrides PublicBaseUrl=https://special.corp.example InsecureHttp=1
```

(bash: `\` instead of the backtick.) Keep `InsecureHttp=1` for now: it lets the app run over plain HTTP until the edge exists, and you will switch it off in section 7. `PublicBaseUrl` is already the final HTTPS name, so tokens and links will be right from the start.

The stack creates a small VPC with one public subnet, the DynamoDB table, ECR repository, log group, IAM role and the EC2 instance (Docker installed on first boot). The instance's security group opens **nothing** (`IngressCidr` defaults to nothing); section 7 lets the edge team's load balancer in.

Parameters you may want: `InstanceType`, `TableName`, `SsmPrefix`, `LogRetentionDays`; for the edge, `AlbSecurityGroupId` and `IngressCidr` (section 7).

```powershell
aws cloudformation describe-stacks --stack-name special --query "Stacks[0].Outputs" --output table
```

Note **InstanceId**, **PrivateIp**, **SecurityGroupId**, **ImageRepositoryUri** and **PortForwardCommand**. If the deploy fails, read *CloudFormation → Stacks → special → Events*; delete a failed first attempt with `aws cloudformation delete-stack --stack-name special` before retrying.

## 5. Create the application secret

`SPECIAL_APP_KEY` signs share links, pagination cursors and token hashes. At least 32 bytes, secret and stable (changing it invalidates share links and all OAuth tokens).

```powershell
$bytes = New-Object byte[] 48
[Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
$key = [Convert]::ToBase64String($bytes)
aws ssm put-parameter --name /special/prod/app-key --type SecureString --value $key
Remove-Variable key, bytes
```

(bash: `aws ssm put-parameter --name /special/prod/app-key --type SecureString --value "$(openssl rand -base64 48)"`.) The name is `<SsmPrefix>/app-key`. Back the value up in your password manager if you want to rebuild the host without invalidating links.

## 6. Deploy on the instance and create the first administrator

Open a shell on the instance (no SSH key or open port; this goes through Systems Manager):

```powershell
aws ssm start-session --target i-0123456789abcdef0     # the InstanceId output
```

If it says the instance is not connected, wait a minute or two and check it is *Running* with 2/2 status checks. Then:

```bash
sudo tail -n 5 /var/log/cloud-init-output.log     # first boot finished?
```

**Point the host at the published image** (GHCR, public package, no login):

```bash
sudo sed -i 's#^SPECIAL_IMAGE_REPO=.*#SPECIAL_IMAGE_REPO=ghcr.io/<owner>/special-docker#' /opt/special/instance.env
printf '#!/bin/bash\nexit 0\n' | sudo tee /opt/special/registry-login.sh >/dev/null && sudo chmod +x /opt/special/registry-login.sh
sudo special-deploy 1.0.0                         # a release tag, or sha256:<digest>
```

(To use the stack's ECR repository instead, skip the two lines above and push with `./deploy/aws/push-image.ps1`.) `special-deploy` pulls the image, extracts the compose file from it, reads the application key from SSM, writes `/opt/special/stack/.env`, starts the container and waits for the health check; on success it prints `>> Healthy. Deployed ...`.

**First look, through a tunnel** (no network exposure yet): from your workstation run the stack's **PortForwardCommand** output, which is

```powershell
aws ssm start-session --region eu-central-1 --target i-0123456789abcdef0 `
  --document-name AWS-StartPortForwardingSession --parameters portNumber=80,localPortNumber=8080
```

and browse <http://localhost:8080>. While the system has no administrator, every page redirects to **/setup**:

1. The setup page asks for a **setup token**, generated and written **once** to the application log the first time `/setup` is opened: CloudWatch (*Log groups → /special/app → stream `app`*, search `Special first-run setup token`), or on the instance `sudo docker compose -f /opt/special/stack/compose.prod.yml logs app | grep "setup token"`. It is valid 24 hours (opening `/setup` after it expires prints a new one); only a hash is stored.
2. Enter the token, an administrator username (login name or email) and a password twice. You are signed in, and `/setup` returns *404* from then on.

To use a token you choose (automation), set `SPECIAL_SETUP_TOKEN` (20+ characters) in `/opt/special/instance.env` before deploying. Fallback without web access: `sudo docker compose -f /opt/special/stack/compose.prod.yml exec -T -e SPECIAL_BOOTSTRAP_ADMIN_PASSWORD='...' app php scripts/bootstrap-admin.php --username you@example.com`; both paths share one once-only guard.

## 7. Hand over to the team that runs the load balancer (TLS)

The Application Load Balancer, the ACM certificate, the DNS record and any WAF are **the edge team's responsibility, not Special's**. What you provide is the contract in [`EDGE_INTEGRATION.md`](EDGE_INTEGRATION.md): the target (instance, HTTP port 80), the health check (`GET /health` → 200), the header and caching requirements, and a request template you can send as is. The stack outputs give you everything to fill it in: **InstanceId**, **PrivateIp**, **SecurityGroupId**.

To let their load balancer in, either:

* pass their security group: `--parameter-overrides AlbSecurityGroupId=sg-0abc...` (or `IngressCidr=<their CIDR>`) on the stack, or add an inbound TCP 80 rule on **SecurityGroupId** yourself; or
* ask them for a **target group ARN** and register the instance (`aws elbv2 register-targets --target-group-arn <arn> --targets Id=<InstanceId>,Port=80`).

When they confirm the listener is live, switch the application to HTTPS mode on the instance:

```bash
sudo sed -i 's#^SPECIAL_INSECURE_HTTP=.*#SPECIAL_INSECURE_HTTP=0#; s#^SPECIAL_PUBLIC_BASE_URL=.*#SPECIAL_PUBLIC_BASE_URL=https://special.corp.example#' /opt/special/instance.env
sudo special-deploy
```

(Add `SPECIAL_TRUSTED_PROXY_HOPS=<n>` if they run more than one trusted proxy.) With `SPECIAL_INSECURE_HTTP=0` the application insists on an `https://` base URL and secure cookies and refuses to start otherwise (`/health` returns 503, so a health-checking edge will mark the host unhealthy rather than serve a misconfigured app). Keep the CloudFormation parameters in step (`InsecureHttp=0`) for the next stack update.

Until the edge exists you can carry on through the tunnel in section 6; only MCP clients need the HTTPS endpoint.

## 8. Verify

On the instance:

```bash
sudo docker compose -f /opt/special/stack/compose.prod.yml exec -T app php scripts/deployment-check.php
```

Every line should say `[ ok ]`: configuration (with the version), store reachable, table active, indexes `gsi1,gsi2`, TTL enabled, administrator exists.

From your workstation: `curl.exe -s https://special.corp.example/health` returns `{"status":"ok","version":"1.0.0",...}` with no `warnings` entry. In the browser, sign in, create a spec (**public** by default; try *Private*), open **Projects**, create a second user and confirm they see the public spec but not your private one. The page footer shows the deployed version. Target group health in the console should be *healthy*.

## 9. Connect an MCP client

The endpoint is `https://special.corp.example/mcp`. Clients supporting OAuth dynamic registration or Client ID Metadata Documents need nothing more: add the URL and approve on the consent page after signing in. For a fixed client:

```bash
sudo docker compose -f /opt/special/stack/compose.prod.yml exec -T app \
  php scripts/register-oauth-client.php --client-id my-client --name "My client" \
  --redirect-uri https://client.example.com/callback
```

Details: [`OAUTH.md`](OAUTH.md), [`MCP_CONTRACT.md`](MCP_CONTRACT.md).

---

## Day 2: operating it

### Update to a new version

Releases are tags in the GitHub repository; CI publishes `ghcr.io/<owner>/special-docker:<version>`. On the instance:

```bash
sudo special-deploy 1.1.0          # or sha256:<digest> for an immutable pin
```

The container restarts in a few seconds (the edge may show the host unhealthy for that moment); sessions survive (volume) and so do data (DynamoDB). Building and pushing yourself instead: `./deploy/aws/push-image.ps1` (ECR).

### Roll back

`sudo special-deploy <previous-version>`. Data is in DynamoDB and releases keep the schema additive, so rolling the container back is safe unless a release note says otherwise.

### Logs and shell access

```bash
cd /opt/special/stack
sudo docker compose -f compose.prod.yml ps
sudo docker compose -f compose.prod.yml logs --tail 100 app
```

CloudWatch keeps them for the retention you chose. Useful *Logs Insights* queries on `/special/app`: `filter @message like /MCP request_id/` and `filter @message like /Special request failure/`. Ask the edge team for access logs if you need request-level history.

### Backups and restore

Point-in-time recovery is on: restore to any second in the last 35 days.

```powershell
aws dynamodb restore-table-to-point-in-time `
  --source-table-name special --target-table-name special-restore `
  --use-latest-restorable-time
aws dynamodb wait table-exists --table-name special-restore
```

A restore creates a **new table without TTL or PITR settings**. Turn them back on, then cut over:

```powershell
aws dynamodb update-time-to-live --table-name special-restore --time-to-live-specification "Enabled=true,AttributeName=ttl"
aws dynamodb update-continuous-backups --table-name special-restore --point-in-time-recovery-specification PointInTimeRecoveryEnabled=true
```
```bash
sudo sed -i 's/^SPECIAL_DYNAMODB_TABLE=.*/SPECIAL_DYNAMODB_TABLE=special-restore/' /opt/special/instance.env
sudo special-deploy
```

(The instance role already permits tables whose names start with `special`.) Keep the original until you are sure; deleting it first requires turning off its deletion protection. Run `scripts/deployment-check.php` after cutting over. Take a manual backup before risky changes: `aws dynamodb create-backup --table-name special --backup-name before-change`.

### Rotate the application key

Replace the parameter (`aws ssm put-parameter --overwrite ...`) and run `sudo special-deploy`. Every share link, pagination cursor and OAuth token stops working and MCP clients must re-authorize. Use it as an incident "sign everyone out" switch.

### Patch the host

The image is rebuilt and redeployed for application and PHP updates. For the host OS: `sudo dnf update -y && sudo reboot` (the container restarts on boot; the edge's health check drains and re-adds the host). Everything of value is in DynamoDB or SSM, so a misbehaving host can be replaced: terminate it and run `aws cloudformation deploy` again (or rebuild it with Terraform in `special-docker-infra`), ask the edge team to re-register it in the target group (or re-run your `register-targets`), then `sudo special-deploy`. PHP session files are lost, so users sign in again (remember-me tokens live in DynamoDB and keep working).

### Reset a user's password

*Users → Reset password* (administrators only). It sets a random temporary password shown once, signs the user out everywhere, revokes their MCP tokens, and forces a new password at next sign-in. Administrators change their own password on the *Account* page.

### Tear down

Ask the edge team to remove the listener/target group registration, then:

```powershell
aws cloudformation delete-stack --stack-name special
```

This removes everything except the **DynamoDB table**, retained on purpose (and protected against deletion). To delete the data too: `aws dynamodb update-table --table-name special --no-deletion-protection-enabled`, then `aws dynamodb delete-table --table-name special`. Remove the SSM parameter with `aws ssm delete-parameter --name /special/prod/app-key`.

---

## Security notes

- **Closed by default.** The instance accepts nothing until you allow the edge team's security group (or CIDR). Administration is via SSM Session Manager (authenticated and logged by AWS; enable session logging in *Systems Manager → Session Manager → Preferences* for transcripts).
- **Plain HTTP stops at the edge.** The load-balancer-to-instance hop is unencrypted inside the VPC. If your policy requires encryption in transit all the way to the host, re-encrypt: HTTPS target group and a certificate on the instance. That is not built in.
- **IMDSv2 required**, hop limit 2 so containers can use the instance role. A compromised container can therefore obtain the role's credentials: the role is limited to this table (and derived restore names), two SSM parameters under one prefix, its log group, and ECR pulls.
- **No static credentials.** Short-lived instance-role credentials are cached on the container's disk with mode 0600.
- **Secrets** live in SSM `SecureString`; `/opt/special/stack/.env` (mode 0600, root) holds the key on the host while the stack runs.
- **Encryption:** EBS volume encrypted; DynamoDB encrypted at rest with an AWS-owned key; TLS from users to the edge.
- **Not included:** WAF, GuardDuty, multi-AZ failover, auto-scaling. You can attach AWS WAF at the edge. The main limitation is **one instance in one AZ**: a failed host means downtime until replaced (data is safe).
- Rebuild/redeploy monthly to pick up base-image security fixes.

## Troubleshooting

| Symptom | Likely cause and fix |
|---|---|
| `aws ... ExpiredToken` / `Unable to locate credentials` | `aws sso login`; check `$env:AWS_PROFILE`. |
| Stack fails at the instance or table | Read the *Events* tab. Name conflicts (`special` table or ECR repo exist) mean a leftover: choose another `TableName` or delete it. |
| `special-deploy: command not found` | First boot not finished: `sudo tail /var/log/cloud-init-output.log`. |
| `special-deploy` fails at `docker pull` | Wrong `SPECIAL_IMAGE_REPO` in `instance.env`, the tag does not exist, or (GHCR) the package is not public. |
| `AccessDeniedException` from DynamoDB in the logs | The container cannot use the instance role. Check the profile, IMDS hop limit 2 (`aws ec2 describe-instances --query "Reservations[].Instances[].MetadataOptions"`), and that the table name starts with the stack's `TableName`. |
| `/health` returns 503 | `scripts/deployment-check.php` names the failing check. A 503 right after setting `SPECIAL_INSECURE_HTTP=0` usually means the base URL is still `http://`. |
| Target group says *unhealthy* | Instance security group lacks the edge team's rule (section 7), the container is down (`docker compose ps`), or the app answers 503 (see above). |
| `ResourceNotFoundException` | Wrong table name or region in `instance.env`, or the table was deleted. |
| Setup page asks for a token I cannot find | Open `/setup` once (that generates it), then search the `app` log stream for `setup token`. Tokens last 24 hours. |
| Login works but you are logged out on every request | Cookies are `Secure` but the browser reached the app over `http://`, or the browser host differs from `PublicBaseUrl`. Use the ALB's HTTPS name. |
| Everyone shares one login-throttle bucket / audit shows the ALB's address | `X-Forwarded-For` is missing, or `TrustedProxyHops` is wrong for your chain. |
| MCP client gets 401 with a token it just received | `PublicBaseUrl` must equal the URL the client uses; tokens are bound to `<base>/mcp`. |
| New spec missing from the list for a moment | Index reads are eventually consistent; refresh. The spec page itself is always current. |

## Appendix A: what the stack creates (for doing it by hand)

1. **DynamoDB table** `special`: partition key `pk` (S), sort key `sk` (S); on-demand; TTL on `ttl`; PITR on; deletion protection on; GSIs `gsi1` and `gsi2` (`gsiNpk` S / `gsiNsk` S) projecting the attributes in [`src/Storage/DynamoSchema.php`](../src/Storage/DynamoSchema.php). (`php scripts/init-table.php` creates exactly this against any endpoint.)
2. **IAM role** for EC2: `AmazonSSMManagedInstanceCore`, `AmazonEC2ContainerRegistryReadOnly`, and an inline policy allowing `dynamodb:GetItem/PutItem/UpdateItem/DeleteItem/Query/ConditionCheckItem/DescribeTable/DescribeTimeToLive` on the table and `table/special*/index/*`, `ssm:GetParameter` on `parameter/special/prod/*`, and `logs:CreateLogStream/PutLogEvents` on the log group. (`TransactWriteItems` is authorized by the underlying put/update/delete actions.)
3. **EC2** Amazon Linux 2023, x86_64, IMDSv2 required with hop limit 2, encrypted gp3 volume, instance profile from step 2, security group allowing TCP 80 only from the edge team's load balancer.
4. **ECR** repository `special` (optional); **CloudWatch** log group `/special/app`; **SSM** parameter `/special/prod/app-key`.
5. On the host: Docker, the Compose plugin, `/opt/special/instance.env`, `/opt/special/registry-login.sh` (optional) and the `special-deploy` wrapper (the `UserData` block in the template).
6. Not ours: ALB, target group, listeners, ACM certificate, DNS record, WAF (the edge team; see `EDGE_INTEGRATION.md`).

## Appendix B: trying it locally first

No AWS account is needed to run the whole application against DynamoDB Local:

```powershell
docker compose up --build
```

Open <http://localhost:8080>: the first visit redirects to the setup page; the compose file sets a known setup token for local use (`SPECIAL_SETUP_TOKEN` in `docker-compose.yml`). `docker compose down -v` deletes the local data.
