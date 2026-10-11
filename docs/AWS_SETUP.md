# AWS setup guide: from nothing to a running Special

This guide takes you from "I have no AWS account" to Special serving HTTPS on your own domain, backed by DynamoDB, with logs, backups and a way to update and roll back. Commands are shown for **Windows PowerShell** first (that is where this project was written); the bash equivalents are noted where they differ.

> **Status of this guide.** What has been executed: the whole application, including every DynamoDB operation, runs against DynamoDB Local (27 service tests plus an HTTP end-to-end test), and the CloudFormation template parses with the projection list checked against the code. What has **not** been executed: the Docker build, the CloudFormation deployment, IAM permissions and the EC2 first-boot script, because the author had no Docker or AWS account available. Treat your first run as a shakedown, follow the verification steps, and fix the template or this guide if a step disagrees with reality.

## 0. What you will end up with

```text
                        Internet
                           |  DNS A record -> Elastic IP
                  +--------v---------+      AWS account / one region
                  |   EC2 t3.small   |
                  |  Docker Compose  |
                  |  +------------+  |      +-----------------------+
                  |  | Caddy 80/443|-+---->|  CloudWatch Logs      |
                  |  +-----+------+  |      +-----------------------+
                  |        |         |      +-----------------------+
                  |  +-----v------+  +----->|  DynamoDB table       |  (all data, PITR backups)
                  |  | Special app|  |      +-----------------------+
                  |  | PHP/Apache |  |      +-----------------------+
                  |  +------------+  +----->|  SSM Parameter Store  |  (SPECIAL_APP_KEY)
                  +--------^---------+      +-----------------------+
   you: SSM Session        |                +-----------------------+
   Manager (no SSH) -------+   image pulled |  ECR repository       |
                              from -------->|  (the Docker image)   |
                                            +-----------------------+
```

| Piece | Why |
|---|---|
| **EC2** instance (Amazon Linux 2023) | Runs Docker. No SSH: you get a shell through SSM Session Manager. IMDSv2 only. |
| **Caddy** container | Terminates TLS with an automatic Let's Encrypt certificate and proxies to the app. |
| **Special** container | PHP 8.3 + Apache. Stateless apart from PHP session files on a Docker volume. |
| **DynamoDB** table | The only database. On-demand billing, point-in-time recovery, deletion protection. |
| **ECR** repository | Where the image you build is stored; the instance pulls from it. |
| **IAM** instance role | Lets the instance read/write *only* this table, read *only* its SSM parameters, pull from ECR, write its log group and be managed by SSM. No access keys exist anywhere. |
| **SSM Parameter Store** | Holds the application key (`SecureString`). |
| **CloudWatch Logs** | Container logs, 30 days by default. |

**Time:** about 60–90 minutes the first time, most of it account setup and waiting.
**Cost:** roughly **US$20/month** at the time of writing (t3.small ≈ $15, public IPv4 ≈ $3.65, 20 GB gp3 ≈ $1.60, DynamoDB/ECR/CloudWatch at this volume ≈ cents). Check the pricing pages for your region; stop or delete the stack when experimenting.

---

## 1. Prepare your workstation

Install:

1. **Docker Desktop** (Windows: WSL 2 backend). Verify: `docker version`.
2. **AWS CLI v2**: <https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html>. Verify: `aws --version` (must say `aws-cli/2.x`).
3. **Session Manager plugin** for the AWS CLI: <https://docs.aws.amazon.com/systems-manager/latest/userguide/session-manager-working-with-install-plugin.html>. Verify: `session-manager-plugin`.
4. **Git**, to tag images with the commit.

You do not need PHP or Composer locally; the Docker build installs dependencies.

## 2. Create and secure the AWS account

If you already have an account with an administrator identity you can use from the CLI, skip to section 3.

