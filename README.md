# PHP CRUD + S3 (OpenShift-ready)

Fitur:
- CRUD sederhana (title, description, image)
- Upload image ke S3/Object Storage (AWS/MinIO/Dell ObjectScale)
- Database PostgreSQL **atau** MySQL (pilih via env var)
- Image container Apache + PHP 8.2 + AWS SDK
- Manifes OpenShift (Deployment/Service/Route/Secrets + contoh DB)

## Build & Push Image (contoh Quay)
```bash
podman build -t quay.io/<org>/php-crud-s3:1.0 .
podman push quay.io/<org>/php-crud-s3:1.0
```

## Deploy di OpenShift (PostgreSQL)
```bash
oc new-project crud-s3-demo || true

# Secrets (DB + S3)
oc apply -f k8s/secrets.example.yaml
# Atau buat manual:
# oc create secret generic db-cred --from-literal=DB_DRIVER=pgsql --from-literal=DB_HOST=postgres --from-literal=DB_PORT=5432 --from-literal=DB_NAME=appdb --from-literal=DB_USER=app --from-literal=DB_PASSWORD=supersecret
# oc create secret generic s3-cred --from-literal=S3_ACCESS_KEY_ID=xxx --from-literal=S3_SECRET_ACCESS_KEY=yyy --from-literal=S3_BUCKET=demo-bucket --from-literal=S3_REGION=us-east-1 --from-literal=S3_ENDPOINT=https://objectscale.example.com --from-literal=S3_PATH_STYLE=true

# DB Postgres
oc apply -f k8s/postgres.yaml
oc rollout status deploy/postgres

# App (ganti image registry sesuai milik Anda)
# Edit k8s/app.yaml -> REGISTRY/ORG/php-crud-s3:1.0
oc apply -f k8s/app.yaml
oc rollout status deploy/php-crud-s3

oc get route php-crud-s3 -o jsonpath='{.spec.host}'; echo
```

## Switch ke MySQL
```bash
oc apply -f k8s/mysql.yaml
oc delete secret db-cred
oc create secret generic db-cred       --from-literal=DB_DRIVER=mysql       --from-literal=DB_HOST=mysql       --from-literal=DB_PORT=3306       --from-literal=DB_NAME=appdb       --from-literal=DB_USER=app       --from-literal=DB_PASSWORD=supersecret

oc rollout restart deploy/php-crud-s3
```

## Catatan
- Untuk endpoint S3 non-AWS (MinIO/ObjectScale), set `S3_ENDPOINT` dan `S3_PATH_STYLE=true`.
- Healthcheck: `/index.php?action=health`
- App otomatis membuat tabel `items` saat start.
