# Database Backup & Disaster Recovery Guide

---

## 1. Automated Scheduled Backups

To automate daily backups at 02:00 AM on Windows 11 using **Windows Task Scheduler**:

1. Open **Task Scheduler** > **Create Basic Task**.
2. **Name**: `SpareTrack PostgreSQL Daily Backup`.
3. **Trigger**: Daily at `02:00:00 AM`.
4. **Action**: Start a program.
   - **Program/script**: `powershell.exe`
   - **Add arguments**: `-ExecutionPolicy Bypass -File "C:\SpareTrack\scripts\backup.ps1" -RetentionDays 30`
5. Click **Finish**.

---

## 2. Manual Backup Command

From PowerShell inside `C:\SpareTrack`:

```powershell
.\scripts\backup.ps1
```

Generates a timestamped `.sql` file inside `C:\SpareTrack\backups\` (e.g. `sparetrack_db_backup_2026-08-18_02-00-00.sql`).

---

## 3. Restoring from a Backup

To restore the database:

```powershell
.\scripts\restore.ps1
```

The interactive script will:
1. List all available backups in `backups/` with file size and timestamp.
2. Prompt you to select a backup number.
3. Request explicit confirmation (`Type 'RESTORE'`).
4. Execute `psql` to recreate the schema and restore all records safely.
