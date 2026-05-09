# Lodenica infra (AWS via Terraform)

Provisions the cheapest viable single-instance deployment on AWS:

- **EC2** running the full Docker Compose stack (Postgres + backend + frontend nginx)
- **Elastic IP** so the public address is stable across instance restarts
- **Security group** opening 22 (SSH from your IP), 80 (HTTP), and 443 (HTTPS, reserved)
- **EBS** root volume only — Postgres data lives on a Docker named volume backed by the root volume

## Why a single EC2?

For a club with maybe 100 reservations a day, a single small instance is more
than enough. RDS (≈$13/mo on top) buys nothing we need at this scale.

| Option              | Specs              | Monthly (eu-central-1)        |
| ------------------- | ------------------ | ----------------------------- |
| `t3.micro` (x86)    | 2 vCPU, 1 GB RAM   | **$0** for 12 months · $7.59  |
| `t4g.micro` (ARM)   | 2 vCPU, 1 GB RAM   | $6.13                         |
| `t4g.small` (ARM)   | 2 vCPU, 2 GB RAM   | $12.26 — recommended sweet    |
| `t4g.medium` (ARM)  | 2 vCPU, 4 GB RAM   | $24.53                        |

Plus EBS gp3 root volume (20 GB ≈ $1.60/mo) and Elastic IP (free while
attached). Egress data transfer in eu-central-1 is $0.09/GB after the first
100 GB/mo free tier.

The default in `variables.tf` is **t3.micro** so the first 12 months are
free; bump to `t4g.small` for comfort once free tier expires.

## Prerequisites

1. AWS CLI authenticated (`aws sts get-caller-identity` returns your account)
2. Terraform ≥ 1.6
3. An SSH public key — by default Terraform reads `~/.ssh/id_ed25519.pub`

## Apply

```bash
cd infra
cp terraform.tfvars.example terraform.tfvars  # edit values
terraform init
terraform plan
terraform apply
```

Outputs:

```
ec2_public_ip = "1.2.3.4"
url           = "http://1.2.3.4"
ssh           = "ssh ubuntu@1.2.3.4"
```

## First deploy after `apply`

The instance's user-data installed Docker, created `/opt/lodenica`, and
fetched a stub `docker-compose.prod.yml`. To go live:

```bash
# 1. Copy your env file (with strong POSTGRES_PASSWORD!)
scp infra/.env.prod.example ubuntu@<ip>:/opt/lodenica/.env
ssh ubuntu@<ip> "vi /opt/lodenica/.env"   # set POSTGRES_PASSWORD, GHCR_OWNER, etc.

# 2. Trigger the GitHub Actions "Deploy" workflow (manual dispatch),
#    which SCPs the latest docker-compose.prod.yml and pulls the latest
#    images from GHCR. Repository → Actions → Deploy → Run workflow.

# Or do it manually one time:
ssh ubuntu@<ip>
cd /opt/lodenica
docker compose -f docker-compose.prod.yml --env-file .env pull
docker compose -f docker-compose.prod.yml --env-file .env up -d
```

## GitHub Actions secrets/variables

For the `Deploy` workflow to roll updates onto the instance, configure in the
repository's **Settings → Secrets and variables → Actions**:

| Kind     | Name             | Value                                      |
| -------- | ---------------- | ------------------------------------------ |
| Variable | `DEPLOY_HOST`    | EC2 Elastic IP from `terraform output`     |
| Variable | `DEPLOY_USER`    | `ubuntu`                                   |
| Secret   | `DEPLOY_SSH_KEY` | Contents of the private key paired w/ EC2  |

Plus an environment named `production` if you want approval gates.

## Tearing it down

```bash
terraform destroy
```

The Postgres data is on the root EBS volume which is attached to the
instance — destroy wipes it. For a backup: `pg_dump` before destroying.
