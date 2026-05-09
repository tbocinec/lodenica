variable "region" {
  description = "AWS region. Frankfurt is closest to Slovakia."
  type        = string
  default     = "eu-central-1"
}

variable "environment" {
  description = "Environment tag (production, staging, …)."
  type        = string
  default     = "production"
}

variable "instance_type" {
  description = "EC2 instance type. t3.micro is free-tier eligible for 12 months."
  type        = string
  default     = "t3.micro"
}

variable "root_volume_gb" {
  description = "Root EBS volume size (gp3). Postgres data lives here too."
  type        = number
  default     = 20
}

variable "ssh_public_key_path" {
  description = "Path to the SSH public key uploaded to the instance."
  type        = string
  default     = "~/.ssh/id_ed25519.pub"
}

variable "allowed_ssh_cidrs" {
  description = "CIDRs allowed to reach SSH (port 22). Tighten to your IP/32."
  type        = list(string)
  default     = ["0.0.0.0/0"]
}

variable "github_owner" {
  description = "GitHub username/org that owns the GHCR images (e.g. `tbocinec`)."
  type        = string
}

variable "github_repo" {
  description = "GitHub repo name (used for cloning compose during user-data)."
  type        = string
  default     = "lodenica"
}