1. **Sign up** at <https://aws.amazon.com/> with an email address you control and a payment card. This creates the **root user**.
2. **Protect the root user.** Sign in as root, open *Security credentials*, and enable **multi-factor authentication** (an authenticator app or a passkey). Do not create access keys for root, and from here on do not use it except for the few tasks that require it.
3. **Set a budget alert** so a mistake cannot become a surprise: *Billing and Cost Management → Budgets → Create budget → Cost budget*, monthly, for example $30, email alert at 80%.
4. **Create an administrator identity for yourself.** The recommended way is **IAM Identity Center**:
   1. Console → search *IAM Identity Center* → **Enable** (choose the same region you will deploy to, for example `eu-central-1`; Identity Center lives in one region). If it offers to create an AWS Organization, accept; it costs nothing.
   2. *Users → Add user*: your name and email; you will get an invitation mail to set a password and MFA.
   3. *Permission sets → Create* → predefined **AdministratorAccess**.
   4. *AWS accounts → select your account → Assign users or groups* → your user + the permission set.
   5. Note the **AWS access portal URL** shown on the Identity Center dashboard.

   *Simpler but weaker alternative:* an IAM user with `AdministratorAccess` and an access key (`aws configure`). Long-lived keys are a liability; prefer Identity Center.
5. **Sign in from the CLI.**

   ```powershell
   aws configure sso
   #   SSO session name: special
   #   SSO start URL:    <your access portal URL>
   #   SSO region:       <the region you enabled Identity Center in>
   #   scopes:           (accept default)
   #   -> pick the account and the AdministratorAccess role
   #   CLI default client Region: eu-central-1   (the region you will deploy to)
   #   CLI profile name: special-admin
   $env:AWS_PROFILE = "special-admin"
   aws sso login
   aws sts get-caller-identity       # shows your account id and role: proof it works
   ```

   (bash: `export AWS_PROFILE=special-admin`.) Sessions expire after a few hours; re-run `aws sso login` when commands start failing with *token expired*.

6. **Pick one region** and stay in it for everything (this guide uses `eu-central-1`). Pass `--region` or set `AWS_REGION` if your profile does not already specify it. The console shows the active region at the top right; resources created in a different region will look like they vanished.

## 3. Get a domain name

Caddy needs a public host name to obtain a certificate, and MCP clients need a stable HTTPS URL.

- **New domain:** register one in *Route 53 → Registered domains* (a few dollars per year, creates a hosted zone), or at any registrar.
- **Existing domain:** you only need permission to add an **A record** for a subdomain such as `special.example.com`.

Decide the exact host name now; you will pass it to the stack.

## 4. Create the infrastructure

From the repository root:

```powershell
aws cloudformation deploy `
  --stack-name special `
  --template-file deploy/aws/special-stack.yaml `
  --capabilities CAPABILITY_IAM `
  --parameter-overrides DomainName=special.example.com
```

