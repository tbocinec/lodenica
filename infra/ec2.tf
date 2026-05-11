resource "aws_key_pair" "this" {
  key_name   = "lodenica-${var.environment}"
  public_key = file(pathexpand(var.ssh_public_key_path))
}

resource "aws_security_group" "this" {
  name_prefix = "lodenica-${var.environment}-"
  description = "Lodenica web + ssh"
  vpc_id      = data.aws_vpc.default.id

  ingress {
    description = "SSH"
    from_port   = 22
    to_port     = 22
    protocol    = "tcp"
    cidr_blocks = var.allowed_ssh_cidrs
  }

  ingress {
    description = "HTTP"
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    description = "HTTPS (reserved for Caddy / LetsEncrypt)"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  egress {
    description = "All egress"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  lifecycle {
    create_before_destroy = true
  }
}

resource "aws_instance" "this" {
  ami                  = data.aws_ami.ubuntu.id
  instance_type        = var.instance_type
  key_name             = aws_key_pair.this.key_name
  iam_instance_profile = aws_iam_instance_profile.instance.name

  subnet_id              = data.aws_subnets.default.ids[0]
  vpc_security_group_ids = [aws_security_group.this.id]

  user_data_replace_on_change = true
  user_data = templatefile("${path.module}/user-data.sh", {
    github_owner = var.github_owner
    github_repo  = var.github_repo
  })

  root_block_device {
    volume_type           = "gp3"
    volume_size           = var.root_volume_gb
    delete_on_termination = true
    encrypted             = true
  }

  metadata_options {
    http_tokens   = "required"
    http_endpoint = "enabled"
  }

  tags = {
    Name = "lodenica-${var.environment}"
  }
}

resource "aws_eip" "this" {
  instance = aws_instance.this.id
  domain   = "vpc"
  tags = {
    Name = "lodenica-${var.environment}"
  }
}
