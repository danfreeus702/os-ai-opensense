# Terraform + FLOCI: AWS Labs Without the AWS Bill

**Daniel Freeman (danfreeus702)**
**Version 1.0 — complete**

*Companion to the open-source OPNsense AI project: https://github.com/danfreeus702/os-ai-opensense*

---

## Why a local AWS lab

You cannot learn Terraform by reading alone, and a real AWS account can
produce a real bill. A local AWS emulator gives you the same provider workflow
with throwaway credentials and no blast radius.

FLOCI is an open-source, MIT-licensed local AWS emulator. Point the AWS CLI,
SDKs or Terraform at `http://localhost:4566` and use the normal tooling. This
guide builds three small labs you can finish in an afternoon.

A warning up front: an emulator is for development, testing and CI. It is not
a substitute for a final smoke test on a real account. IAM edge cases,
quotas, consistency and billing behave differently in production.

---

## Chapter 1 — Install FLOCI

The fastest path uses the official CLI:

```sh
curl -fsSL https://floci.io/install.sh | sh
floci start
eval $(floci env)
```

The `floci env` command prints the exports needed to point standard tools at
the local endpoint:

```sh
export AWS_ENDPOINT_URL=http://localhost:4566
export AWS_DEFAULT_REGION=us-east-1
export AWS_ACCESS_KEY_ID=test
export AWS_SECRET_ACCESS_KEY=test
```

Verify with the AWS CLI:

```sh
aws --endpoint-url http://localhost:4566 s3 ls
```

If you prefer Docker Compose:

```yaml
services:
  floci:
    image: floci/floci:latest
    ports:
      - "4566:4566"
    volumes:
      - ./data:/app/data
    environment:
      FLOCI_STORAGE_MODE: hybrid
```

Start with `docker compose up -d`. Pin the image version in real projects —
the emulator is young and changes quickly.

### Storage modes

| Mode | Behaviour | Use |
|---|---|---|
| `memory` | State lost on stop | CI, throwaway tests |
| `persistent` | Written to disk on every mutation | Maximum local durability |
| `hybrid` | In-memory with periodic flush | Long-running development |
| `wal` | Write-ahead log | Highest durability |

Use `memory` for labs you do not care about, `hybrid` for a work session that
survives container restarts.

---

## Chapter 2 — Point Terraform at FLOCI

The AWS provider can override service endpoints. With the environment
variables above, a plain provider block works:

```hcl
# provider.tf
terraform {
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.0"
    }
  }
}

provider "aws" {
  region = "us-east-1"
}
```

For explicit configuration, or when you want local and production in the same
repository, use an endpoint block:

```hcl
provider "aws" {
  region     = "us-east-1"
  access_key = "test"
  secret_key = "test"

  skip_credentials_validation = true
  skip_metadata_api_check     = true
  skip_requesting_account_id  = true
  s3_use_path_style           = true

  endpoints {
    dynamodb       = "http://localhost:4566"
    secretsmanager = "http://localhost:4566"
    s3             = "http://localhost:4566"
    sns            = "http://localhost:4566"
    sqs            = "http://localhost:4566"
    ssm            = "http://localhost:4566"
  }
}
```

You must list every service your configuration uses. If a resource is created
against real AWS by mistake, stop and check your variables before doing
anything else.

---

## Chapter 3 — Lab 1: S3, SQS, DynamoDB and SSM

Create `main.tf`:

```hcl
resource "aws_s3_bucket" "app" {
  bucket = "floci-terraform-example"
}

resource "aws_sqs_queue" "jobs" {
  name = "floci-terraform-jobs"
}

resource "aws_dynamodb_table" "items" {
  name         = "floci-terraform-items"
  billing_mode = "PAY_PER_REQUEST"
  hash_key     = "id"

  attribute {
    name = "id"
    type = "S"
  }
}

resource "aws_ssm_parameter" "environment" {
  name  = "/floci/environment"
  type  = "String"
  value = "local"
}
```

Run the standard lifecycle:

```sh
terraform init
terraform fmt
terraform validate
terraform plan
terraform apply
```

Verify from the AWS CLI:

```sh
aws --endpoint-url http://localhost:4566 s3api head-bucket \
  --bucket floci-terraform-example

aws --endpoint-url http://localhost:4566 sqs list-queues

aws --endpoint-url http://localhost:4566 dynamodb list-tables

aws --endpoint-url http://localhost:4566 ssm get-parameter \
  --name /floci/environment
```

Clean up:

```sh
terraform destroy
```

That is the whole point: real provider code, zero cloud cost.

---

## Chapter 4 — Lab 2: separate local and production

Never edit provider files back and forth. Use a variable:

```hcl
# variables.tf
variable "aws_endpoint" {
  type    = string
  default = ""
}
```

```hcl
# providers.tf
provider "aws" {
  region = "us-east-1"

  dynamic "endpoints" {
    for_each = var.aws_endpoint != "" ? [var.aws_endpoint] : []
    content {
      s3       = endpoints.value
      sqs      = endpoints.value
      dynamodb = endpoints.value
      sns      = endpoints.value
      ssm      = endpoints.value
    }
  }
}
```

Local run:

```sh
terraform apply -var="aws_endpoint=http://localhost:4566"
```

Production run uses your normal credentials and no override. Keep the two
paths documented in the README so nobody "temporarily" edits a provider file.

---

## Chapter 5 — Lab 3: state backends

By default Terraform state is a local `terraform.tfstate` file. That is fine
for labs. To emulate the real S3 + DynamoDB locking workflow:

```hcl
terraform {
  backend "s3" {
    bucket                      = "tfstate"
    key                         = "terraform.tfstate"
    region                      = "us-east-1"
    endpoint                    = "http://localhost:4566"
    dynamodb_endpoint           = "http://localhost:4566"
    dynamodb_table              = "tflock"
    access_key                  = "test"
    secret_key                  = "test"
    skip_credentials_validation = true
    skip_region_validation      = true
    use_path_style              = true
  }
}
```

Create the backend resources first:

```sh
aws --endpoint-url http://localhost:4566 s3api create-bucket --bucket tfstate

aws --endpoint-url http://localhost:4566 dynamodb create-table \
  --table-name tflock \
  --attribute-definitions AttributeName=LockID,AttributeType=S \
  --key-schema AttributeName=LockID,KeyType=HASH \
  --billing-mode PAY_PER_REQUEST
```

Then run `terraform init` again. This teaches the same workflow you will use
on real AWS, including the "backend resources must exist before init" rule.

---

## Chapter 6 — Using FLOCI in CI

For CI, start the emulator, export its environment, run checks, then tear it
down:

```sh
floci start
eval $(floci env)
terraform fmt -check
terraform validate
terraform plan -out=tfplan
terraform apply -auto-approve tfplan
# run integration tests here
terraform destroy -auto-approve
floci stop
```

Use `FLOCI_STORAGE_MODE=memory` in CI so each run starts clean. Pin the
emulator version so an upstream change does not break your pipeline without
warning.

---

## Chapter 7 — What the emulator cannot teach you

- IAM policies may be partially implemented; verify them on real AWS.
- Quotas, service limits and eventual consistency differ.
- Some advanced APIs are missing or simplified.
- Billing behaviour does not exist locally.
- Container-backed services such as Lambda or RDS need Docker and may pull
  images on first use.

Before a real deployment, run a small smoke test on a real account with a
budget alert and explicit tags. Local success is not production proof.

---

## Chapter 8 — Glossary and next steps

**Backend** — where Terraform stores state.
**Endpoint override** — redirecting an SDK or provider to a different URL.
**Emulation** — behaving like the real service, not just returning canned
answers.
**FLOCI** — local AWS emulator on port 4566.
**Provider** — the Terraform plugin that speaks to an API.
**State** — Terraform's record of what it believes exists.
**Workspace** — a named, separate state file for the same configuration.

Next steps:

1. Add a Makefile or Taskfile with `init`, `plan`, `apply`, `test`, `destroy`.
2. Pin provider and emulator versions.
3. Add a budget alert to your real AWS account before any production lab.
4. Keep local and production configuration separate by variable, never by
   editing files.

---

*End of guide. Corrections welcome via https://github.com/danfreeus702/os-ai-opensense*
