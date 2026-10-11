<#
.SYNOPSIS
  Build the Special image for linux/amd64 and push it to the ECR repository created by special-stack.yaml.

.EXAMPLE
  ./deploy/aws/push-image.ps1                  # pushes :latest (and :<git short sha> when git is available)
  ./deploy/aws/push-image.ps1 -Tag v1.0.0
#>
param(
    [string]$Region = (aws configure get region),
    [string]$Tag = 'latest',
    [string]$Repository = 'special'
)

$ErrorActionPreference = 'Stop'
if (-not $Region) { throw 'No region: pass -Region or run `aws configure`.' }

$account = aws sts get-caller-identity --query Account --output text
if ($LASTEXITCODE -ne 0) { throw 'AWS credentials are not usable. Run `aws sso login` (or `aws configure`) first.' }

$registry = "$account.dkr.ecr.$Region.amazonaws.com"
$image = "$registry/${Repository}:$Tag"

aws ecr get-login-password --region $Region | docker login --username AWS --password-stdin $registry
if ($LASTEXITCODE -ne 0) { throw 'ECR login failed.' }

$tags = @('-t', $image)
$sha = git rev-parse --short HEAD 2>$null
if ($LASTEXITCODE -eq 0 -and $sha) { $tags += @('-t', "$registry/${Repository}:$sha") }

docker build --platform linux/amd64 @tags .
if ($LASTEXITCODE -ne 0) { throw 'docker build failed.' }

docker push --all-tags "$registry/$Repository"
if ($LASTEXITCODE -ne 0) { throw 'docker push failed.' }

Write-Host "Pushed $image. Deploy it with:  sudo special-deploy $Tag   (on the instance)"
