# Troubleshooting & Diagnostics Runbook

---

## 1. Top Common Issues & Solutions

### Issue 1: Android Phone Shows "Network Error / Could Not Connect"
* **Check 1: Phone Wi-Fi**: Is the phone on the same Wi-Fi subnet as the Windows 11 Server?
* **Check 2: Server LAN IP**: Check if the server's LAN IP changed using `.\scripts\status.ps1`.
* **Check 3: Windows Firewall**: Run `.\scripts\setup_server.ps1` as Administrator to ensure ports `8080` and `8085` are open.
* **Check 4: Browser Test**: Open Chrome on the phone and visit `http://<SERVER-LAN-IP>:8080/api/v1/health`.

---

### Issue 2: Realtime Updates Not Syncing Across Devices
* **Check 1**: Run `docker compose ps` and verify `sparetrack-reverb` is `Up`.
* **Check 2**: View Reverb logs using `.\scripts\logs.ps1 -Service reverb`.
* **Check 3**: In `.env`, ensure `REVERB_HOST` matches the server's LAN IP (not `127.0.0.1`).

---

### Issue 3: Database Container Fails to Start
* **Check 1**: Run `.\scripts\logs.ps1 -Service postgres`.
* **Check 2**: Check host disk space using `.\scripts\status.ps1`.

---

### Issue 4: Resetting the Application to a Clean State
If you ever need to completely restart the stack from scratch:

```powershell
docker compose down -v
docker compose up -d --build
docker exec sparetrack-app php artisan migrate:fresh
```