(bash: use `\` instead of the backtick.)

This takes 3–5 minutes. It creates: a small VPC with one public subnet, the security group (80/443 only), the DynamoDB table, the ECR repository, the log group, the IAM role and instance profile, the EC2 instance (which installs Docker on first boot), and an Elastic IP.

Optional parameters: `InstanceType` (`t3.micro` works for a handful of users; `t3.medium` for headroom), `TableName`, `SsmPrefix`, `LogRetentionDays`.

Read the outputs:

```powershell
aws cloudformation describe-stacks --stack-name special --query "Stacks[0].Outputs" --output table
```

Note **PublicIp**, **ImageRepositoryUri**, **InstanceId** and **ConnectCommand**.

If the deploy fails, the failing resource and reason are in *CloudFormation → Stacks → special → Events* (or `aws cloudformation describe-stack-events --stack-name special`). Delete a failed first attempt with `aws cloudformation delete-stack --stack-name special` before retrying.

## 5. Point DNS at the instance

Create an **A record** `special.example.com → <PublicIp>`.

- Route 53: *Hosted zones → your zone → Create record → Simple routing*, type A, value = the IP, TTL 300.
- Other DNS providers: the same record in their UI.

Check from your workstation (propagation can take minutes):

```powershell
nslookup special.example.com
```

## 6. Create the application secret

`SPECIAL_APP_KEY` signs share links, pagination cursors and token hashes. It must be at least 32 bytes and must stay secret and stable (changing it invalidates share links and all OAuth tokens).

```powershell
$bytes = New-Object byte[] 48
[Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
$key = [Convert]::ToBase64String($bytes)
aws ssm put-parameter --name /special/prod/app-key --type SecureString --value $key
Remove-Variable key, bytes
```

(bash: `aws ssm put-parameter --name /special/prod/app-key --type SecureString --value "$(openssl rand -base64 48)"`.)

The name must be `<SsmPrefix>/app-key`; `/special/prod` is the default prefix. **Back this value up** in your password manager if you want to be able to rebuild the host without invalidating links: read it with `aws ssm get-parameter --name /special/prod/app-key --with-decryption --query Parameter.Value --output text`.

## 7. Build and push the image

Make sure Docker Desktop is running.

```powershell
./deploy/aws/push-image.ps1
```

(bash: `./deploy/aws/push-image.sh`.) The script logs in to ECR, builds `linux/amd64`, and pushes `:latest` plus `:<git short sha>`. The first build downloads PHP, Apache and the AWS SDK and takes a few minutes.

> **Reproducible builds.** The Docker build runs `composer install` against the committed `composer.lock`, so every build uses the same dependency versions. To upgrade them deliberately: `docker run --rm -v ${PWD}:/app -w /app composer:2 composer update --no-dev --ignore-platform-reqs` (bash: `$(pwd)`), run the tests, and commit the new lock.

## 8. Deploy on the instance

Open a shell on the instance. No SSH key or open port is involved; the connection goes through AWS Systems Manager using the instance role.

```powershell
aws ssm start-session --target i-0123456789abcdef0     # the InstanceId output
```

If it says the instance is not connected, wait a minute or two (the SSM agent registers shortly after boot) and check that the instance is *Running* with 2/2 status checks.

On the instance:

```bash
sudo tail -n 5 /var/log/cloud-init-output.log     # first boot finished? (Docker + compose installed)
sudo special-deploy                               # deploys :latest
```

`special-deploy` logs in to ECR, pulls the image, extracts the compose file and Caddyfile *from the image*, reads the application key from SSM, writes `/opt/special/stack/.env`, starts the stack and waits for the health check. On success it prints `>> Healthy. Deployed ...`.

If DNS already points at the instance, Caddy now obtains a certificate automatically (ports 80 and 443 must be reachable; the security group allows them). Watch it with `sudo docker compose -f /opt/special/stack/compose.prod.yml logs -f caddy`.

## 9. Create the first administrator

Still on the instance. Pick the username (an email address works) and type the password when asked; it is not stored in your shell history.

```bash
cd /opt/special/stack
read -rs -p "Admin password (12+ characters): " PW; echo
sudo docker compose -f compose.prod.yml exec -T -e SPECIAL_BOOTSTRAP_ADMIN_PASSWORD="$PW" \
  app php scripts/bootstrap-admin.php --username you@example.com
unset PW
```

Expected: `Created first administrator with id 1.` This command works exactly once for the life of the table.

## 10. Verify

On the instance:

```bash
sudo docker compose -f compose.prod.yml exec -T app php scripts/deployment-check.php
```

Every line should say `[ ok ]`: configuration, store reachable, table active, indexes `gsi1,gsi2`, TTL enabled, administrator exists.

From your workstation:

```powershell
curl.exe -s https://special.example.com/health        # {"status":"ok"}
```

Then in a browser: open `https://special.example.com`, sign in, create a spec (it is **public** by default; try the *Private* option), open **Projects**, and create a second user under **Users** to confirm they see the public spec but not your private one.

Logs: *CloudWatch → Log groups → /special/app* (streams `app` and `caddy`).

## 11. Connect an MCP client

The MCP endpoint is `https://special.example.com/mcp`. Clients that support OAuth dynamic registration or Client ID Metadata Documents (most current ones) need nothing more: add the URL, approve on the consent page after signing in. For a fixed client, register it on the instance:

```bash
sudo docker compose -f compose.prod.yml exec -T app \
  php scripts/register-oauth-client.php --client-id my-client --name "My client" \
  --redirect-uri https://client.example.com/callback
```

Details: [`OAUTH.md`](OAUTH.md), [`MCP_CONTRACT.md`](MCP_CONTRACT.md).

---

## Day 2: operating it

### Update to a new version

```powershell
./deploy/aws/push-image.ps1              # builds, pushes :latest and :<sha>
aws ssm start-session --target i-0123456789abcdef0
```
```bash
sudo special-deploy                      # or: sudo special-deploy <sha>
```

The app restarts in a few seconds; sessions survive (they are on a volume) and so do data (DynamoDB).

### Roll back

Every push is also tagged with its git commit. `sudo special-deploy <previous-sha>` redeploys that exact image. (`aws ecr list-images --repository-name special` lists tags.)

### Logs and shell access

```bash
cd /opt/special/stack
sudo docker compose -f compose.prod.yml ps
sudo docker compose -f compose.prod.yml logs --tail 100 app
```

CloudWatch keeps them for the retention you chose. Two useful *Logs Insights* queries on `/special/app`: `filter @message like /MCP request_id/` and `filter @message like /Special request failure/`.

### Backups and restore

Point-in-time recovery is on: you can restore to any second in the last 35 days.

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
# on the instance: point the stack at the restored table and redeploy
sudo sed -i 's/^SPECIAL_DYNAMODB_TABLE=.*/SPECIAL_DYNAMODB_TABLE=special-restore/' /opt/special/instance.env
sudo special-deploy
```

(The instance role already permits tables whose names start with `special`.) Keep the original table until you are sure; deleting it first requires turning off its deletion protection. Run `scripts/deployment-check.php` after cutting over. Consider also taking a manual backup before risky changes: `aws dynamodb create-backup --table-name special --backup-name before-change`.

### Rotate the application key

Replace the parameter (`aws ssm put-parameter --overwrite ...`) and run `sudo special-deploy`. Effects: every share link, pagination cursor and OAuth token stops working and MCP clients must re-authorize. Use it as an incident "sign everyone out" switch.

### Patch the host

The container image is rebuilt and redeployed for application and PHP updates. For the host OS itself: `sudo dnf update -y && sudo reboot` (the containers restart on boot via `restart: unless-stopped`). Everything of value is in DynamoDB or SSM, so if a host ever misbehaves you can also delete the instance resource and let CloudFormation recreate it (`aws cloudformation deploy` after removing it, or terminate it and run a stack update): first boot reinstalls Docker, then `sudo special-deploy` brings the app back. Caddy re-issues its certificate; PHP session files are lost, so users sign in again (remember-me tokens live in DynamoDB and keep working).

### Change the instance size or the domain

Re-run `aws cloudformation deploy` with new `--parameter-overrides` (`InstanceType`, `DomainName`). Be aware of two details:

- Changing the instance type stops and restarts the instance (a few minutes of downtime; the Elastic IP is kept).
- `DomainName` is written to `/opt/special/instance.env` only at **first boot**. After changing it, also edit that file on the instance (`sudo sed -i 's/^SPECIAL_DOMAIN=.*/SPECIAL_DOMAIN=new.example.com/' /opt/special/instance.env`), move the DNS record, and run `sudo special-deploy`. Share links already handed out point at the old host name and stop working once it no longer resolves here; MCP clients must re-authorize for the new resource URL.

### Tear down

```powershell
aws cloudformation delete-stack --stack-name special
```

Removes everything except the **DynamoDB table**, which is retained on purpose (and protected against deletion). To delete the data as well, turn off deletion protection (`aws dynamodb update-table --table-name special --no-deletion-protection-enabled`) and run `aws dynamodb delete-table --table-name special`. Remove the SSM parameter with `aws ssm delete-parameter --name /special/prod/app-key`.

---

## Security notes

- **No inbound access except 80/443.** Administration is via SSM Session Manager, authenticated and logged by AWS (enable session logging under *Systems Manager → Session Manager → Preferences* if you want transcripts).
- **IMDSv2 required**, hop limit 2 so containers can use the instance role. A compromised container can therefore obtain the instance role's credentials: that is why the role is limited to this table (and its derived restore names), two SSM parameters under one prefix, its log group, ECR pulls and SSM core.
- **No static credentials.** The app uses the instance role through the AWS SDK; short-lived credentials are cached on the container's disk with mode 0600.
- **Secrets** live in SSM `SecureString`; `/opt/special/stack/.env` (mode 0600, root) holds the key on the host while the stack runs.
- **Encryption:** EBS volume encrypted; DynamoDB encrypted at rest with an AWS-owned key; TLS in transit everywhere.
- **Not included:** AWS WAF, GuardDuty, multi-AZ failover, auto-scaling. For a small internal tool they are optional; the main limitation is that this is **one instance in one AZ**: a failed host means downtime until it is replaced (data is safe in DynamoDB).
- The OS user database of the container image and Apache are updated only when you rebuild the image. Rebuild monthly to pick up security fixes (`push-image` then `special-deploy`).

## Troubleshooting

| Symptom | Likely cause and fix |
|---|---|
| `aws ... ExpiredToken` / `Unable to locate credentials` | Run `aws sso login`; check `$env:AWS_PROFILE`. |
| Stack fails at the instance or table | Read the *Events* tab. Name conflicts (`special` table or `special` ECR repo already exist) mean a leftover from a previous attempt: choose another `TableName` or delete the leftover. |
| `special-deploy: command not found` | First boot is not finished. `sudo tail /var/log/cloud-init-output.log`; wait for it to end. |
| `special-deploy` fails at `docker pull` with *not found* | You have not pushed the image yet (section 7), or the tag does not exist. |
| `AccessDeniedException` from DynamoDB in the logs | The container cannot use the instance role. Check the instance has the profile, IMDS hop limit is 2 (`aws ec2 describe-instances --query "Reservations[].Instances[].MetadataOptions"`), and the table name in `instance.env` starts with the stack's `TableName`. |
| `/health` returns 503 | `sudo docker compose -f compose.prod.yml exec app php scripts/deployment-check.php` names the failing check. |
| `ResourceNotFoundException: Requested resource not found` | Wrong table name or region in `instance.env`, or the table was deleted. |
| Browser shows a certificate error | DNS does not point at the Elastic IP yet, or port 80/443 is blocked. `docker compose logs caddy` shows the ACME attempts; Let's Encrypt rate-limits repeated failures, so fix DNS first. |
| Login works but you are logged out on every request | `SPECIAL_SESSION_SECURE=1` with plain HTTP, or the domain in `SPECIAL_PUBLIC_BASE_URL` differs from the one you browse. Use the HTTPS URL of `DomainName`. |
| New spec missing from the list for a moment | Index reads are eventually consistent; refresh. The spec page itself is always current. |
| MCP client gets 401 with a token it just received | `SPECIAL_PUBLIC_BASE_URL` must equal the URL the client uses; tokens are bound to `<base>/mcp`. |

## Appendix A: what the stack creates (for doing it by hand)

If you prefer the console or another tool, these are the pieces and the settings that matter:

1. **DynamoDB table** `special`: partition key `pk` (S), sort key `sk` (S); on-demand; TTL on `ttl`; PITR on; deletion protection on; GSIs `gsi1` (`gsi1pk` S / `gsi1sk` S) and `gsi2` (`gsi2pk` S / `gsi2sk` S), each projecting the attributes listed in [`src/Storage/DynamoSchema.php`](../src/Storage/DynamoSchema.php). (`php scripts/init-table.php` creates exactly this against any endpoint, including real AWS.)
2. **IAM role** for EC2 with: `AmazonSSMManagedInstanceCore`, `AmazonEC2ContainerRegistryReadOnly`, and an inline policy allowing `dynamodb:GetItem/PutItem/UpdateItem/DeleteItem/Query/ConditionCheckItem/DescribeTable/DescribeTimeToLive` on the table and `table/special*/index/*`, `ssm:GetParameter` on `parameter/special/prod/*`, and `logs:CreateLogStream/PutLogEvents` on the log group. (`TransactWriteItems` needs no separate permission: it is authorized by the underlying put/update/delete actions.)
3. **EC2** Amazon Linux 2023, x86_64, public IP (Elastic IP), security group allowing TCP 80 and 443, IMDSv2 required with hop limit 2, encrypted gp3 volume, instance profile from step 2.
4. **ECR** repository `special`; **CloudWatch** log group `/special/app`; **SSM** parameter `/special/prod/app-key`.
5. On the host: Docker, the Compose plugin, `/opt/special/instance.env` and the `special-deploy` wrapper (see the `UserData` block in the template).

## Appendix B: trying it locally first

No AWS account is needed to run the whole application against DynamoDB Local:

```powershell
copy .env.example .env          # optional: set SPECIAL_BOOTSTRAP_ADMIN_PASSWORD
docker compose up --build
docker compose exec app php scripts/bootstrap-admin.php --username admin
```

Open <http://localhost:8080>. The `app` container creates the table on start (`SPECIAL_AUTO_INIT_TABLE=1`). `docker compose down -v` deletes the local data.
