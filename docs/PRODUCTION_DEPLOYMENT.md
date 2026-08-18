# Day-One Production Deployment Runbook

Complete step-by-step procedure to deploy **FAITH AUTOMATION** on a brand-new Windows 11 Desktop server.

---

## Step-by-Step Deployment Procedure

### Step 1: Clone the Repository
Open PowerShell and clone the repository into `C:\SpareTrack`:

```powershell
cd C:\
git clone <YOUR-GIT-REPO-URL> SpareTrack
cd C:\SpareTrack
```

### Step 2: Initialize Server Configuration
Run the setup script with Administrator privileges:

```powershell
Set-ExecutionPolicy RemoteSigned -Scope Process
.\scripts\setup_server.ps1
```

### Step 3: Configure Production `.env`
Open `.env` and verify database passwords, LAN IP, and Reverb keys:

```ini
APP_NAME="FAITH AUTOMATION"
APP_ENV=production
APP_URL=http://<YOUR-SERVER-LAN-IP>:8080
DB_PASSWORD=YourStrongDatabasePasswordHere!
REVERB_HOST=<YOUR-SERVER-LAN-IP>
```

### Step 4: Launch the Full Application Stack
Run the automated deployment script:

```powershell
.\scripts\deploy.ps1
```

This command will:
1. Build frontend assets (`npm run build`).
2. Start all Docker containers (Nginx, Laravel, PostgreSQL 16, Adminer, Redis, Reverb, Worker).
3. Execute all database migrations (`php artisan migrate --force`).
4. Warm system caches.
5. Run health diagnostics.

### Step 5: Verify Access
- **Web Management Dashboard**: `http://<SERVER-LAN-IP>:8080`
- **Adminer Database Console**: `http://<SERVER-LAN-IP>:8088` (System: `PostgreSQL`, Server: `postgres`, Username: `sparetrack_user`, DB: `sparetrack`)
- **API Health Endpoint**: `http://<SERVER-LAN-IP>:8080/api/v1/health`
