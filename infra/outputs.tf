output "ec2_public_ip" {
  description = "Elastic IP attached to the instance."
  value       = aws_eip.this.public_ip
}

output "url" {
  description = "Public HTTP URL for the app."
  value       = "http://${aws_eip.this.public_ip}"
}

output "ssh" {
  description = "SSH command to reach the instance."
  value       = "ssh ubuntu@${aws_eip.this.public_ip}"
}

output "next_steps" {
  description = "What to do after `apply`."
  value       = <<-EOT
    1. Wait ~2 min for cloud-init to finish installing Docker.
    2. SCP your prod env file:
         scp .env.prod ubuntu@${aws_eip.this.public_ip}:/opt/lodenica/.env
    3. Trigger GitHub Actions → Deploy → Run workflow (tag: latest)
       OR manually:
         ssh ubuntu@${aws_eip.this.public_ip}
         cd /opt/lodenica && docker compose -f docker-compose.prod.yml --env-file .env up -d
  EOT
}
