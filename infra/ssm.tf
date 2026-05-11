# IAM role + instance profile so we can reach the box via AWS Systems
# Manager Session Manager. SSM traffic is plain HTTPS to AWS endpoints,
# which corporate firewalls typically permit — useful when outbound SSH
# (port 22) or non-TLS traffic on 443 is blocked.

data "aws_iam_policy_document" "ec2_trust" {
  statement {
    effect  = "Allow"
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["ec2.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "instance" {
  name               = "lodenica-${var.environment}-ec2"
  assume_role_policy = data.aws_iam_policy_document.ec2_trust.json
}

resource "aws_iam_role_policy_attachment" "ssm_core" {
  role       = aws_iam_role.instance.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

resource "aws_iam_instance_profile" "instance" {
  name = "lodenica-${var.environment}-ec2"
  role = aws_iam_role.instance.name
}
