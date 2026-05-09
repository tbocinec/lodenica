terraform {
  required_version = ">= 1.6"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.70"
    }
  }
}

provider "aws" {
  region = var.region

  default_tags {
    tags = {
      Project     = "lodenica"
      ManagedBy   = "terraform"
      Environment = var.environment
    }
  }
}

# Use the account's default VPC + subnets; avoids networking complexity for a
# single-instance deployment. For multi-AZ / private DBs, build a dedicated VPC.
data "aws_vpc" "default" {
  default = true
}

data "aws_subnets" "default" {
  filter {
    name   = "vpc-id"
    values = [data.aws_vpc.default.id]
  }
}

# Pick the latest Ubuntu 24.04 AMI for the chosen architecture.
data "aws_ami" "ubuntu" {
  most_recent = true
  owners      = ["099720109477"] # Canonical

  filter {
    name   = "name"
    values = ["ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-${local.ami_arch}-server-*"]
  }
  filter {
    name   = "virtualization-type"
    values = ["hvm"]
  }
}

locals {
  ami_arch = startswith(var.instance_type, "t4g.") || startswith(var.instance_type, "c7g.") || startswith(var.instance_type, "m7g.") ? "arm64" : "amd64"
}
